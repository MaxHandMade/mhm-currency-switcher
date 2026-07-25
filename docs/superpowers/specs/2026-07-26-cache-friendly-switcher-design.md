# MHM Currency Switcher — Cache-Friendly Switcher Tasarımı (v1.1)

**Tarih:** 2026-07-26
**Durum:** Onaylandı (kullanıcı), Fable spec-denetimi bekliyor
**Hedef sürüm:** v1.1.0

---

## 1. Problem

Fiyatlar şu an **server-side** dönüşüyor: `PriceFilter`, `woocommerce_product_get_price` (+ regular/sale/variation, priority 100) filtresine bağlanıp tutarı seçili para birimine çevirir. WC'nin `price_html`'i bu dönüşmüş `get_price`'tan türer. Sayfa cache'lenince (WP Rocket / LiteSpeed / Cloudflare) bu dönüşmüş fiyat statik HTML'e gömülür → **ilk ziyaretçinin para birimi cache'lenir, ikinci ziyaretçi onu görür.** Pazar araştırması (Gemini + kendi): caching çakışması currency-switcher eklentilerinin **#1 kronik şikâyeti**; "önbellek dostu" en güçlü pazarlama kozu.

Ek olarak switcher şu an `window.location.reload()` yapıyor (yavaş + cache'li sayfayı yeniden servis eder = çözüm değil).

## 2. Yaklaşım (onaylanmış 4 karar)

1. **İstemci-taraflı AJAX dönüşüm.** Sayfa **temel para biriminde** cache'lenir; JS yüklenince görünüm fiyatlarını dönüştürür. Herhangi bir cache eklentisiyle config'siz çalışır (en evrensel).
2. **Dönüşüm sunucuda (REST).** JS ürün ID'lerini toplu gönderir; sunucu **dönüştürülmüş+biçimlenmiş `price_html`** döner — mevcut `Converter`/`FormatFilter`/`ProductPricing` (sabit fiyat) + WC'nin kendi `price_html` üretimini kullanır (basit/indirimli/aralık/varyasyon tüm şekilleri doğru). Tek doğruluk kaynağı; JS'te format/yuvarlama tekrarı YOK.
3. **Yüzey ayrımı (doğruluk zorunluluğu, tercih değil):** **görünüm fiyatları** (katalog/ürün/arşiv/widget/Elementor) = temel+marker+JS. **Sepet/ödeme/sipariş totalleri** (gerçek para) = server-side kalır (WC bunları zaten cache'lemez; müşterinin ödeyeceği tutar JS ile "sahte" değiştirilemez).
4. **Toggle "Cache compatibility mode", varsayılan AÇIK.** AÇIK: görünüm temel+JS. KAPALI: mevcut server-side görünüm dönüşümü (flash yok ama cache-safe değil).

## 3. 🔴 Ana teknik risk — `get_price` ikili kullanımı

`woocommerce_product_get_price` **hem görünümü hem sepeti** besler; `price_html` ondan türer. "Görünümde temel, sepette dönüştürülmüş" bu yüzden bağlam ayrımı ister.

**Önerilen mekanizma (plan hardening'e açık):** Mod AÇIK'ken `PriceFilter::convert_price()` bağlama bakar:
- **Dönüştür** (server-side) → `is_cart()` || `is_checkout()` || WC hesap/endpoint bağlamı || **WooCommerce Store API** (blocks cart/checkout REST) || cart-fragment AJAX. Yani müşterinin ödeyeceği tutarın hesaplandığı her yer.
- **Temel bırak** (dönüştürme) → katalog/ürün/arşiv görünümü. `price_html` bu bağlamda doğal olarak temel gelir; ona yalnız `data-mhmcs-product` marker'ı eklenir.

Yanlış ayrımın iki hata modu: (a) katalog dönüşürse cache kırılır; (b) sepet temel kalırsa **yanlış tahsilat**. **(b) çok daha tehlikeli** → belirsizlikte "dönüştür" tarafına yanıl (fazla dönüştürmek cache-safety'yi bozar ama yanlış para tahsil etmez). Store API bağlam tespiti (`WC()->cart` varlığı / `wc/store` route / `REST_REQUEST` + rest-route eşleşmesi) plan tarafından test-güdümlü sağlamlaştırılacak; kör nokta bir PHPUnit senaryosu olacak.

## 4. Bileşenler (izole, tek sorumluluk)

| Bileşen | Tip | Sorumluluk |
|---|---|---|
| `src/Integration/WooCommerce/PriceFilter.php` (değişir) | mevcut | Mod AÇIK'ken `convert_price`/`convert_sale_price`/variasyon: **bağlam kontrolü** — display'de temel, cart/checkout'ta dönüştür. Mod KAPALI'da mevcut davranış (her yerde dönüştür). |
| `src/Integration/WooCommerce/PriceDisplayMarker.php` (yeni) | yeni | Mod AÇIK'ken `woocommerce_get_price_html` (ve widget/Elementor render'ları) çıktısını `<span class="mhmcs-price" data-mhmcs-product="ID" data-mhmcs-context="...">…temel price_html…</span>` ile sarar. Yalnız display bağlamında çalışır. |
| `src/Admin/ConvertEndpoint.php` (yeni REST) | yeni | `POST mhmcs/v1/convert` — girdi `{currency, product_ids[]}`; çıktı `{ID: converted_price_html}`. Her ürün için conversion'ı zorlayıp `$product->get_price_html()` üretir. Abuse koruması: §6. |
| `assets/js/price-converter.js` (yeni) | yeni | Sayfadaki `[data-mhmcs-product]` marker'larını topla (tekilleştir) → tek batch REST → dönen HTML'i ilgili marker'lara yaz. Cookie'den geçerli para birimini okur; base ise no-op. `opacity` geçişiyle flash yumuşatma. |
| `assets/js/switcher.js` (değişir) | mevcut | `reload()` yerine: cookie set → `price-converter`'ı yeniden tetikle (custom event `mhmcs:currency-changed`) → switcher UI'daki aktif-para göstergesini güncelle. |
| Settings (React `AdvancedSettings.jsx` + `RestAPI` sanitizer) | değişir | `cache_compat` toggle'ını GERÇEK ayar olarak geri ekle (varsayılan `true`). B3'te kaldırılan ölü toggle'ın işlevsel hâli. |

## 5. Veri akışı

```
[Cache'li sayfa: temel-para HTML + <span data-mhmcs-product=42>$10.00</span>]
   ↓ DOMContentLoaded
price-converter.js: cookie mhmcs_currency = "EUR"? (base ise dur)
   ↓ marker'ları topla → uniq product_ids [42, 43, ...]
   ↓ POST mhmcs/v1/convert {currency:"EUR", product_ids:[42,43,...]}
ConvertEndpoint: her ID için conversion-context'i zorla → $product->get_price_html()
   ↓ {42:"<span…>€9,20</span>", 43:"…"}
price-converter.js: her [data-mhmcs-product=X] .innerHTML = dönen HTML; opacity 1
   ↓
[Switcher değişimi] → cookie=EUR set → mhmcs:currency-changed event → converter yeniden koşar (reload YOK)
[Sepet/ödeme] → server-side PriceFilter cookie'den EUR okur → gerçek tutar dönüştürülür (uncached)
```

## 6. Public REST endpoint güvenliği (Fable'ın bakacağı ikinci risk)

`POST mhmcs/v1/convert` public (`permission_callback: __return_true`) — fiyat verisi zaten public, ama batch abuse/DoS vektörü:
- **`product_ids` üst sınırı** (ör. 100/istek). Aşan kırpılır veya `400`.
- Her ID `absint`; geçersiz/var-olmayan ID atlanır (yanıtta yer almaz).
- `currency` `sanitize_currency_code()` (strict `^[A-Z]{3}$`) + enabled-currencies allowlist; geçersizse base döner (no-op).
- Yalnız **published product** fiyatı döner (`wc_get_product` + status kontrolü); draft/private sızmaz.
- Yanıt sadece `price_html` (zaten sayfada public görünen veri) — yeni bilgi ifşası yok.
- Nonce **gerekmez** (public read-only, state değiştirmez) — ama rate-limit/размер sınırı DoS'u sınırlar. (WP.org: public GET-benzeri read'de nonce beklenmez; §suppression-audit dersiyle tutarlı.)

## 7. Kapsam

**İçinde:** katalog/mağaza/arşiv/ürün/ilgili-ürünler/öne-çıkanlar görünüm fiyatları · ProductWidget + Elementor price widget · switcher no-reload · toggle (varsayılan AÇIK) · basit/indirimli/aralık/varyasyon price_html şekilleri.

**Dışında (bilinçli):** sepet/ödeme/sipariş/mini-cart totalleri (server-side kalır) · Cloudflare edge özel config (AJAX evrensel, gerekmez) · varyasyon *seçildiğinde* fiyat güncellemesi (WC'nin kendi variation AJAX'ı; `found_variation` event'ine converter bağlanır — küçük ek, plan değerlendirir) · logged-in/kişiselleştirilmiş fiyat (ayrı konu).

## 8. Hata yönetimi / degradasyon

- **JS kapalı/başarısız** → temel para birimi görünür (işlevsel; flash kalıcı olur). Graceful.
- **REST hata/timeout** → marker'lar temelde kalır, `console.debug` (kullanıcıya görünür hata yok).
- **SEO** → botlar temel fiyatı görür (bu yaklaşımın kabul edilen standardı; readme'de belirtilir).
- **Flash** → base görünür, dönüşünce `opacity 0→1` geçişi; prefers-reduced-motion'a saygı.
- **Mod KAPALI** → hiçbir marker/JS yok, mevcut server-side davranış birebir korunur (regresyon yok).

## 9. Test

**PHPUnit:**
- `ConvertEndpoint`: basit/indirimli/aralık/varyasyon/sabit-fiyat şekilleri doğru dönüşüyor · geçersiz currency → base · boş/aşırı-uzun product_ids listesi (sınır) · var-olmayan/draft ID atlanıyor · yalnız price_html döner.
- `PriceDisplayMarker`: mod AÇIK'ta marker sarıyor, KAPALI'da hiç dokunmuyor · yalnız display bağlamında.
- **🔴 Bağlam ayrımı (en kritik):** `convert_price` cart/checkout/Store-API bağlamında dönüşür, katalog display'de base döner — mock'lanmış WC bağlamlarıyla. Bu, ana riskin regresyon kilididir.
- Settings: `cache_compat` sanitize + varsayılan true.

**Tarayıcı (Chrome DevTools MCP):**
- Cache simülasyonu: temel-para HTML render → JS convert → doğru para birimi görünür, konsol temiz.
- Switcher değişimi: **reload YOK**, fiyatlar anında güncellenir.
- Sepet tutarlılığı: katalogda EUR göründükten sonra sepette de EUR (server-side).
- Mod KAPALI: eski davranış, flash yok, marker yok.
- (Varsa) gerçek WP Rocket/LiteSpeed ile: iki farklı cookie'li istek doğru para birimi.

## 10. İlişkili
- [[project_currency_switcher]] — Pro yol haritasında bu #1 idi; artık ücretsiz-çekirdek kalitesi.
- [[feedback_carve_shipping_surface_blind_spots]] · [[feedback_gate_suppression_blindspot]] (public endpoint + suppression disiplini)
- [[feedback_pre_zip_fable_gate]] — bu spec Fable-denetiminden geçecek + kod ZIP-öncesi zorunlu Fable gate'ine girecek.
