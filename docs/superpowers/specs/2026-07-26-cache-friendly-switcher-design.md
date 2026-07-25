# MHM Currency Switcher — Cache-Friendly Switcher Tasarımı (v1.1)

**Tarih:** 2026-07-26
**Revizyon:** v3 — Fable bağımsız spec-denetimi tur 1 (NO-GO, 4 blocker) ve tur 2 (NO-GO, 4 yeni blocker + 6 HIGH) sonrası
**Durum:** Revize edildi, Fable tur 3 denetimi bekliyor
**Hedef sürüm:** v1.1.0

> **Denetim geçmişi.** **Tur 1:** `FormatFilter` tasarımda hiç yoktu → "base tutar + dönüşmüş sembol" cache'lenirdi; variation transient hash'i bağlamı kodlamıyordu; bağlam listesi eksik + `is_cart()` zamanlama açısından güvenilmezdi; geolocation/`?currency=` cache modunda ölüyordu. **Tur 2:** v2'nin *kendi getirdiği* mekanizmada dört yeni blocker: karar memoization'ı kök bug'ı geri getiriyordu; `wc-ajax` **allowlist**'i ödeme ağ geçitlerini sayamadığı için yanlış tahsilat üretiyordu; JS tespit sırası sunucunun tersineydi; admin-ajax sipariş kalemi açıktaydı. Bu revizyon hepsini kapatır ve ortaya çıkan 4. üretim bug'ını (REST çifte dönüşüm) kapsama alır.

---

## 1. Problem

Fiyatlar şu an **server-side** dönüşüyor: `PriceFilter` ([PriceFilter.php:64-82](../../../src/Integration/WooCommerce/PriceFilter.php)) `woocommerce_product_get_price` ailesine priority 100'de bağlanıp tutarı çevirir; `FormatFilter` ([FormatFilter.php:68-79](../../../src/Integration/WooCommerce/FormatFilter.php)) sembolü, para kodunu ve ayraçları çevirir. WC'nin `price_html`'i ikisinin bileşiminden doğar. Sayfa cache'lenince bu çıktı statik HTML'e gömülür → **ilk ziyaretçinin para birimi cache'lenir, ikinci ziyaretçi onu görür.**

Pazar araştırması: caching çakışması currency-switcher eklentilerinin **#1 kronik şikâyeti**; "önbellek dostu" en güçlü pazarlama kozu.

Ek olarak switcher `window.location.reload()` yapıyor ([switcher.js:62](../../../assets/js/switcher.js)) — yavaş, üstelik cache'li sayfayı yeniden servis ettiği için çözüm değil.

## 2. Yaklaşım (onaylanmış kararlar)

1. **İstemci-taraflı AJAX dönüşüm.** Sayfa **temel para biriminde** cache'lenir; JS görünüm fiyatlarını dönüştürür. Cache eklentisinden bağımsız, config'siz.
2. **Dönüşüm sunucuda (REST).** JS ürün ID'lerini toplu gönderir; sunucu dönüştürülmüş+biçimlenmiş `price_html` döner. Tek doğruluk kaynağı; JS'te format/yuvarlama tekrarı YOK.
3. **Yüzey ayrımı:** görünüm = temel+marker+JS · sepet/ödeme/sipariş totalleri = server-side.
4. **Toggle `cache_compat`, varsayılan AÇIK.**
5. **Tek paylaşılan bağlam çözücü** — `PriceFilter`, `FormatFilter`, `CouponFilter`, `ShippingFilter` ve variation-hash ayrı ayrı karar veremez (tur 1 / B1).
6. **Login'li kullanıcıda server-side'a düş** — marker/JS üretilmez. Page cache login'li kullanıcıda zaten bypass edilir; üye-fiyatı eklentilerinin fiyatını JS'in misafir fiyatıyla ezmesini ve nonce tartışmasını birlikte bitirir.
7. **Tespit zinciri JS'e taşınır** — geolocation ve `?currency=` cache modunda ölmesin diye (tur 1 / B4).
8. **🆕 Para bağlamında allowlist YOK, varsayılan "dönüştür".** Ödeme ağ geçitleri kendi `wc-ajax` endpoint'lerini kaydeder; sonlu bir liste onları asla sayamaz (tur 2 / YB-2).

