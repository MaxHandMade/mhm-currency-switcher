# MHM Currency Switcher — Cache-Friendly Switcher Tasarımı (v1.1)

**Tarih:** 2026-07-26
**Revizyon:** v5 — Fable bağımsız spec-denetimi tur 1 / 2 / 3 / 4 (dördü de NO-GO) sonrası + 6 sahip kararı işlendi
**Durum:** Revize edildi, Fable tur 5 doğrulaması bekliyor
**Hedef sürüm:** v1.1.0

> **Denetim geçmişi.**
> **Tur 1 (NO-GO):** `FormatFilter` tasarımda hiç yoktu → "base tutar + dönüşmüş sembol" cache'lenirdi (B1); variation transient hash'i bağlam-körüydü (B2); bağlam listesi eksik + `is_cart()` zamanlaması güvenilmezdi (B3); geolocation/`?currency=` cache modunda ölüyordu (B4).
> **Tur 2 (NO-GO):** memoize edilen karar pre-`wp` "dönüştür" cevabını render'a sızdırıp kök bug'ı geri getiriyordu (YB-1); `wc-ajax` **allowlist**'i ödeme ağ geçitlerini sayamadığı için express checkout base tahsil ederdi (YB-2); JS tespit sırası sunucunun tersiydi (YB-3); admin-ajax sipariş kalemi dönüşmüş fiyatı kalıcı yazıyordu (YB-4); + H1–H6, M1–M5.
> **Tur 3 (NO-GO):** REST dalı login dalının arkasına konduğu için **hiç çalışmıyordu** — wc/v3 daima kimlik doğrulamalıdır (NB-1); `CartFilter::recalculate_fees` "tek çözücü" setinin dışında kalmıştı (H-1); memoization kuralı post-`wp` erken-kilitlenmeyi kapatmıyordu (H-2); karar 1'in referer sezgisinin başarısızlık yönü YB-4'ü geri açıyordu (H-3); + M-1..M-4, drift.
>
> **Tur 4 (NO-GO, dar):** karar tablosunun yeni sırasında para yolunu bozan hata **bulunamadı**; NO-GO iki şeydendi — ölü-kontrol envanteri gerçekte 4 değil **14** üyeliydi ve ikisi `readme.txt`'de var olmayan özellik olarak duyuruluyordu (YB4-1); karar 2'nin "REST isteği" ilkeli tanımsızdı ve yanlış ilkel seçimi WC Blocks preload'unu bozuyordu (YB4-2). Artı M-1 (`?wc-ajax=` boş değer zehirleme vektörü), M-2 (mandalın sessiz tam gerilemesi), M-3 (YB-3'ün test kilidinin hiç yazılmamış olması).
>
> Bu revizyon hepsini kapatır. **Mimari iskelet dört turda da sağlam çıktı** — tur 3'ten beri düzeltmeler karar tablosunun sırası, bir dal koşulunun pinlenmesi ve envanter doğruluğuyla sınırlı.

---

## 1. Problem

Fiyatlar şu an **server-side** dönüşüyor: `PriceFilter` ([PriceFilter.php:64-82](../../../src/Integration/WooCommerce/PriceFilter.php)) `woocommerce_product_get_price` ailesine priority 100'de bağlanıp tutarı çevirir; `FormatFilter` ([FormatFilter.php:68-79](../../../src/Integration/WooCommerce/FormatFilter.php)) sembolü, para kodunu ve ayraçları çevirir. WC'nin `price_html`'i ikisinin bileşiminden doğar. Sayfa cache'lenince bu çıktı statik HTML'e gömülür → **ilk ziyaretçinin para birimi cache'lenir, ikinci ziyaretçi onu görür.**

Pazar araştırması: caching çakışması currency-switcher eklentilerinin **#1 kronik şikâyeti**; "önbellek dostu" en güçlü pazarlama kozu.

Ek olarak switcher `window.location.reload()` yapıyor ([switcher.js:62](../../../assets/js/switcher.js)) — yavaş, üstelik cache'li sayfayı yeniden servis ettiği için çözüm değil.

## 2. Yaklaşım (onaylanmış kararlar)

1. **İstemci-taraflı AJAX dönüşüm.** Sayfa **temel para biriminde** cache'lenir; JS görünüm fiyatlarını dönüştürür.
2. **Dönüşüm sunucuda (REST).** JS ürün ID'lerini toplu gönderir; sunucu dönüştürülmüş+biçimlenmiş `price_html` döner. Tek doğruluk kaynağı; JS'te format/yuvarlama tekrarı YOK.
3. **Yüzey ayrımı:** görünüm = temel+marker+JS · sepet/ödeme/sipariş totalleri = server-side.
4. **Toggle `cache_compat`, varsayılan AÇIK.**
5. **Tek paylaşılan bağlam çözücü** — fiyat/format/ücret/kupon/kargo yüzeylerinin **tamamı** aynı cevabı kullanır (tur 1 / B1, tur 3 / H-1).
6. **Login'li kullanıcıda server-side'a düş** — marker/JS üretilmez.
7. **Tespit zinciri JS'e taşınır** (tur 1 / B4).
8. **Para bağlamında allowlist YOK, varsayılan "dönüştür"** (tur 2 / YB-2).
9. **🆕 Bug-fix dalları toggle'ın ÜSTÜNDE.** Admin, REST ve cron/CLI dalları `cache_compat`'tan bağımsız çalışır — bunlar cache özelliğinin parçası değil, §8'deki üretim bug'larının fix'i (tur 3 / NB-1).

---

## 3. `ConversionContext` — tek bağlam çözücü

**Yeni:** `src/Core/ConversionContext.php`. Tek public sorusu: `should_convert(): bool`. `PriceFilter`, `FormatFilter`, `CouponFilter`, `ShippingFilter`, `CartFilter::recalculate_fees` ve variation-hash bu tek cevabı paylaşır.

### 3.1 Karar sırası (ilk eşleşen kazanır)

