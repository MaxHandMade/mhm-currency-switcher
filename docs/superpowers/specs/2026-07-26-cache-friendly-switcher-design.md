# MHM Currency Switcher — Cache-Friendly Switcher Tasarımı (v1.1)

**Tarih:** 2026-07-26
**Revizyon:** v2 — Fable bağımsız spec-denetimi (NO-GO, 4 blocker + 13 bulgu) sonrası yeniden yazıldı
**Durum:** Revize edildi, Fable re-denetimi bekliyor
**Hedef sürüm:** v1.1.0

> **v1 neden NO-GO aldı (özet):** Spec, fiyatı sayfaya basan iki mekanizmadan yalnız birini (`PriceFilter`) ele almıştı; `FormatFilter` (sembol/kod/ayraç) hiç geçmiyordu → "base tutar + dönüşmüş sembol" cache'lenirdi. Ayrıca variation-prices transient hash'i bağlamı kodlamıyordu, bağlam listesi eksik+zamanlama açısından güvenilmezdi, ve mode AÇIK'ken geolocation/URL-param tespiti sessizce ölüyordu. Bu revizyon dördünü de kapatır.

---

## 1. Problem

Fiyatlar şu an **server-side** dönüşüyor: `PriceFilter` ([src/Integration/WooCommerce/PriceFilter.php:64-82](../../../src/Integration/WooCommerce/PriceFilter.php)) `woocommerce_product_get_price` ailesine priority 100'de bağlanıp tutarı çevirir; `FormatFilter` ([FormatFilter.php:68-79](../../../src/Integration/WooCommerce/FormatFilter.php)) sembolü, para kodunu ve ayraçları çevirir. WC'nin `price_html`'i ikisinin bileşiminden doğar. Sayfa cache'lenince (WP Rocket / LiteSpeed / Cloudflare) bu çıktı statik HTML'e gömülür → **ilk ziyaretçinin para birimi cache'lenir, ikinci ziyaretçi onu görür.**

Pazar araştırması: caching çakışması currency-switcher eklentilerinin **#1 kronik şikâyeti**; "önbellek dostu" en güçlü pazarlama kozu.

Ek olarak switcher `window.location.reload()` yapıyor ([assets/js/switcher.js:62](../../../assets/js/switcher.js)) — yavaş, üstelik cache'li sayfayı yeniden servis ettiği için çözüm değil.

## 2. Yaklaşım (onaylanmış kararlar)

1. **İstemci-taraflı AJAX dönüşüm.** Sayfa **temel para biriminde** cache'lenir; JS yüklenince görünüm fiyatlarını dönüştürür. Cache eklentisinden bağımsız, config'siz çalışır.
2. **Dönüşüm sunucuda (REST).** JS ürün ID'lerini toplu gönderir; sunucu **dönüştürülmüş+biçimlenmiş `price_html`** döner — mevcut `Converter`/`FormatFilter`/`ProductPricing` + WC'nin kendi `price_html` üretimi. Tek doğruluk kaynağı; JS'te format/yuvarlama tekrarı YOK.
3. **Yüzey ayrımı:** görünüm fiyatları = temel+marker+JS · sepet/ödeme/sipariş totalleri = server-side.
4. **Toggle `cache_compat`, varsayılan AÇIK.**
5. **🆕 Tek paylaşılan bağlam çözücü.** `PriceFilter` ve `FormatFilter` bağlam kararını **ayrı ayrı veremez** — tek istek içinde tutarsızlaşırlar (v1'in B1'i). Karar tek bir `ConversionContext` servisinde toplanır, ikisi de ona sorar.
6. **🆕 Login'li kullanıcıda server-side'a düş.** Kullanıcı giriş yapmışsa marker/JS üretilmez, mevcut davranış çalışır. Page cache login'li kullanıcıda zaten bypass edildiği için bedeli yok; üye-fiyatı eklentilerinin fiyatını JS'in misafir fiyatıyla ezmesini ve nonce tartışmasını birlikte bitirir.
7. **🆕 Tespit zinciri JS'e taşınır.** Geolocation ve `?currency=` cache modunda ölmesin diye (v1'in B4'ü) tespit, convert isteğinin kendisiyle çözülür.