---

## 3. `ConversionContext` — tek bağlam çözücü

**Yeni:** `src/Core/ConversionContext.php`. Tek public sorusu: `should_convert(): bool`. `PriceFilter`, `FormatFilter`, `CouponFilter`, `ShippingFilter` ve variation-hash bu tek cevabı paylaşır.

### 3.1 Karar sırası (ilk eşleşen kazanır)

| # | Koşul | Karar | Gerekçe |
|---|---|---|---|
| 1 | **Admin bağlamı**: `is_admin()` **ve** (`! wp_doing_ajax()` **veya** referer `admin_url()` ile başlıyor) | **Dönüştürme** | Admin daima base görür. §8.1'in *tam* kapsamı: admin-ajax dalı olmadan sipariş ekranındaki "ürün ekle" dönüşmüş fiyatı **sipariş kalemine kalıcı yazar** (tur 2 / YB-4). `wc-ajax` bu dala girmez — o `admin-ajax.php` değil, `template_redirect` üzerinden koşar. |
| 2 | `cache_compat` KAPALI | **Dönüştür** | Mevcut v1.0.0 görünüm davranışı. (§8.1 fix'i toggle'dan bağımsız yaşar — bkz. §3.4.) |
| 3 | `is_user_logged_in()` | **Dönüştür** | Karar 6: server-side yol, marker yok. |
| 4 | **Para bağlamı** (§3.2) | **Dönüştür** | Müşterinin ödeyeceği tutar. |
| 5 | **REST isteği**, Store API ve kendi endpoint'imiz **dışında** (`WC()->is_rest_api_request()` veya `REST_REQUEST`) | **Dönüştürme** | wc/v3 ürün okumaları. Aynı zamanda §8.4'teki çifte-dönüşüm bug'ının fix'i: `RestApiFilter` `?currency=` ile kendi dönüşümünü yapar, `PriceFilter` artık öncesinde bir kez daha çevirmez. Karar 6'dan **önce** gelmeli — REST'te `wp` hiç ateşlenmez. |
| 6 | `! did_action( 'wp' )` | **Dönüştür** | Erken okumalar (`wp_loaded` sepet-session doğrulaması). Conditional tag'ler henüz kurulmamıştır; `is_cart()` bu anda `_doing_it_wrong` + `false` döner. **Bu cevap memoize EDİLMEZ** — bkz. §3.3. |
| 7 | aksi hâlde | **Dönüştürme (base)** | Katalog/ürün/arşiv görünümü. |

Kaçış kapısı: `apply_filters( 'mhmcs_should_convert', bool $decision, string $reason )`.

### 3.2 Para bağlamı (karar 4)

- `defined( 'WOOCOMMERCE_CHECKOUT' )` veya `defined( 'WOOCOMMERCE_CART' )`
- **`wc-ajax` parametresi VARSA — değeri ne olursa olsun → dönüştür.**
  Tur 2'nin YB-2'si: allowlist yanlış yön. Stripe/PayPal express checkout kendi endpoint'lerini kaydeder (`wc-ajax=wc_stripe_*`, `ppc-create-order`); sonlu liste onları sayamaz → ürün sayfasındaki express ödeme butonu **base tutarla tahsilat yapardı**. `wc-ajax` çıktısı hiçbir zaman sayfa cache'ine girmediği için "hepsi dönüşür" kuralının cache maliyeti **sıfır**, kazancı tam. Okuma: `sanitize_key( wp_unslash( $_GET['wc-ajax'] ) )` + gerekçeli `WordPress.Security.NonceVerification.Recommended` ignore (susturma-kuyruğu dersi: gerekçe koda yazılır).
- **Store API:** `WC()->is_rest_api_request()` **ve** route `/wc/store` ile başlıyor.
  ⚠ `WC()->cart` varlığı ayırt edici **değildir** — normal frontend isteklerinde de vardır.
- Conditional tag'ler kurulmuşsa: `is_cart() || is_checkout() || is_account_page()`
- **Kendi convert endpoint'imiz** — `ConversionContext::force_convert()` ile açıkça işaretlenir.

### 3.3 🔴 Memoization faz semantiği (tur 2 / YB-1)