| # | Koşul | Karar | Gerekçe |
|---|---|---|---|
| 0 | `force_convert()` aktif | **Dönüştür** | Kendi convert endpoint'imiz. En üstte olduğu için alttaki REST dalı onu yanlışlıkla yakalayamaz (tur 3 / L-1: v3 bunu bir "istisna" olarak yazıyordu, dal olarak temiz). |
| 1 | **Admin bağlamı**: `is_admin()` **ve** (`! wp_doing_ajax()` **veya** referer belirsiz **veya** referer `admin_url()` ile başlıyor) | **Dönüştürme** | §8.1. Referer **yoksa da admin sayılır** — tur 3 / H-3: v3 "referer yoksa frontend" diyordu, o durumda admin login'li olduğu için alttaki login dalı yakalayıp bug'ı geri açıyordu (`Referrer-Policy: no-referrer` basan güvenlik eklentilerinde yaygın). WC çekirdeğinin frontend para işlemleri `admin-ajax.php`'ye binmez; 3. parti bir istisna çıkarsa `mhmcs_should_convert` filtresi kaçış kapısıdır. `wc-ajax` bu dala **girmez** (o `template_redirect` üzerinden koşar, `is_admin()` false). |
| 2 | **REST isteği**, Store API **hariç** (ilkel: §3.2'nin **aynısı**) | **Dönüştürme** | wc/v3 ürün okumaları. §8.4'ün fix'i. **Login dalından ÖNCE olmak zorunda** — tur 3 / NB-1: wc/v3 daima kimlik doğrulamalıdır, v3'te bu dal login dalının arkasındaydı ve **hiç çalışmıyordu.** İlkel §3.2'de pinlenmiştir; serbest bırakılırsa tur 4 / YB4-2'deki WC Blocks preload hatası doğar. |
| 3 | `wp_doing_cron()` veya `defined('WP_CLI')` | **Dönüştürme** | tur 3 / M-4. Pratikte cookie yok → zaten no-op; ama `WC_Geolocation` sunucu IP'sini bir ülkeye çözerse cron'daki fiyat okumaları dönüşürdü. Tesadüfe bırakılmaz. |
| 4 | `cache_compat` KAPALI | **Dönüştür** | v1.0.0 görünüm davranışı. Dikkat: 1-2-3 bunun **üstünde** → §3.4. |
| 5 | **Para bağlamı** (§3.2, Store API dahil) | **Dönüştür** | Müşterinin ödeyeceği tutar. |
| 6 | `is_user_logged_in()` | **Dönüştür** | Karar 6: server-side yol, marker yok. |
| 7 | `! did_action( 'wp' )` | **Dönüştür** | Erken okumalar (`wp_loaded` sepet-session doğrulaması); conditional tag'ler henüz kurulmamıştır. **Bu cevap memoize EDİLMEZ** — §3.3. |
| 8 | aksi hâlde | **Dönüştürme (base)** | Katalog/ürün/arşiv görünümü. |

Kaçış kapısı: `apply_filters( 'mhmcs_should_convert', bool $decision, string $reason )`.

### 3.2 Para bağlamı (karar 5)

- `defined( 'WOOCOMMERCE_CHECKOUT' )` veya `defined( 'WOOCOMMERCE_CART' )`
- **`wc-ajax` parametresi BOŞ DEĞİLSE — değeri ne olursa olsun → dönüştür.**
  Tur 2 / YB-2: allowlist yanlış yöndü. Stripe/PayPal express checkout kendi endpoint'lerini kaydeder (`wc-ajax=wc_stripe_*`, `ppc-create-order`); sonlu liste onları sayamaz → ürün sayfasındaki express ödeme butonu **base tutarla tahsilat yapardı**.
  🔴 **`isset` DEĞİL `! empty` (tur 4 / M-1).** `WC_AJAX::do_wc_ajax()` yalnız **boş olmayan** değerde nocache header basıp `wp_die()` eder; `sayfa/?wc-ajax=` (boş değer) WC tarafından **normal sayfa olarak render edilir**. `isset` ile kural bu URL'de "dönüştür" derdi → tam sayfa, marker'sız, dönüşmüş render; query-string'i cache anahtarından düşüren edge/CDN konfigürasyonunda **crafted link ile cache zehirleme vektörü**. Dolayısıyla "cache maliyeti sıfır" iddiası **yalnız boş olmayan değerler için** geçerlidir.
  Okuma: `sanitize_key( wp_unslash( $_GET['wc-ajax'] ) )` + gerekçeli `WordPress.Security.NonceVerification.Recommended` ignore (gerekçe koda yazılır).
- **Store API:** `WC()->is_rest_api_request()` **ve** route `/wc/store` ile başlıyor.
  ⚠ `WC()->cart` varlığı ayırt edici **değildir**.
  🔴 **İlkel pinlenmiştir (tur 4 / YB4-2).** Hem bu test hem karar 2'nin "REST isteği" testi **aynı, REQUEST_URI-tabanlı** `WC()->is_rest_api_request()` ilkelini kullanır. Sayfa render'ı sırasında WC Blocks'un `rest_preload_api_request()` → `rest_do_request()` ile **içeriden** dispatch ettiği Store API çağrıları **REST sayılmaz** (REQUEST_URI o an sayfa URL'idir). Dispatch-farkındalıklı bir ilkel (`wp_is_rest_endpoint()`, `$wp_rest_server->is_dispatching()`) seçilirse preload "REST ama route Store API değil" diye **karar 2'ye düşer** → Cart/Checkout/Mini-Cart block'unun hydrate verisi base basılır, tarayıcının sonraki Store API çağrıları dönüşür → block sayfalarında base↔dönüşmüş tutarsızlığı. NB-1 tam bu dalın yanlış gerçeklenmesiydi; ilkel implementer'ın seçimine bırakılamaz.
- Conditional tag'ler kurulmuşsa: `is_cart() || is_checkout() || is_account_page()`

### 3.3 🔴 Memoization kuralı (tur 2 / YB-1 + tur 3 / H-2)

İki ayrı sızıntı yolu var, ikisi de kapatılır:

1. **Pre-`wp` cevabı hiç önbelleklenmez.** v2 "istek başına bir kez hesapla" diyordu; `wp_loaded` sepet-session doğrulaması karar 7'den "dönüştür" alıp memoize ediyor, aynı istekte template render o cevabı kullanıyor, sayfa dönüşmüş cache'leniyordu → **sepeti olan her ziyaretçi için kök bug geri geliyordu.** Okuma HTML'e gitmiyordu ama **memoize edilen karar gidiyordu.**
2. **Post-`wp`'de yalnız "dönüştür" cevabı önbelleklenir; "base" cevabı her çağrıda yeniden hesaplanır.** v3 yalnız fazı ayırıyordu, bu yetmiyordu: `WOOCOMMERCE_CART`/`WOOCOMMERCE_CHECKOUT` sabitleri **render ortasında shortcode tarafından** tanımlanır. Cart/checkout shortcode'u WC'nin atadığı sayfa dışında bir sayfadaysa, sayfadaki ilk fiyat okuması (header mini-cart, üstteki ürün grid'i) karar 8'den "base" alıp kilitleniyor; sonra shortcode sabiti tanımlasa bile kilitli cevap kullanılıyordu → sepet tablosu base basılır (kalem fiyatı `wc_price`'tan gelir, marker'ı yoktur, JS düzeltemez), ama `wc-ajax=checkout` dönüşmüş tahsil eder → **"base göster, dönüşmüş öde".**

Kural tek cümlede: **karar tek yönlü mandaldır** — "dönüştür"e geçtikten sonra istek boyunca orada kalır (güvenli taraf); "base" cevabı hiçbir zaman kilitlenmez. Kontroller ucuz olduğu için **CPU** maliyeti ihmal edilebilir.

🔴 **Mandalın en kötü durumu (tur 4 / M-2) — dürüstçe:** mandal tek yönlü olduğu için erken ve yersiz bir "dönüştür" tüm sayfayı server-side dönüşmüş render ettirir. Bilinen tetikleyici: bazı temalar/eklentiler header'daki mini-cart toplamı için `WOOCOMMERCE_CART`'ı **site genelinde** tanımlar → karar 5 her sayfada tetiklenir → **cache modu sessizce tamamen devre dışı kalır ve kök bug geri gelir.** Bu ne hata verir ne uyarır. Karşı önlem: eklenti, `cache_compat` AÇIK'ken sayfa render'ının sonunda mandalın "dönüştür"e düşüp düşmediğini tespit eder ve admin'e **tanılama uyarısı** basar ("önbellek uyumluluğu bu sayfada devre dışı kaldı; muhtemel sebep: tema/eklenti sepet sabitini erken tanımlıyor"). §10'da readme sınırı olarak da yazılır.

**Ayrıca (tur 3 / L-2):** `DetectionService::get_current_currency()` bugün istek-içi memoize **değil** ve `setcookie` `$_COOKIE`'yi güncellemediği için geolocation aynı istekte her okumada yeniden koşabiliyor ([DetectionService.php:224-250](../../../src/Core/DetectionService.php)). Plan buraya da ucuz bir memo koyar.

### 3.4 `cache_compat` KAPALI'nın anlamı

Karar 1-2-3 toggle'ın **üstünde** olduğu için mod KAPALI'yken de admin/REST/cron davranışı değişir (§8'in bilinçli bug-fix'leri). Doğru ifade: *"mod KAPALI = v1.0.0'ın görünüm davranışı, artı admin/REST/cron bug-fix'leri."* readme ve ayar açıklaması bunu böyle söyler; "birebir eski davranış" **denmez**.

### 3.5 Kapsam dışı bırakılan dönüşüm yüzeyleri (gerekçeli)

- `CartFilter::save_order_meta` ([CartFilter.php:115-122](../../../src/Integration/WooCommerce/CartFilter.php)) detection-tabanlı kalır — **fiilen tahsil edilen** parayı siparişe yazar, bağlam sorusu sorulmaz.
- `OrderFilter`'ın `woocommerce_currency` priority-200 override'ı ([OrderFilter.php:83](../../../src/Integration/WooCommerce/OrderFilter.php)) order-meta güdümlüdür (geçmiş siparişi kaydedildiği parada gösterir), ziyaretçi bağlamına bakmaz → kapsam dışı.

### 3.6 Hata modu sıralaması

- **Yanlış tahsilat (en tehlikeli):** Store API tespiti kaçarsa blocks-checkout base tutarla sipariş keser. Klasik checkout `WOOCOMMERCE_CHECKOUT` sabitiyle, ağ geçidi wc-ajax'ları §3.2'nin "hepsi dönüşür" kuralıyla güvende.
- **Yanlış gösterim + session tutarsızlığı:** `CouponFilter::convert_coupon_amount` ([CouponFilter.php:90-104](../../../src/Integration/WooCommerce/CouponFilter.php)) ve `CartFilter::recalculate_fees` ([CartFilter.php:91-102](../../../src/Integration/WooCommerce/CartFilter.php)) bugün bağlama değil yalnız detection'a bakıyor → dönüşmüş kupon/ücret, base bırakılmış kalemlerden düşülürdü.
- **Cache kırılması (en zararsız):** fazladan dönüşüm.