---

## 3. `ConversionContext` — tek bağlam çözücü (v1'in B1+B3'ü)

**Yeni:** `src/Core/ConversionContext.php`. Tek public sorusu: `should_convert(): bool` (istek başına bir kez hesaplanıp önbelleklenir). `PriceFilter`, `FormatFilter` ve variation-hash bu tek cevabı paylaşır.

### Karar sırası (ilk eşleşen kazanır)

| # | Koşul | Karar | Gerekçe |
|---|---|---|---|
| 1 | `is_admin() && ! wp_doing_ajax()` | **Dönüştürme** | Admin ekranları daima base görür. Bugünkü bug'ı kapatır (§8.1). |
| 2 | `cache_compat` KAPALI | **Dönüştür** | Mevcut v1.0.0 davranışı birebir. |
| 3 | `is_user_logged_in()` | **Dönüştür** | Karar 6: server-side yol, marker yok. |
| 4 | **Para bağlamı** (aşağıdaki allowlist) | **Dönüştür** | Müşterinin ödeyeceği tutar. |
| 5 | `! did_action( 'wp' )` | **Dönüştür** | Erken okumalar (`wp_loaded` sepet-session doğrulaması) — conditional tag'ler henüz kurulmamıştır, `is_cart()` bu anda `_doing_it_wrong` + `false` döner. Bu okumalar sayfa HTML'ine gitmez, dolayısıyla dönüştürmek cache'i kırmaz. **Bu kanıt bir teste bağlanır** (§9). |
| 6 | aksi hâlde | **Dönüştürme (base)** | Katalog/ürün/arşiv görünümü. |

### Karar 4 — para bağlamı allowlist'i

- `defined( 'WOOCOMMERCE_CHECKOUT' )` veya `defined( 'WOOCOMMERCE_CART' )`
- `wc-ajax` parametresi şu allowlist'te: `checkout`, `update_order_review`, `add_to_cart`, `apply_coupon`, `remove_coupon`, `update_shipping_method`, `get_refreshed_fragments`, `get_variation`
  → `add_to_cart` özellikle gerekli: kendi kodumuz orada `calculate_totals()` çağırıyor ([CartFilter.php:78](../../../src/Integration/WooCommerce/CartFilter.php))
- **Store API:** `WC()->is_rest_api_request()` **ve** istenen route `/wc/store` ile başlıyor.
  ⚠ `WC()->cart` varlığı ayırt edici **değildir** — normal frontend isteklerinde de vardır.
- Conditional tag'ler kurulmuşsa (`did_action('wp')`): `is_cart() || is_checkout() || is_account_page()`
- **Kendi convert endpoint'imiz** — `ConversionContext::force_convert()` ile açıkça işaretlenir. (v1'de eksikti: §3'ün kuralına harfiyen uyulsa endpoint ne cart ne checkout ne Store API olduğu için base dönerdi = özellik no-op.)
- Kaçış kapısı: `apply_filters( 'mhmcs_should_convert', bool $decision, string $reason )` — üçüncü-parti/edge durumlar için.

### Hata modu sıralaması

- **Yanlış tahsilat (en tehlikeli):** Store API tespiti kaçarsa blocks-checkout base tutarla sipariş keser. Klasik checkout `WOOCOMMERCE_CHECKOUT` sabiti sayesinde güvende.
- **Yanlış gösterim + session tutarsızlığı:** `wc-ajax` ailesi kaçarsa. Kritik kombinasyon: `CouponFilter::convert_coupon_amount` ([CouponFilter.php:90-104](../../../src/Integration/WooCommerce/CouponFilter.php)) bağlama değil yalnız detection'a bakar → EUR'a çevrilmiş kupon, base bırakılmış kalemlerden düşülür. **`CouponFilter` ve `ShippingFilter` de `ConversionContext`'e bağlanır.**
- **Cache kırılması (en zararsız):** fazladan dönüşüm.

**İlke:** belirsizlikte **dönüştür** tarafına yanıl.

## 4. Bileşenler