v2 "karar istek başına bir kez hesaplanıp önbelleklenir" diyordu. **Bu, özelliğin kendisini boşa çıkarıyordu:** `wp_loaded` sepet-session doğrulaması karar 6'dan "dönüştür" alır → cevap memoize edilir → aynı istekte template render de o cevabı kullanır → sayfa sunucuda dönüşmüş basılır, marker üretilmez, edge onu cache'ler → **sepeti olan her ziyaretçi için kök bug geri gelir.** "Bu okumalar sayfa HTML'ine gitmez" doğruydu; ama **memoize edilen karar gidiyordu**.

**Kural:** memoization anahtarı isteğin fazını içerir — `did_action('wp') ? 'post-wp' : 'pre-wp'`. Pratikte: karar 6'dan dönen cevaplar **hiç önbelleklenmez**; `wp` ateşlendikten sonra hesaplanan cevap istek sonuna kadar önbelleklenir.

### 3.4 `cache_compat` KAPALI'nın anlamı

Karar 1, karar 2'den **önce** geldiği için toggle KAPALI'yken de admin davranışı değişir (§8.1 bilinçli bug-fix'i). Yani "mod KAPALI = v1.0.0 birebir" **değil**; doğru ifade: *"mod KAPALI = v1.0.0'ın görünüm davranışı, artı admin/REST bug-fix'leri."* readme ve ayar açıklaması bunu böyle söyler.

### 3.5 Hata modu sıralaması

- **Yanlış tahsilat (en tehlikeli):** Store API tespiti kaçarsa blocks-checkout base tutarla sipariş keser. Klasik checkout `WOOCOMMERCE_CHECKOUT` sabitiyle güvende; ağ geçidi wc-ajax'ları §3.2'nin "hepsi dönüşür" kuralıyla güvende.
- **Yanlış gösterim + session tutarsızlığı:** `CouponFilter::convert_coupon_amount` ([CouponFilter.php:90-104](../../../src/Integration/WooCommerce/CouponFilter.php)) bugün bağlama değil yalnız detection'a bakıyor → EUR'a çevrilmiş kupon, base bırakılmış kalemlerden düşülürdü. `CouponFilter` + `ShippingFilter` de `ConversionContext`'e bağlanır.
- **Cache kırılması (en zararsız):** fazladan dönüşüm.

**İlke:** belirsizlikte **dönüştür** tarafına yanıl.

## 4. Bileşenler

| Bileşen | Tip | Sorumluluk |
|---|---|---|
| `src/Core/ConversionContext.php` | yeni | §3'teki tek karar. Enjekte edilebilir seam (test edilebilirlik). |
| `PriceFilter.php` | değişir | `convert_price`/`convert_sale_price`/`convert_variation_price` → context'e sorar. |
| `FormatFilter.php` | değişir | 6 format filtresi aynı kararı kullanır. Kayıt-zamanı `is_admin()` guard'ı ([FormatFilter.php:69-71](../../../src/Integration/WooCommerce/FormatFilter.php)) runtime karar 1 ile çakışmamalı — guard kaldırılıp karar 1'e devredilir. |
| `PriceFilter::add_currency_to_hash` | değişir | Variation transient hash'i bağlamı kodlar: dönüştürmüyorsak `<base>:display`, dönüştürüyorsak hedef kod. Aksi hâlde display bağlamı base değerleri "EUR" hash'ine yazar, endpoint aynı hash'ten base okur → variable ürünler kalıcı no-op (tur 1 / B2). |
| `CouponFilter.php`, `ShippingFilter.php` | değişir | Aynı karara bağlanır. |
| `src/Frontend/PriceDisplayMarker.php` | yeni | Dönüştürmediğimiz bağlamda `woocommerce_get_price_html` çıktısını `<span class="mhmcs-price" data-mhmcs-product="ID">…base price_html…</span>` ile sarar. |
| `src/Rest/ConvertController.php` | yeni | `POST mhmcs/v1/convert`. Güvenlik: §6. |
| `assets/js/price-converter.js` | yeni | Marker toplama → batch REST → HTML yazma. Tespit: §5. |
| `assets/js/switcher.js` | değişir | `reload()` yerine cookie set + `mhmcs:currency-changed`. **Load'ta** aktif-para göstergesini cookie'den senkronlar. Cookie öznitelikleri §5.3. |
| `Switcher.php`, `Elementor/SwitcherWidget.php`, `NavMenu` | değişir | Cache modunda **nötr** render — `data-current` ([Switcher.php:109](../../../src/Frontend/Switcher.php)) ve `mhm-cs-active` ([:122](../../../src/Frontend/Switcher.php)) ön-seçili basılmaz; aksi hâlde cache'li sayfada switcher hep base'i seçili gösterir. |
| `DetectionService.php` | değişir | `set_request_override( string $code )` (istek-kapsamlı, cookie yazmaz; aktifken geolocation hiç koşmaz) + `sanitize_currency_code()` private→paylaşılabilir. |
| `Enqueue.php` | değişir | **Bugün hiçbir localize yok** ([Enqueue.php:67](../../../src/Frontend/Enqueue.php)). Eklenecek: REST URL, base currency, `cache_compat`, `auto_detect`, kod→sembol/bayrak haritası (switcher load-time senkronu için). |
| Ayarlar (`RestAPI` sanitizer + `AdvancedSettings.jsx`) | değişir | `cache_compat` **gerçek** ayar (varsayılan `true`). Whitelist'e eklenmezse sessizce düşer (§8.2). |

**Kapsam dışı — `ProductWidget` ve Elementor `PriceDisplayWidget`:** [ProductWidget.php:152-172](../../../src/Frontend/ProductWidget.php) **ziyaretçiden bağımsız çoklu-para listesi** basıyor ve ham `_price` meta okuyor → zaten cache-safe; marker+`innerHTML` replace onu **bozar**. Elementor'ın `PriceDisplayWidget`'ı ([PriceDisplayWidget.php:112-124](../../../src/Integration/Elementor/PriceDisplayWidget.php)) doğrudan `ProductWidget::render_shortcode()`'a delege ettiği için **aynı çıktıdır** ve o da kapsam dışıdır (tur 2 / H3 — v2 bunu iki zıt yere koymuştu). Elementor **Pro**'nun kendi Product Price widget'ı `get_price_html` kullandığı için marker'ı otomatik alır.

## 5. Veri akışı + tespit zinciri

### 5.1 Akış

```
[Cache'li sayfa: base HTML + <span data-mhmcs-product=42>$10.00</span>]
   ↓ DOMContentLoaded
price-converter.js tespit sırası (SUNUCUYLA AYNI — DetectionService.php:154-171):
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
   → converter yeniden koşar + wc_fragment_refresh (§5.4)
[Sepet/ödeme] → ConversionContext "dönüştür" → gerçek tutar server-side (uncached)
```

### 5.2 🔴 Zincir sırası sunucuyla aynı olmalı (tur 2 / YB-3)

v2, JS sırasını `?currency=` → cookie diye yazmıştı; sunucu ise cookie → URL param → geolocation ([DetectionService.php:154-171](../../../src/Core/DetectionService.php)). EUR cookie'li ziyaretçi `?currency=USD` linkine tıklarsa JS katalogda USD basar, sepet server-side EUR kullanır → **müşteri USD görür, EUR öder.** Zincir **cookie-first** olmak zorunda.

Cache MISS tarafı temiz: karar 7'de sunucu zaten base basar, query-var yolu fiyatı etkilemez → çifte dönüşüm yok.

### 5.3 Cookie öznitelik sözleşmesi (tur 2 / M5)

PHP bugün: `expires` 30 gün · `path /` · `secure is_ssl()` · `httponly false` · `samesite Lax` ([DetectionService.php:185-199](../../../src/Core/DetectionService.php)). Mevcut `switcher.js` ise `max-age` 30g + `path=/` + `SameSite=Lax` yazıyor ama **`Secure` yok** ([switcher.js:53-57](../../../assets/js/switcher.js)). Cookie'yi artık JS yazdığı için öznitelikler PHP ile **birebir** hizalanır (https'te `Secure` dahil), yoksa iki mod arasında kalıcılık sessizce bozulur.