**İlke:** belirsizlikte **dönüştür** tarafına yanıl. (Tek istisna karar 1'in referer-belirsiz dalı — gerekçesi tabloda.)

## 4. Bileşenler

| Bileşen | Tip | Sorumluluk |
|---|---|---|
| `src/Core/ConversionContext.php` | yeni | §3'teki tek karar. Enjekte edilebilir seam. |
| `PriceFilter.php` | değişir | `convert_price`/`convert_sale_price`/`convert_variation_price` → context'e sorar. |
| `FormatFilter.php` | değişir | 6 format filtresi aynı kararı kullanır. Kayıt-zamanı `is_admin()` guard'ı ([FormatFilter.php:69-71](../../../src/Integration/WooCommerce/FormatFilter.php)) kaldırılıp karar 1'e devredilir. |
| `PriceFilter::add_currency_to_hash` | değişir | Variation transient hash'i bağlamı kodlar: dönüştürmüyorsak `<base>:display`, dönüştürüyorsak hedef kod. Aksi hâlde display bağlamı base değerleri "EUR" hash'ine yazar, endpoint aynı hash'ten base okur → variable ürünler kalıcı no-op (tur 1 / B2). |
| `CouponFilter.php`, `ShippingFilter.php` | değişir | Aynı karara bağlanır. |
| **`CartFilter::recalculate_fees`** | **değişir (tur 3 / H-1)** | Bugün doğrudan detection ile çeviriyor ([CartFilter.php:91-102](../../../src/Integration/WooCommerce/CartFilter.php)); context'e bağlanır. v3 "tek çözücü" ilkesini kurup bu üyeyi dışarıda bırakmıştı. |
| `src/Frontend/PriceDisplayMarker.php` | yeni | Dönüştürmediğimiz bağlamda `woocommerce_get_price_html` çıktısını `<span class="mhmcs-price" data-mhmcs-product="ID">…base price_html…</span>` ile sarar. |
| `src/Rest/ConvertController.php` | yeni | `POST mhmcs/v1/convert`. Güvenlik: §6. |
| `assets/js/price-converter.js` | yeni | Marker toplama → 50'lik batch REST → HTML yazma. Tespit: §5. |
| `assets/js/switcher.js` | değişir | `reload()` yerine cookie set + `mhmcs:currency-changed`. **Load'ta** aktif-para göstergesini cookie'den senkronlar. Cookie öznitelikleri §5.3. |
| `Switcher.php`, `Elementor/SwitcherWidget.php`, `NavMenu` | değişir | Cache modunda **nötr** render — `data-current` ([Switcher.php:109](../../../src/Frontend/Switcher.php)) ve `mhm-cs-active` ([:122](../../../src/Frontend/Switcher.php)) ön-seçili basılmaz. |
| `DetectionService.php` | değişir | `set_request_override( string $code )` (istek-kapsamlı, cookie yazmaz; aktifken geolocation koşmaz) + `sanitize_currency_code()` paylaşılabilir + istek-içi memo (§3.3). **Ayrıca (tur 4 / L-1):** convert endpoint'inin `currency:null` yolunda geolocation başarılı olursa `detect_from_geolocation()` bugün server-side `setcookie` tetikliyor ([DetectionService.php:247](../../../src/Core/DetectionService.php)) → endpoint bağlamında bu yazım **bastırılır**, yoksa aynı cookie hem PHP hem JS tarafından yazılır ve §5.3'ün "cookie'yi JS yazar" sözleşmesi delinir. |
| `Enqueue.php` | değişir | **Bugün hiçbir localize yok** ([Enqueue.php:67](../../../src/Frontend/Enqueue.php)). Eklenecek: REST URL, base currency, `cache_compat`, `auto_detect`, kod→sembol/bayrak haritası. |
| Ayarlar (`RestAPI` sanitizer + `AdvancedSettings.jsx`) | değişir | `cache_compat` gerçek ayar (varsayılan `true`) + §8.2'nin 4 ölü kontrolü kaldırılır. |

**Kapsam dışı — `ProductWidget` ve Elementor `PriceDisplayWidget`:** [ProductWidget.php:152-172](../../../src/Frontend/ProductWidget.php) **ziyaretçiden bağımsız çoklu-para listesi** basıyor ve ham `_price` meta okuyor ([:189](../../../src/Frontend/ProductWidget.php)) → zaten cache-safe; marker+`innerHTML` replace onu **bozar**. Elementor'ın `PriceDisplayWidget`'ı ([PriceDisplayWidget.php:119](../../../src/Integration/Elementor/PriceDisplayWidget.php)) doğrudan `ProductWidget::render_shortcode()`'a delege ettiği için **aynı çıktıdır**, o da kapsam dışıdır. Elementor **Pro**'nun kendi Product Price widget'ı `get_price_html` kullandığı için marker'ı otomatik alır.

## 5. Veri akışı + tespit zinciri

### 5.1 Akış

```
[Cache'li sayfa: base HTML + <span data-mhmcs-product=42>$10.00</span>]
   ↓ DOMContentLoaded
price-converter.js tespit sırası (SUNUCUYLA AYNI — DetectionService.php:154-174):
   1. mhmcs_currency cookie
   2. ?currency= URL param  → cookie'ye YAZMA (parite: detect_from_url_param cookie yazmıyor)
   3. ikisi de yok + auto_detect açık → currency:null gönder, sunucu geolocation ile çözsün
   ↓ marker'ları topla → uniq ID'ler → 50'lik parçalara böl
   ↓ POST mhmcs/v1/convert { currency: "EUR" | null, product_ids: [...] }
ConvertController: force_convert() + set_request_override(çözülen para)
   → her ID için $product->get_price_html()
   ↓ { currency: "EUR", detected: true|false, prices: { 42: "…€9,20…", … } }
price-converter.js: HTML yaz, opacity 0→1
   → currency:null göndermiştik VE detected:true ise cookie'ye yaz (detected:false → YAZMA)
   ↓
[Switcher değişimi] → cookie set → mhmcs:currency-changed
   → converter yeniden koşar + fragment storage temizlenir + wc_fragment_refresh (§5.4)
[Sepet/ödeme] → ConversionContext "dönüştür" → gerçek tutar server-side (uncached)
```

### 5.2 Zincir sırası sunucuyla aynı (tur 2 / YB-3)

v2 JS sırasını `?currency=` → cookie diye yazmıştı; sunucu cookie → URL param → geolocation ([DetectionService.php:154-171](../../../src/Core/DetectionService.php)). EUR cookie'li ziyaretçi `?currency=USD` linkine tıklarsa JS katalogda USD basar, sepet server-side EUR kullanır → **müşteri USD görür, EUR öder.** Zincir **cookie-first** olmak zorunda.

Cache MISS tarafı temiz: karar 8'de sunucu zaten base basar, query-var yolu fiyatı etkilemez → çifte dönüşüm yok.

### 5.3 Cookie öznitelik sözleşmesi (tur 2 / M5)

PHP bugün: `expires` 30 gün · `path /` · `secure is_ssl()` · `httponly false` · `samesite Lax` ([DetectionService.php:185-199](../../../src/Core/DetectionService.php)). Mevcut `switcher.js` `max-age` 30g + `path=/` + `SameSite=Lax` yazıyor ama **`Secure` yok** ([switcher.js:53-57](../../../assets/js/switcher.js)). Cookie'yi artık JS yazdığı için öznitelikler PHP ile **birebir** hizalanır, yoksa iki mod arasında kalıcılık sessizce bozulur.

### 5.4 Geolocation ve fragments

- **Geolocation başarısızlık sözleşmesi (tur 2 / H2):** CF-IPCountry yok + MaxMind DB kurulu değil (yaygın) → endpoint base döner, yanıt `detected: false` taşır ve JS **cookie yazmaz**. Aksi hâlde base cookie yazılır, zincir 1. adımda takılır, geolocation bir daha hiç denenmez. Sunucunun bugünkü davranışı da başarısızlıkta cookie yazmamak ([DetectionService.php:241-249](../../../src/Core/DetectionService.php)).
- **Mini-cart fragments invalidation (tur 2 / H5):** WC fragment'ları `sessionStorage`'da cart-hash anahtarıyla saklar; para değişimi cart-hash'i değiştirmez → switcher'dan sonra mini-cart **eski para biriminde** kalır. `mhmcs:currency-changed`'de fragment storage temizlenir + `wc_fragment_refresh` tetiklenir.

### 5.5 Varyasyonlar (tur 2 / H6 — sahip kararı: AJAX yolunu zorla)

Cache'li sayfada `data-product_variations` attribute'u **base** `price_html` JSON'u taşır ve WC'nin `variation.js`'i seçimde onu (marker'sız) DOM'a yazar. Çözüm: cache modunda `woocommerce_ajax_variation_threshold` filtresiyle **AJAX varyasyon yolu zorlanır** → fiyat `wc-ajax=get_variation`'dan gelir, o da §3.2 kuralıyla dönüşür. Tek yol kalır, format/yuvarlama sunucuda kalır (JS'e taşınmaz).

**Kabul edilen bedel (tur 3 / M-3):** varyasyon seçiminde bir ek istek, **ve** `data-product_variations` `false` basıldığı için o JSON'u okuyan 3. parti renk/beden swatch eklentileri fiyat gösterimini kaybedebilir. readme'ye yazılır (§10). `reset_data` marker'ın base içeriğine döner, tutarlı.

## 6. `POST mhmcs/v1/convert` güvenliği

- `register_rest_route` **`args` şeması** (`validate_callback`/`sanitize_callback`) — mevcut konvansiyon elle sanitize ([RestAPI.php:96-165](../../../src/Admin/RestAPI.php)), reviewer'lar şemayı tercih eder.
- `product_ids`: istek başına en fazla **50**; JS 50'lik parçalara böler. Aşan → `400`.
- Her ID `absint`; post type **`product` veya `product_variation`** (tur 2 / H4).
  - Ürün: `publish` + `post_password_required()` false.
  - Varyasyon: **kendi statüsü `publish`** (tur 3 / M-1: WC devre dışı bırakılan varyasyona `private` verir — parent'a bakmak yetmez) **ve** parent `publish` + parent şifresiz.
- `currency`: strict `^[A-Z]{3}$` + enabled-currencies allowlist; geçersizse base (no-op). `null` = "tespit et".
- Yanıt: `prices` + çözülen `currency` + `detected` — sayfada zaten public olan veri.
- **`Cache-Control: no-store`**.
- **Nonce yok, `permission_callback => '__return_true'`** — public read-only için WP.org'ca kabul edilir; repoda emsali var ([RestAPI.php:155-164](../../../src/Admin/RestAPI.php) `/rates`). WP.org'un gerçek şartı: callback'in açıkça set edilmesi + girdi doğrulama + veri sızıntısı olmaması. Login'li kullanıcı bu yolu hiç kullanmıyor (karar 6) → "misafir bağlamı" kusur değil tasarım.
- `price_html` sunucu-üretimi WC HTML'i; sembol girişte sanitize ediliyor ([RestAPI.php:486](../../../src/Admin/RestAPI.php)) → `innerHTML` kabul edilebilir.

## 7. Kapsam

**İçinde:** katalog/mağaza/arşiv/ürün/ilgili-ürünler/öne-çıkanlar görünüm fiyatları · Elementor **Pro** Product Price widget · switcher no-reload + load-time UI senkronu · `cache_compat` toggle · basit/indirimli/aralık/varyasyon `price_html` şekilleri · varyasyon seçimi (§5.5) · §8'deki 4 mevcut bug + **§8.2'nin 14 üyeli ölü-kontrol envanterinin tasfiyesi** (kaldır veya implemente et) + readme düzeltmesi.

**Dışında (bilinçli):** sepet/ödeme/sipariş totalleri (server-side) · `ProductWidget` + Elementor `PriceDisplayWidget` (§4) · `save_order_meta` + `OrderFilter` (§3.5) · login'li kullanıcı · Cloudflare edge özel config · kişiselleştirilmiş/rol-bazlı fiyat.

**Mini-cart:** her sayfada render edildiği için istek-bazlı sınıflandırılamaz. Cache modunda base render edilir; doğruluğu `get_refreshed_fragments`'a yaslanır (§3.2 ile dönüşür) + §5.4 invalidation. **Fragments kapalıysa** cache'lenmiş mini-cart base kalır → tespit edilip **admin uyarısı** basılır + readme'de belgelenir.

## 8. Bugün v1.0.0'da CANLI olan buglar ve ölü kontroller (v1.1 kapsamında)

1. **Admin'de fiyat dönüşümü.** `PriceFilter::init()` koşulsuz kayıtlı ([Plugin.php:117-118](../../../src/Plugin.php)); `FormatFilter`'ın aksine `is_admin()` guard'ı yok. **En zararlı yüzey admin-ajax:** sipariş ekranında "ürün ekle" `admin-ajax.php` üzerinden koşar, WC ürünü `get_price()` (view context, filtreli — [PriceFilter.php:66](../../../src/Integration/WooCommerce/PriceFilter.php)) ile okur → **dönüşmüş fiyat sipariş kalemine kalıcı yazılır.** Ekran görüntüsü değil, veri. Admin-ajax'ta `FormatFilter`'ın kendi guard'ı ([FormatFilter.php:69](../../../src/Integration/WooCommerce/FormatFilter.php)) da geçirdiği için sembol de dönüşür (tur 3 drift: v3 "sembol base kalır" diyordu, bu yalnız admin *sayfa* yüklemesi için doğruydu). Karar 1 kapatır. Admin *sayfa* yüklemeleri zaten güvendeydi: WC edit ekranları `'edit'` context ile okur.
2. **🔴 Ölü kontroller — gerçek envanter 14 üye.** Bu sınıfı **üç kez üst üste eksik saydım** (v3: 2 · v4: 4 · gerçek: 14). Sayım artık tahminle değil, üç kümenin kesişimiyle yapıldı: **(a)** sanitizer whitelist'i ([RestAPI.php:207-255](../../../src/Admin/RestAPI.php) — whitelist dışı her anahtar **sessizce düşer**), **(b)** React'ın yazdığı anahtarlar, **(c)** `src/` içinde okunan anahtarlar.

   | # | Kontrol | Durum | Karar |
   |---|---|---|---|
   | 1 | `provider` (+ "Open Exchange Rates / CurrencyLayer" seçenekleri, [AdvancedSettings.jsx:138-157](../../../admin-app/src/components/tabs/AdvancedSettings.jsx)) | Kaydediliyor ([RestAPI.php:210-211](../../../src/Admin/RestAPI.php), değer whitelist'i yok — yalnız `sanitize_text_field`), okuyan **yok** | **Kaldır** |
   | 2 | `provider_api_key` ([AdvancedSettings.jsx:159-173](../../../admin-app/src/components/tabs/AdvancedSettings.jsx)) | Sanitizer'da yok → kaydedilmiyor bile | **Kaldır** |
   | 3 | `cache_duration` ([RestAPI.php:214-216](../../../src/Admin/RestAPI.php)) | Kaydediliyor, okuyan yok | **Kaldır** |
   | 4 | `round_prices` ([RestAPI.php:222-224](../../../src/Admin/RestAPI.php)) | Kaydediliyor, **UI'sı bile yok**, okuyan yok | **Kaldır** |
   | 5 | `multilingual_mapping` ([AdvancedSettings.jsx:28-38](../../../admin-app/src/components/tabs/AdvancedSettings.jsx)) | Kaydedilmiyor, okuyan yok · **`readme.txt:94`'te özellik olarak duyuruluyor** | **Kaldır + readme düzelt** |
   | 6 | `payment_restrictions` — Checkout sekmesinin **tamamı** ([CheckoutOptions.jsx:26-48](../../../admin-app/src/components/tabs/CheckoutOptions.jsx)) | Kaydedilmiyor; `woocommerce_available_payment_gateways` filtresi **repoda hiç yok** → özellik mevcut değil · **`readme.txt:93`'te özellik olarak duyuruluyor** · [Settings.php:117](../../../src/Admin/Settings.php) sekmeye gateway listesi besliyor | **Sekmeyi kaldır + readme düzelt** |
   | 7 | `currency.payment_methods` ([RestAPI.php:542-546](../../../src/Admin/RestAPI.php)) | Sanitize ediliyor ama ne UI'sı ne tüketicisi var (#6'nın akrabası) | **Kaldır** |
   | 8-12 | Switcher görünüm grubu: `show_flag`, `show_name`, `show_symbol`, `show_code`, `size` ([DisplayOptions.jsx:51-122](../../../admin-app/src/components/tabs/DisplayOptions.jsx)) | Kaydedilmiyor; `Switcher.php` **hiçbirini okumuyor** — bayrak+sembol+kod koşulsuz basılır ([Switcher.php:113-126](../../../src/Frontend/Switcher.php)), isim **hiç** basılmaz, `size` yalnız shortcode att'ı ([:84-86](../../../src/Frontend/Switcher.php)). ⚠ Admin'deki **Canlı Önizleme** ([DisplayOptions.jsx:180-223](../../../admin-app/src/components/tabs/DisplayOptions.jsx)) kontrole tepki verdiği için "çalışıyor" görünüyor — sınıfın en aldatıcı biçimi | **İMPLEMENTE ET** (sahip kararı) |
   | 13 | `product_widget.show_flags` | **Üç katmanlı ölü:** JSX `show_flag` (tekil) yazıyor ([DisplayOptions.jsx:168-171](../../../admin-app/src/components/tabs/DisplayOptions.jsx)), sanitizer `show_flags` (çoğul) bekliyor ([RestAPI.php:239-241](../../../src/Admin/RestAPI.php)) → anahtar uyuşmuyor; üstelik `ProductWidget` ikisini de okumuyor, bayrağı koşulsuz basıyor ([ProductWidget.php:158-159](../../../src/Frontend/ProductWidget.php)) | **İmplemente et** (#8-12 kararıyla tutarlı; anahtar adı tek kaynağa çekilir) |
   | 14 | Elementor `PriceDisplayWidget` "Show Flags" ([PriceDisplayWidget.php:92-102](../../../src/Integration/Elementor/PriceDisplayWidget.php)) | `render()` yalnız `currencies` geçiriyor ([:119-123](../../../src/Integration/Elementor/PriceDisplayWidget.php)) → kontrol hiçbir şey yapmıyor | **İmplemente et** (#13'e bağlanır) |

   `RateProvider` sabit ExchangeRate→Fawaz zinciri ve sabit `TRANSIENT_EXPIRY = 86400` kullanıyor ([src/Core/RateProvider.php:43, :58-82](../../../src/Core/RateProvider.php)) — #1/#3'ün okunamamasının sebebi bu.

   **Neden bu bir blocker'dı:** #5 ve #6 `readme.txt`'de **yayınlanmış özellik olarak duyuruluyor** ama kodda yoklar. WP.org'a giden pakette bu, ölü butondan ağır bir kategoridir. v1.0.0'ın GH release'i yapılmadığı ve gönderim ertelendiği için readme henüz hiçbir yere gitmedi — göndermeden önce yakalandı.

   **Kalıcı ders:** aynı sınıfı üç turda üç farklı sayıyla raporladım. Envanter, üç kümenin (kaydedilen / yazılan / okunan) **mekanik kesişimiyle** çıkarılmalı; "birkaç tane daha vardır" sezgisiyle değil. [[feedback_audit_the_class_not_the_report]] + [[feedback_measure_with_the_format_owner]].
3. **Geolocation cookie'si geç yazılıyor.** Fiyat filtreleme sırasında, header'lar gönderilmiş olabilecekken `setcookie` ([DetectionService.php:247](../../../src/Core/DetectionService.php) → `:185-199`) → sessizce düşebilir. Cache modunda cookie'yi JS yazar; server-side yol için yazım `template_redirect` öncesine alınır.
4. **REST wc/v3 çifte dönüşümü.** `RestApiFilter::maybe_convert_product_response` ([RestApiFilter.php:112-119](../../../src/Integration/WooCommerce/RestApiFilter.php)) `$data['price']`'ı çeviriyor — o alan `$product->get_price()` çıktısı, yani `PriceFilter`'dan **zaten geçmiş**. Cookie + `?currency=` birlikteyken tutar **iki kez** dönüşür. Karar 2 düzeltir.
   **Sahip kararı — davranış değişikliği:** `?currency=` göndermeyen wc/v3 istemcisi artık **temel para birimi** alır (bugün çerez/geolocation'a göre örtülü dönüşüyor). API tek ve öngörülebilir davranır; readme'ye davranış-değişikliği notu girer (§10).

## 9. Test

**A. `ConversionContext` — saf unit:** 9 karar dalının her biri · karar 1'in üç alt-durumu (admin sayfa / admin-ajax + admin referer / admin-ajax + **referer yok**) · **frontend admin-ajax + frontend referer → karar 7'ye düşer, dönüştürür** (tur 4 / L-2: admin-ajax'ta `wp` hiç ateşlenmez, WC'nin legacy `wp_ajax_woocommerce_*` kancaları bu terminal daldan geçer) · **`?wc-ajax=` BOŞ değer → para bağlamı DEĞİL** (tur 4 / M-1 zehirleme vektörü) · boş olmayan `wc-ajax` → dönüştür · Store API route eşleşmesi · **karar 2'nin login dalından önce gelmesi** (authenticated wc/v3 → dönüştürme) · **sayfa render'ı içindeki `rest_do_request()` REST SAYILMAZ** (tur 4 / YB4-2: WC Blocks preload'u karar 2'ye düşerse cart/checkout block hydrate'i base kalır) · cron/CLI dalı · `force_convert` en üstte · `mhmcs_should_convert` filtresinin kararı ezmesi · **memoization: pre-`wp` cevabı önbelleklenmiyor + "base" cevabı kilitlenmiyor, "dönüştür" kilitleniyor.**

**B. WC entegrasyon suite'i (yeni altyapı — ayrı task).** `tests/bootstrap.php` bugün saf stub ([:41-424](../../../tests/bootstrap.php)); WP yolu koşullu ([:428-454](../../../tests/bootstrap.php)) ve `tests/Integration/` **boş** — hiç koşulmamış. Yalnız gerçek WC ile test edilebilenler:
- 🔴 **Memoization/zamanlama kilidi 1:** `wp_loaded` sepet-session doğrulaması "dönüştür" aldıktan sonra, aynı istekte template render okuması **base** dönmeli (tur 2 / YB-1).
- 🔴 **Memoization/zamanlama kilidi 2:** cart shortcode'u WC'nin atadığı sayfa **dışında** bir sayfada; sayfa başındaki "base" cevabı, shortcode `WOOCOMMERCE_CART` tanımladıktan sonra **dönüştür**e çevrilmeli (tur 3 / H-2).
- `price_html` şekilleri: basit/indirimli/aralık/varyasyon/sabit-fiyat.
- **Variation transient:** display bağlamı base yazdıktan sonra endpoint doğru dönüştürülmüş aralığı döndürüyor mu (tur 1 / B2 kilidi).
- Store API yakalanıyor + wc-ajax dalı dönüştürüyor.
- **Authenticated wc/v3'te çifte dönüşüm yok** (§8.4 kilidi).
- `CartFilter::recalculate_fees` context'e uyuyor (tur 3 / H-1 kilidi).

**C. Endpoint (entegrasyon):** 50 sınırı + JS chunking · `product_variation` kabul, **varyasyonun kendi `private` statüsü reddediliyor** · draft/şifreli/ürün-olmayan ID atlanıyor · geçersiz currency → base · `currency:null` → geolocation · geolocation başarısız → `detected:false` + cookie yazılmıyor · `no-store` başlığı · yanıt yalnız `prices`+`currency`+`detected`.

**D. Ayarlar (saf unit):** `cache_compat` sanitize + varsayılan `true` · §8.2'nin **kaldırılan 7 üyesi** (#1-#7) UI'dan ve sanitizer'dan kalktı, kayıtlı option'da kalıntı varsa zararsız · **implemente edilen 7 üye** (#8-#14) gerçekten kaydediliyor VE okunuyor: switcher görünüm grubunun her toggle'ı render çıktısını değiştiriyor, `size` uygulanıyor, `product_widget` bayrak anahtarı tek isimde birleşti, Elementor kontrolü render'a geçiyor · **regresyon kilidi:** her ayar için "kaydet → oku → render'da etkisi görünür" üçlüsü; sanitizer whitelist'i ile JSX'in yazdığı anahtar adları **birebir eşleşiyor** (#13'ün `show_flag`/`show_flags` uyuşmazlığı sınıfı bir daha doğmasın).

**E. Tarayıcı (Chrome DevTools MCP):** cache simülasyonu (base HTML → JS convert) · switcher değişiminde **reload YOK** · katalogda EUR ↔ sepette EUR · mini-cart switcher sonrası doğru para · varyasyon seçimi doğru para · mod KAPALI'da marker/JS yok · login'li kullanıcıda marker yok · **admin sipariş ekranında "ürün ekle" base fiyat yazıyor** (§8.1 kilidi) · **🔴 `?currency=` / cookie öncelik testi: EUR cookie'li ziyaretçi `?currency=USD` linkine tıkladığında JS de sunucu da AYNI parayı kullanır** (tur 2 / YB-3 kilidi — tur 4 / M-3 bunun test karşılığının hiç yazılmadığını yakaladı) · **switcher görünüm ayarlarının her biri ön yüzde canlı önizlemeyle aynı sonucu veriyor** (§8.2 #8-12 kilidi) · konsol temiz.

## 10. Belgelenecek kabul edilmiş sınırlar (readme)

- **🔴 readme.txt düzeltmesi (§8.2):** `readme.txt:93` "per-currency payment method restrictions" ve `:94` "multilingual currency names" **var olmayan özellikler** — sürüm notlarından çıkarılır. Changelog dili de aynı turda düzeltilir. WP.org'a giden pakette yalnızca gerçekten yapılan iş duyurulur.
- **Önbellek uyumluluğunun sessizce devre dışı kalabileceği durum (§3.3):** tema/eklenti `WOOCOMMERCE_CART`/`WOOCOMMERCE_CHECKOUT`'u site genelinde tanımlarsa mod fiilen kapanır; eklenti bunu tespit edip admin'de uyarır.
- **Sepet shortcode'u WC'nin atadığı sayfa dışında bir sayfadaysa** o sayfayı cache'ten dışlayın: mandal render ortasında dönerse sayfanın kalanı marker'sız dönüşmüş basılır ve cache eklentileri bu sayfayı page-ID ile otomatik dışlamaz (tur 4 / L-3). Sepet içeriği zaten kişiseldir.
- **Mod KAPALI ≠ v1.0.0 birebir** (§3.4).
- **wc/v3 davranış değişikliği:** `?currency=` yoksa temel para (§8.4).
- **Varyasyonlar:** ek AJAX isteği + `data-product_variations` JSON'unu okuyan 3. parti swatch eklentilerinde olası gerileme (§5.5).
- **Yapılandırılmış veri:** cache modunda karar 8 zaten hem tutarı hem `woocommerce_currency`'yi base bırakır → şema doğal olarak base'tir; `woocommerce_structured_data_product` pinlemesi yalnız mod KAPALI / login'li durumda anlamlıdır (tur 2 / L1). Görünen fiyat (JS sonrası EUR) ile şema (base) uyuşmazlığı kabul edilen bedeldir.
- **SEO:** botlar base fiyatı görür.
- **JS kapalı / REST hatası:** base görünür, `console.debug`.
- **Flash:** base → `opacity 0→1`; `prefers-reduced-motion`'a saygı.
- **`?currency=` cache-key patlaması.**
- **Mini-cart + fragments** (§7).
- **Login'li cache bypass varsayımı:** çoğu cache eklentisi login'li kullanıcıyı bypass eder; cookie'ye bakmadan cache'leyen edge/CDN kurulumunda login'li kullanıcının dönüşmüş sayfası cache'lenebilir.

## 11. Denetim kapanış eşlemesi (tur 4 doğrulayabilsin diye)

| Bulgu | Nerede kapandı |
|---|---|
| B1 FormatFilter | §2.5, §3, §4 (+ tur 3 / H-1 ile `CartFilter` eklendi) |
| B2 variation hash | §4 `add_currency_to_hash` |
| B3 bağlam listesi | §3.1 tablo, §3.2 |
| B4 geolocation/URL param | §5.1, §5.2, §5.4 |
| YB-1 memoization sızıntısı | §3.3 kural 1 + §9B kilit 1 |
| YB-2 wc-ajax allowlist | §3.2 |
| YB-3 JS zincir sırası | §5.2 |
| YB-4 admin-ajax | §3.1 karar 1 (+ tur 3 / H-3 referer yönü) |
| H1 REST + çifte dönüşüm | §3.1 karar 2, §8.4 (+ tur 3 / NB-1 sıra düzeltmesi) |
| H2 geolocation başarısızlık | §5.4 |
| H3 Elementor kapsamı | §4 kapsam-dışı paragrafı |
| H4 varyasyon post type | §6 (+ tur 3 / M-1 kendi statüsü) |
| H5 fragments invalidation | §5.4 |
| H6 variations JSON | §5.5 |
| M1 ölü ayarlar | §8.2 — **v4'teki "4 üye" iddiası YANLIŞTI** (tur 4 / YB4-1 falsifiye etti). Gerçek envanter **14**, mekanik kesişimle sayıldı; 7 kaldırılıyor, 7 implemente ediliyor |
| **YB4-1 ölü-kontrol envanteri** | §8.2 tablo + §7 + §9D + §10 readme düzeltmesi |
| **YB4-2 REST ilkeli** | §3.2 Store API maddesi (ilkel pinlendi) + §3.1 karar 2 + §9A internal-dispatch testi |
| tur4 M-1 `?wc-ajax=` boş değer | §3.2 (`! empty`) + §9A |
| tur4 M-2 mandalın en kötü durumu | §3.3 son paragraf + §10 |
| tur4 M-3 `?currency=` test kilidi | §9E |
| tur4 L-1 endpoint cookie yazımı | §4 `DetectionService` satırı |
| tur4 L-2 karar 7 terminal dalı | §9A |
| tur4 L-3 cart-shortcode sayfası | §10 |
| **M2 JS config localization** | §4 `Enqueue.php` satırı |
| **M3 50 ID vs sayfa başına marker** | §5.1 chunking + §6 |
| **M4 "mod KAPALI birebir" iddiası** | §3.4 |
| M5 cookie öznitelikleri | §5.3 |
| NB-1 REST/login sırası | §3.1 karar 2 |
| tur3 H-1 CartFilter | §4, §3.6 |
| tur3 H-2 post-`wp` kilitlenme | §3.3 kural 2 + §9B kilit 2 |
| tur3 H-3 referer yönü | §3.1 karar 1 |
| tur3 M-1 varyasyon statüsü | §6 |
| tur3 M-3 swatch gerilemesi | §5.5, §10 |
| tur3 M-4 cron/CLI | §3.1 karar 3 |
| tur3 L-1 endpoint istisnası | §3.1 karar 0 |
| tur3 L-2 detection memo | §3.3 son paragraf |
| tur3 drift (RateProvider yolu, sembol nüansı) | §8.2, §8.1 |

## 12. İlişkili
- [[project_currency_switcher]] — Pro yol haritasında bu #1 idi; artık ücretsiz-çekirdek kalitesi.
- [[feedback_carve_shipping_surface_blind_spots]] · [[feedback_gate_suppression_blindspot]] (§3.2'deki gerekçeli ignore)
- [[feedback_pre_zip_fable_gate]] — spec 3 tur denetimden geçti; kod ayrıca ZIP-öncesi zorunlu Fable gate'ine girecek.
- [[feedback_audit_the_class_not_the_report]] — denetimler yan ürün olarak 4 üretim bug'ı + 4 ölü kontrol çıkardı (§8): bulgu örneklemdir, sınıfı tara. Tur 3'ün M-2'si bunun kendi içindeki tekrarı: v3 sınıfın 2 üyesini görmüştü, gerçek envanter 4'tü.