| Bileşen | Tip | Sorumluluk |
|---|---|---|
| `src/Core/ConversionContext.php` | **yeni** | §3'teki tek karar. Enjekte edilebilir (test edilebilirlik için seam). |
| `PriceFilter.php` | değişir | `convert_price`/`convert_sale_price`/`convert_variation_price` → `ConversionContext`'e sorar. |
| **`FormatFilter.php`** | **değişir (v1'de yoktu)** | 6 format filtresinin tamamı aynı bağlam kararını kullanır. Dönüştürmüyorsak sembol/kod/ayraç **base kalır**. |
| **`PriceFilter::add_currency_to_hash`** | **değişir (v1'de yoktu)** | Variation transient hash'i bağlamı kodlar: dönüştürmüyorsak `<base>:display`, dönüştürüyorsak hedef kod. Aksi hâlde display bağlamı base değerleri "EUR" hash'ine yazar, endpoint aynı hash'ten base okur → variable ürünler kalıcı olarak no-op (v1'in B2'si). |
| `CouponFilter.php`, `ShippingFilter.php` | değişir | Aynı bağlam kararına bağlanır. |
| `src/Frontend/PriceDisplayMarker.php` | yeni | Dönüştürmediğimiz bağlamda `woocommerce_get_price_html` çıktısını `<span class="mhmcs-price" data-mhmcs-product="ID">…base price_html…</span>` ile sarar. |
| `src/Rest/ConvertController.php` | yeni | `POST mhmcs/v1/convert`. Güvenlik: §6. (v1 bunu `src/Admin/` altına koyuyordu — public frontend endpoint'i Admin namespace'ine ait değil.) |
| `assets/js/price-converter.js` | yeni | Marker toplama → tek batch REST → HTML yazma. Tespit zinciri: §5. |
| `assets/js/switcher.js` | değişir | `reload()` yerine cookie set + `mhmcs:currency-changed` event. Ayrıca **load'ta** aktif-para göstergesini cookie'den senkronlar. |
| `src/Frontend/Switcher.php`, `Elementor/SwitcherWidget.php`, `NavMenu` | değişir | Cache modunda **nötr** render (`data-current` / `mhm-cs-active` ön-seçili basılmaz) — aksi hâlde cache'li sayfada switcher hep base'i seçili gösterir. |
| `DetectionService.php` | değişir | `set_request_override( string $code )` (istek-kapsamlı, cookie yazmaz) + `sanitize_currency_code()` private→paylaşılabilir. |
| Ayarlar (`RestAPI` sanitizer + `admin-app/src/components/tabs/AdvancedSettings.jsx`) | değişir | `cache_compat` **gerçek** ayar (varsayılan `true`). Sanitizer whitelist'ine eklenmezse sessizce düşer. |

**Kapsam dışı bırakıldı:** `ProductWidget`. v1 onu marker'lamak istiyordu, ama [ProductWidget.php:152-172](../../../src/Frontend/ProductWidget.php) **ziyaretçiden bağımsız çoklu-para listesi** basıyor (tüm seçili kurlar, bayraklarla) ve ham `_price` meta okuyor → zaten cache-safe. Marker+innerHTML replace bu widget'ı **bozar**.

## 5. Veri akışı + tespit zinciri (v1'in B4'ü)

```
[Cache'li sayfa: base HTML + <span data-mhmcs-product=42>$10.00</span>]
   ↓ DOMContentLoaded
price-converter.js tespit sırası:
   1. ?currency= URL param  → varsa kullan, cookie'ye YAZMA (server davranışıyla parite: URL param yapışkan değil)
   2. mhmcs_currency cookie → varsa kullan
   3. ikisi de yok + auto_detect açık → currency:null gönder, sunucu geolocation ile çözsün
   ↓ marker'ları topla → uniq product_ids
   ↓ POST mhmcs/v1/convert { currency: "EUR" | null, product_ids: [...] }
ConvertController: force_convert() + set_request_override(çözülen para)
   → her ID için $product->get_price_html()
   ↓ { currency: "EUR", prices: { 42: "<span…>€9,20</span>", … } }
price-converter.js: HTML'i yaz, opacity 0→1; currency null gönderilmişse dönen değeri cookie'ye yaz
   ↓
[Switcher değişimi] → cookie set → mhmcs:currency-changed → converter yeniden koşar (reload YOK)
[Sepet/ödeme]      → ConversionContext "dönüştür" der → gerçek tutar server-side (uncached)
```

**Tek gidiş-dönüş:** `currency: null` "sen tespit et" demek olduğu için ayrı bir detect endpoint'i gerekmez. Yanıt IP'ye göre değiştiğinden `Cache-Control: no-store` zorunlu.

**Bugünkü yan bug:** geolocation cookie'si fiyat filtreleme sırasında, header'lar gönderilmiş olabilecekken `setcookie` ile yazılıyor ([DetectionService.php:247](../../../src/Core/DetectionService.php) → `:185-199`) — sessizce düşebilir. Cache modunda cookie'yi **JS** yazar; server-side yol için de yazım `template_redirect` öncesine alınır (§8.3).

## 6. `POST mhmcs/v1/convert` güvenliği

- `register_rest_route` **`args` şeması** (`validate_callback`/`sanitize_callback`) — mevcut konvansiyon elle sanitize ([RestAPI.php:96-165](../../../src/Admin/RestAPI.php)), reviewer'lar şemayı tercih eder.
- `product_ids`: en fazla **50** (v1 100 diyordu; her ID `get_price_html()` → variable üründe tüm varyasyonların yüklenmesi). Aşan → `400`.
- Her ID `absint`; **post type `product`** + **`publish`** + **`post_password_required()` false**. Şifre korumalı üründe fiyat sayfada gizliyken endpoint'ten sızmamalı.
- `currency`: strict `^[A-Z]{3}$` + enabled-currencies allowlist; geçersizse base (no-op). `null` = "tespit et".
- Yanıt yalnız `price_html` + çözülen `currency` — sayfada zaten public olan veri.
- **`Cache-Control: no-store`** (IP'ye göre değişir; ayrıca POST'u cache'leyen edge yapılandırmalarına karşı).
- **Nonce yok, `permission_callback => '__return_true'`** — public read-only için WP.org'ca kabul edilir; repoda emsali var ([RestAPI.php:156-164](../../../src/Admin/RestAPI.php) `/rates`). WP.org'un gerçek şartı: callback'in açıkça set edilmesi + girdi doğrulama + veri sızıntısı olmaması. Login'li kullanıcı zaten bu yolu hiç kullanmıyor (karar 6), dolayısıyla "misafir bağlamı" bir kusur değil, tasarım.

## 7. Kapsam

**İçinde:** katalog/mağaza/arşiv/ürün/ilgili-ürünler/öne-çıkanlar görünüm fiyatları · Elementor price widget · switcher no-reload + load-time UI senkronu · `cache_compat` toggle · basit/indirimli/aralık/varyasyon `price_html` şekilleri · varyasyon seçildiğinde (`found_variation`) güncelleme · §8'deki 3 mevcut bug.

**Dışında (bilinçli):** sepet/ödeme/sipariş totalleri (server-side) · `ProductWidget` (§4) · login'li kullanıcı (server-side'a düşer) · Cloudflare edge özel config · kişiselleştirilmiş/rol-bazlı fiyat.

**Mini-cart:** her sayfada render edildiği için istek-bazlı sınıflandırılamaz. Cache modunda base render edilir; doğruluğu WC'nin `get_refreshed_fragments` AJAX'ına yaslanır (allowlist'te → dönüşür). **Fragments kapalıysa** (yaygın perf önerisi) cache'lenmiş mini-cart base kalır → tespit edilip **admin uyarısı** basılır + readme'de belgelenir.

## 8. Denetimin ortaya çıkardığı, bugün v1.0.0'da CANLI olan buglar (v1.1 kapsamında)

1. **Admin'de fiyat dönüşümü.** `PriceFilter::init()` koşulsuz kayıtlı ([Plugin.php:117-118](../../../src/Plugin.php)); `FormatFilter`'ın aksine `is_admin()` guard'ı yok → adminin tarayıcısında `mhmcs_currency` cookie'si varsa **sipariş oluşturma ekranında ürün fiyatı dönüşür** (üstelik sembol base kalır). `ConversionContext` karar 1 bunu kapatır.
2. **İki ayar sessizce düşüyor.** `multilingual_mapping` ve `provider_api_key` JSX'te var ([AdvancedSettings.jsx:33, :166](../../../admin-app/src/components/tabs/AdvancedSettings.jsx)), `src/` içinde **sıfır sonuç** → `save_settings` whitelist sanitizer'ı bilinmeyen key'i atıyor. `cache_compat` eklenirken aynı tuzağa düşmemek için üçü birlikte düzeltilir.
3. **Geolocation cookie'si geç yazılıyor** (§5).

## 9. Test

**A. `ConversionContext` — saf unit (yeni seam sayesinde mümkün):** 6 karar dalının her biri · para-bağlamı allowlist'inin her üyesi · `mhmcs_should_convert` filtresinin kararı ezmesi · istek başına bir kez hesaplanması.

**B. WC entegrasyon suite'i (yeni altyapı — ayrı task).** `tests/bootstrap.php` bugün saf stub ([:41-424](../../../tests/bootstrap.php)); WP entegrasyon yolu ([:428-454](../../../tests/bootstrap.php)) koşullu ve `tests/Integration/` **boş** — hiç koşulmamış. Şunlar yalnız gerçek WC ile test edilebilir:
- 🔴 **Zamanlama:** `wp_loaded` sepet-session doğrulaması `wp`'den önce fiyat okur → karar 5 doğru mu? (Asıl risk bu; stub'la test edilemez.)
- `price_html` şekilleri: basit/indirimli/aralık/varyasyon/sabit-fiyat.
- **Variation transient:** display bağlamı base yazdıktan sonra endpoint'in doğru dönüştürülmüş aralığı döndürmesi (B2 regresyon kilidi).
- Store API bağlamının gerçekten yakalanması.

**C. Endpoint (entegrasyon):** 50 sınırı · geçersiz/draft/şifreli/ürün-olmayan ID atlanıyor · geçersiz currency → base · `currency:null` → geolocation · yanıt yalnız `price_html`+`currency` · `no-store` başlığı.

**D. Ayarlar (saf unit, mevcut kalıp):** `cache_compat` sanitize + varsayılan `true` · §8.2'nin iki key'i artık düşmüyor.

**E. Tarayıcı (Chrome DevTools MCP):** cache simülasyonu (base HTML → JS convert) · switcher değişiminde **reload YOK** · katalogda EUR ↔ sepette EUR tutarlılığı · mod KAPALI'da marker/JS yok, eski davranış birebir · login'li kullanıcıda marker yok · konsol temiz.

## 10. Belgelenecek kabul edilmiş sınırlar (readme)

- **Yapılandırılmış veri:** WC ürün şemasını `wc_get_price_to_display()` + `get_woocommerce_currency()` ile basar. Cache modunda şema **base'e sabitlenir** (`woocommerce_structured_data_product` filtresi) — görünen fiyat EUR iken şema base der. Google Merchant "fiyat uyuşmazlığı" sınıfı uyarı verebilir; bu yaklaşımın kabul edilen bedeli, readme'de açıkça yazılır.
- **SEO:** botlar base fiyatı görür.
- **JS kapalı/REST hatası:** base görünür, `console.debug`, kullanıcıya hata yok. Graceful.
- **Flash:** base görünür → `opacity 0→1`; `prefers-reduced-motion`'a saygı.
- **`?currency=` cache-key patlaması:** cache eklentileri query-string'i ayrı anahtar yapar.
- **Mini-cart + fragments** (§7).

## 11. İlişkili
- [[project_currency_switcher]] — Pro yol haritasında bu #1 idi; artık ücretsiz-çekirdek kalitesi.
- [[feedback_carve_shipping_surface_blind_spots]] · [[feedback_gate_suppression_blindspot]]
- [[feedback_pre_zip_fable_gate]] — bu spec Fable-denetiminden geçti (v1 NO-GO → v2 re-denetim); kod ayrıca ZIP-öncesi zorunlu Fable gate'ine girecek.
- [[feedback_audit_the_class_not_the_report]] — v1 denetimi 3 mevcut bug'ı yan ürün olarak çıkardı (§8): bulgu örneklemdir, sınıfı tara.