### 5.4 Geolocation ve fragments

- **Geolocation başarısızlık sözleşmesi (tur 2 / H2):** CF-IPCountry yok + MaxMind DB kurulu değil (yaygın) → endpoint base döner. Yanıt `detected: false` taşır ve JS **cookie yazmaz**. Aksi hâlde base cookie yazılır, zincir 1. adımda takılır ve geolocation bir daha hiç denenmez. Sunucunun bugünkü davranışı da başarısızlıkta cookie yazmamak ([DetectionService.php:224-250](../../../src/Core/DetectionService.php)).
- **Mini-cart fragments invalidation (tur 2 / H5):** WC fragment'ları `sessionStorage`'da cart-hash anahtarıyla saklar; para değişimi cart-hash'i değiştirmez → switcher'dan sonra mini-cart **eski para biriminde** kalır. `mhmcs:currency-changed`'de fragment storage temizlenir + `wc_fragment_refresh` tetiklenir.

### 5.5 Varyasyonlar (tur 2 / H6)

Cache'li sayfada `data-product_variations` attribute'u **base** `price_html` JSON'u taşır ve WC'nin `variation.js`'i seçimde onu (marker'sız) DOM'a yazar. Çözüm: cache modunda `woocommerce_ajax_variation_threshold` filtresiyle **AJAX varyasyon yolu zorlanır** → fiyat `wc-ajax=get_variation`'dan gelir, o da §3.2 kuralıyla **dönüşür**. Böylece tek yol kalır; bedeli varyasyon seçiminde bir ek AJAX — readme'de belirtilir. `reset_data` (seçim temizleme) marker'ın base içeriğine döner, tutarlı.

## 6. `POST mhmcs/v1/convert` güvenliği

- `register_rest_route` **`args` şeması** (`validate_callback`/`sanitize_callback`) — mevcut konvansiyon elle sanitize ([RestAPI.php:96-165](../../../src/Admin/RestAPI.php)), reviewer'lar şemayı tercih eder.
- `product_ids`: istek başına en fazla **50**; JS 50'lik parçalara böler (§5.1). Aşan → `400`.
- Her ID `absint`; post type **`product` veya `product_variation`** (tur 2 / H4: varyasyonlar `product` değildir; §7'nin `found_variation` özelliği aksi hâlde ölü doğardı). Varyasyonda `publish` + `post_password_required()` kontrolü **parent üzerinden** yapılır.
- Ürün: `publish` + `post_password_required()` false. Şifre korumalı üründe fiyat sayfada gizliyken endpoint'ten sızmamalı.
- `currency`: strict `^[A-Z]{3}$` + enabled-currencies allowlist; geçersizse base (no-op). `null` = "tespit et".
- Yanıt: `prices` + çözülen `currency` + `detected` — sayfada zaten public olan veri.
- **`Cache-Control: no-store`** (IP'ye göre değişir; POST'u cache'leyen edge yapılandırmalarına karşı).
- **Nonce yok, `permission_callback => '__return_true'`** — public read-only için WP.org'ca kabul edilir; repoda emsali var ([RestAPI.php:155-164](../../../src/Admin/RestAPI.php) `/rates`). WP.org'un gerçek şartı: callback'in açıkça set edilmesi + girdi doğrulama + veri sızıntısı olmaması. Login'li kullanıcı bu yolu hiç kullanmıyor (karar 6), dolayısıyla "misafir bağlamı" kusur değil tasarım.
- `price_html` sunucu-üretimi WC HTML'i; sembol admin girdisi ve girişte sanitize ediliyor ([RestAPI.php:486](../../../src/Admin/RestAPI.php)) → `innerHTML` kabul edilebilir. §9C'nin "yanıt yalnız `prices`+`currency`+`detected`" testi kilit.

## 7. Kapsam

**İçinde:** katalog/mağaza/arşiv/ürün/ilgili-ürünler/öne-çıkanlar görünüm fiyatları · Elementor **Pro** Product Price widget (get_price_html üzerinden) · switcher no-reload + load-time UI senkronu · `cache_compat` toggle · basit/indirimli/aralık/varyasyon `price_html` şekilleri · varyasyon seçimi (§5.5) · §8'deki 4 mevcut bug.

**Dışında (bilinçli):** sepet/ödeme/sipariş totalleri (server-side) · `ProductWidget` + Elementor `PriceDisplayWidget` (§4) · login'li kullanıcı (server-side'a düşer) · Cloudflare edge özel config · kişiselleştirilmiş/rol-bazlı fiyat.

**Mini-cart:** her sayfada render edildiği için istek-bazlı sınıflandırılamaz. Cache modunda base render edilir; doğruluğu `get_refreshed_fragments`'a yaslanır (§3.2 kuralıyla dönüşür) + §5.4 invalidation. **Fragments kapalıysa** cache'lenmiş mini-cart base kalır → tespit edilip **admin uyarısı** basılır + readme'de belgelenir.

## 8. Denetimin ortaya çıkardığı, bugün v1.0.0'da CANLI olan buglar (v1.1 kapsamında)

1. **Admin'de fiyat dönüşümü.** `PriceFilter::init()` koşulsuz kayıtlı ([Plugin.php:117-118](../../../src/Plugin.php)); `FormatFilter`'ın aksine `is_admin()` guard'ı yok → adminin tarayıcısında `mhmcs_currency` cookie'si varsa fiyat dönüşür (sembol base kalır). **En zararlı yüzey admin-ajax'tır:** sipariş ekranında "ürün ekle" `admin-ajax.php` üzerinden koşar ve WC ürünü `get_price()` (view context, filtreli — [PriceFilter.php:66](../../../src/Integration/WooCommerce/PriceFilter.php)) ile okur → **dönüşmüş fiyat sipariş kalemine kalıcı yazılır.** Ekran görüntüsü değil, veri. Karar 1'in admin-ajax dalı bunu kapatır. (Admin *sayfa* yüklemeleri zaten güvende: WC edit ekranları `'edit'` context ile okur, bu filtre uygulanmaz.)
2. **İki ayar sessizce düşüyor.** `multilingual_mapping` ve `provider_api_key` JSX'te var ([AdvancedSettings.jsx:28-38, :159-173](../../../admin-app/src/components/tabs/AdvancedSettings.jsx)), `src/` içinde tüketici **sıfır** → `save_settings` whitelist sanitizer'ı ([RestAPI.php:207-255](../../../src/Admin/RestAPI.php)) bilinmeyen key'i atıyor.
   🔴 **Ama doğru fix "sanitizer'a ekle" DEĞİL** (tur 2 / M1): `RateProvider` provider ayarını da, herhangi bir API key'i de hiç okumuyor ([RateProvider.php:58-82](../../../src/Currency/RateProvider.php) — sabit ExchangeRate→Fawaz zinciri, key parametresi yok). Sanitizer'a eklemek **ölü bir şifre alanını kalıcılaştırır**. Bu tam olarak WP.org'un reddettiği "hiçbir şey yapmayan kontrol" sınıfı (Rentiva'nın GDPR ölü-UI dersi). **Öneri: ikisini de UI'dan kaldır.** İmplemente etmek ayrı bir özellik kararıdır → **kullanıcı onayı gerekir, plan bunu ayrı task olarak sorar.**
3. **Geolocation cookie'si geç yazılıyor.** Fiyat filtreleme sırasında, header'lar gönderilmiş olabilecekken `setcookie` ([DetectionService.php:247](../../../src/Core/DetectionService.php) → `:185-199`) → sessizce düşebilir. Cache modunda cookie'yi JS yazar; server-side yol için yazım `template_redirect` öncesine alınır.
4. **🆕 REST wc/v3 çifte dönüşümü.** `RestApiFilter::maybe_convert_product_response` ([RestApiFilter.php:112-119](../../../src/Integration/WooCommerce/RestApiFilter.php)) `$data['price']`'ı çeviriyor — ama o alan `$product->get_price()` çıktısı, yani `PriceFilter`'dan **zaten geçmiş**. Cookie + `?currency=` birlikteyken tutar **iki kez** dönüşür. Karar 5 (REST → dönüştürme) bunu tek hamlede düzeltir.

## 9. Test

**A. `ConversionContext` — saf unit (yeni seam sayesinde mümkün):** 7 karar dalının her biri · karar 1'in admin-ajax/frontend-ajax ayrımı · `wc-ajax` parametresi varlığının yeterliliği · Store API route eşleşmesi · REST dalının Store API ve kendi endpoint'imizi dışlaması · `mhmcs_should_convert` filtresinin kararı ezmesi · **memoization fazı**: `pre-wp` cevabı önbelleklenmiyor.

**B. WC entegrasyon suite'i (yeni altyapı — ayrı task).** `tests/bootstrap.php` bugün saf stub ([:41-424](../../../tests/bootstrap.php)); WP yolu koşullu ([:428-454](../../../tests/bootstrap.php)) ve `tests/Integration/` **boş** — hiç koşulmamış. Yalnız gerçek WC ile test edilebilenler:
- 🔴 **Memoization/zamanlama regresyon kilidi:** `wp_loaded` sepet-session doğrulaması "dönüştür" cevabı aldıktan **sonra**, aynı istekte template render okuması **base** dönmeli. (v2'nin testi bu bug'ı geçirirdi — tur 2 / YB-1.)
- `price_html` şekilleri: basit/indirimli/aralık/varyasyon/sabit-fiyat.
- **Variation transient:** display bağlamı base yazdıktan sonra endpoint doğru dönüştürülmüş aralığı döndürüyor mu (B2 kilidi).
- Store API bağlamının gerçekten yakalanması + wc-ajax dalının dönüştürmesi.
- REST wc/v3'te çifte dönüşüm yok (§8.4 kilidi).

**C. Endpoint (entegrasyon):** 50 sınırı + JS chunking · `product_variation` kabul ediliyor, parent publish/şifre kontrolü · draft/şifreli/ürün-olmayan ID atlanıyor · geçersiz currency → base · `currency:null` → geolocation · geolocation başarısız → `detected:false` + cookie yazılmıyor · `no-store` başlığı · yanıt yalnız `prices`+`currency`+`detected`.

**D. Ayarlar (saf unit, mevcut kalıp):** `cache_compat` sanitize + varsayılan `true`.

**E. Tarayıcı (Chrome DevTools MCP):** cache simülasyonu (base HTML → JS convert) · switcher değişiminde **reload YOK** · katalogda EUR ↔ sepette EUR tutarlılığı · mini-cart switcher sonrası doğru para · varyasyon seçimi doğru para · mod KAPALI'da marker/JS yok · login'li kullanıcıda marker yok · admin sipariş ekranında "ürün ekle" **base** fiyat yazıyor (§8.1 kilidi) · konsol temiz.

## 10. Belgelenecek kabul edilmiş sınırlar (readme)

- **Yapılandırılmış veri:** cache modunda karar 7 zaten hem tutarı hem `woocommerce_currency`'yi base bırakır, yani şema doğal olarak base'tir; `woocommerce_structured_data_product` pinlemesi yalnız **mod KAPALI / login'li** durumda anlamlıdır (tur 2 / L1). Görünen fiyat (JS sonrası EUR) ile şema (base) uyuşmazlığı bu yaklaşımın kabul edilen bedelidir; Google Merchant uyarısı verebilir.
- **SEO:** botlar base fiyatı görür.
- **JS kapalı / REST hatası:** base görünür, `console.debug`, kullanıcıya hata yok.
- **Flash:** base → `opacity 0→1`; `prefers-reduced-motion`'a saygı.
- **`?currency=` cache-key patlaması:** cache eklentileri query-string'i ayrı anahtar yapar.
- **Mini-cart + fragments** (§7) ve **varyasyonda ek AJAX** (§5.5).
- **Mod KAPALI ≠ v1.0.0 birebir** (§3.4).
- **Login'li cache bypass varsayımı:** çoğu cache eklentisi login'li kullanıcıyı bypass eder; cookie'ye bakmadan cache'leyen edge/CDN kurulumunda login'li kullanıcının dönüşmüş sayfası cache'lenebilir.

## 11. İlişkili
- [[project_currency_switcher]] — Pro yol haritasında bu #1 idi; artık ücretsiz-çekirdek kalitesi.
- [[feedback_carve_shipping_surface_blind_spots]] · [[feedback_gate_suppression_blindspot]] (§3.2'deki gerekçeli ignore)
- [[feedback_pre_zip_fable_gate]] — spec 2 tur denetimden geçti (v1 NO-GO → v2 NO-GO → v3); kod ayrıca ZIP-öncesi zorunlu Fable gate'ine girecek.
- [[feedback_audit_the_class_not_the_report]] — denetimler yan ürün olarak 4 üretim bug'ı çıkardı (§8): bulgu örneklemdir, sınıfı tara.
