# MHM Currency Switcher — WordPress.org Ücretsiz Sürüm Tasarımı

**Tarih:** 2026-07-22
**Durum:** Onaylandı (kullanıcı), uygulama planı bekliyor
**Hedef sürüm:** v1.0.0
**Kaynak dersler:** `wp-knowledge/standards/wporg-submission-guidelines.md` · MHM Rentiva'nın 3 WP.org reddi (T1/T2 Mayıs 2026, T4 19 Temmuz 2026)

---

## 1. Amaç ve arka plan

`mhm-currency-switcher` WordPress.org eklenti dizinine gönderilebilir hale getirilecek.

Eklenti şu anda **trialware** mimarisi taşıyor: kod pakette bulunuyor ama lisans olmadan çalışmıyor. WordPress.org Guideline 5 bunu açıkça yasaklar:

> *"Plugins may not contain functionality that is restricted or locked, only to be made available by payment or upgrade. Functionality may not be disabled after a trial period or quota is met."*

Bu, MHM Rentiva'yı üç kez reddettiren mimarinin aynısıdır — hatta daha açığıdır, çünkü CS bir **kota** da uygular (`Mode::get_currency_limit()` → Lite 2, Pro sınırsız) ve guideline metni "quota is met" ifadesini birebir içerir.

### Stratejik karar: tek ücretsiz eklenti (Pro hattı yok)

İki seçenek değerlendirildi:

- **A — Seam inversion:** Rentiva'nın izlediği yol. Ücretsiz eklenti Pro kodunu hiç içermez, nötr uzantı noktaları açar; ayrı bir Pro eklentisi bu noktalara kaydolur.
- **B — Tek paket, kilit yok:** Lisans alt sistemi ve kota silinir, mevcut 6 özellik herkese açılır.

**B seçildi.** Gerekçeler:

1. **Gelir yok.** CS'in ödeme yapan müşterisi yok; tek kurulum sahibin kendi mağazası (maxhandmade.com). A'nın tek gerekçesi gelir korumaktı.
2. **Kalan Pro seti savunulabilir değil.** Kota her hâlükârda silinecek. Geriye kalanlardan **otomatik kur güncelleme** ve **geolocation** rakiplerin ücretsiz sürümlerinde standart (FOX 5 dakikaya kadar, CURCY 30dk–1ay, X-Currency benzeri). Bunları kilitli tutan bir ücretsiz eklenti WP.org'da rakiplerin gerisinde başlar — ki oraya gitme sebebimiz görünürlüktür.
3. **Bakım maliyeti ölçülmüş bir risk.** Rentiva'da Lite 5.1.0 + Pro 5.0.x uyumsuzluğu canlı siteyi beyaz ekrana düşürdü ("önce Pro, sonra Lite" kuralı o olaydan çıktı). Sıfır gelirli bir üründe bu riski her release'de almanın karşılığı yok.
4. **Yan fayda:** maxhandmade.com koordineli upgrade gerektirmez; sadece her şeyi ücretsiz alır.

**B'nin kabul edilen bedeli:** bugün var olan bu 6 özellik bir daha ücretli yapılamaz (GPL ile yayınlandıktan sonra geri alınamaz). İleride bir Pro istenirse **yeni** özelliklerle kurulur — esirgenmiş özelliklerle değil. Nötr `apply_filters`/`do_action` uzantı noktaları crippleware sayılmadığı için bu kapı açık kalır.

---

## 2. Yöntem — bu bir *carve* değil, *silme*

`feedback_carve_blacklist_vs_whitelist` dersi (monolitten sökme yerine sıfırdan toplama) **eklentiyi ikiye bölerken** geçerlidir: kara listenin bittiği kanıtlanamaz ve hata modu sessizdir.

Burada ikiye bölmüyoruz. Sınırlı, tanımlı ve küçük bir alt sistemi kaldırıyoruz. Bu yüzden kanıt bedava ve makinece doğrulanabilir:

```bash
grep -rn "License\\\\|Mode::" src/     # beklenen: 0 sonuç
```

Buna ek olarak **PHPStan level 0, baseline'sız** ölü referansı yakalar — Rentiva carve'ında fatal hataların doğrudan panzehiri buydu ve grep'in göremediğini görür (grep metin arar, kodu anlamaz).

Yüzey küçük. Koddan sayıldı — toplam **9 gerçek gate çağrısı**:

| Dosya | Satır | Çağrı |
|---|---|---|
| `src/Plugin.php` | 120 | `Mode::can_use_geolocation()` |
| `src/Plugin.php` | 148 | `Mode::can_use_rest_api_filter()` |
| `src/Plugin.php` | 211 | `Mode::can_use_auto_rate_update()` |
| `src/Plugin.php` | 252 | `Mode::is_pro() && MhmRentiva::is_active()` |
| `src/Admin/RestAPI.php` | 252–253 | `Mode::is_pro()` (settings payload `is_pro`) |
| `src/Admin/RestAPI.php` | 344 | `Mode::can_use_auto_rate_update()` |
| `src/Admin/RestAPI.php` | 407–408 | `Mode::is_lite()` → `enforce_limit()` |
| `src/Admin/Settings.php` | 253 | `Mode::is_pro()` (localize `isPro`) |
| `src/Integration/WooCommerce/ProductPricing.php` | 61 | `Mode::can_use_fixed_prices()` |
| `src/CLI/Commands.php` | 229 | `Mode::is_pro()` (WP-CLI "Pro/Lite" etiketi) |

---

## 3. Silinecekler

### 3.1 Lisans alt sistemi
`src/License/` dizininin **tamamı — 7 dosya**:
`LicenseManager.php` · `Mode.php` · `ClientSecrets.php` · `ResponseVerifier.php` · `FeatureTokenVerifier.php` · `LicenseServerPublicKey.php` · `VerifyEndpoint.php`

### 3.2 REST yüzeyi
- `mhm-currency/v1` altındaki `license/*` rotaları (durum, aktivasyon, deaktivasyon, yeniden doğrulama, `license/manage-subscription`)
- `mhm-currency-switcher-verify/v1/ping` ters-doğrulama endpoint'i

### 3.3 Kota zorlaması
- `Mode::get_currency_limit()`
- `RestAPI.php:408` → `$this->store->enforce_limit( $currencies )` sunucu-taraflı zorlaması
- `CurrencyStore::enforce_limit()`

### 3.4 Kaçış kapıları ve yapılandırma sabitleri
- `MHM_CS_DEV_PRO` (geliştirici bypass'ı — Rentiva'da da sökülmüştü; `.dev`/`.localhost` gibi host tabanlı bypass'lar üretim sitelerinde kazara Pro açıyordu)
- `MHM_CS_LICENSE_RESPONSE_HMAC_SECRET`, `MHM_CS_LICENSE_FEATURE_TOKEN_KEY`, `MHM_CS_LICENSE_PING_SECRET`

### 3.5 Arayüz (React admin)
- `admin-app/src/components/shared/ProGate.jsx`
- `admin-app/src/components/tabs/License.jsx` → **sekme sayısı 5 → 4**
- `App.jsx`, `ManageCurrencies.jsx`, `AdvancedSettings.jsx`, `CheckoutOptions.jsx` içindeki Pro referansları ve kilit durumları
- REST settings payload'ındaki `is_pro` ve `wp_localize_script`'teki `isPro`

### 3.6 Diğer
- Transient `mhm_cs_license_visit_throttle`
- Lisansa dokunan testler (13 test dosyasında referans var)
- `WP-CLI` çıktısındaki "Pro/Lite" mod etiketi

---

## 4. Açılacaklar

§2'deki 9 gate çağrısı koşulsuz hale gelir. Sonuç: aşağıdaki altı özellik **herkese açık**:

1. Geolocation tabanlı para birimi algılama (CloudFlare `CF-IPCountry` → WC MaxMind kademeli)
2. Ürün başına sabit fiyat (`_mhmcs_fixed_prices`)
3. Ödeme yöntemi kısıtı (para birimine göre)
4. Otomatik kur güncelleme (WP Cron)
5. Çoklu dil para birimi adları
6. WooCommerce REST API para birimi filtresi

Ayrıca **para birimi sayısı sınırsız** olur (kota silindi) ve `MhmRentiva` uyumluluk modülü lisanstan bağımsız çalışır.

`Converter`'daki mevcut **`fee` (markup %) ve `rounding`** mantığı da ücretsiz sürümde kalır — rakiplerin çoğu bunları ücretli sürümde satar.

---

## 5. Prefix geçişi

WordPress.org'un prefix denetleyicisi prefix'i **ilk `_` karakterinde böler**: `mhm_cs_foo` → `mhm` (3 harf) → "too short" (minimum 4 harf). İç alt çizgisiz tek token gerekir.

**Karar: tam geçiş, migration YOK.**

| Tür | Eski | Yeni |
|---|---|---|
| PHP sabitleri | `MHM_CS_VERSION`, `MHM_CS_FILE`, … | `MHMCS_VERSION`, `MHMCS_FILE`, … |
| Option | `mhm_currency_switcher_currencies` | `mhmcs_currencies` |
| Option | `mhm_currency_switcher_settings` | `mhmcs_settings` |
| Sipariş/ürün meta | `_mhm_cs_currency_code` | `_mhmcs_currency_code` |
| Sipariş meta | `_mhm_cs_exchange_rate` | `_mhmcs_exchange_rate` |
| Sipariş meta | `_mhm_cs_base_currency` | `_mhmcs_base_currency` |
| Ürün meta | `_mhm_cs_fixed_prices` | `_mhmcs_fixed_prices` |
| Cookie | `mhm_cs_currency` | `mhmcs_currency` |
| REST namespace | `mhm-currency/v1` | `mhmcs/v1` |

**Değişmeyenler:** eklenti slug'ı ve textdomain `mhm-currency-switcher` olarak kalır → **i18n katalogları (`.pot`/`.po`/`.mo`/`.l10n.php`) etkilenmez**, klasör adı ve repo adı değişmez.

**Migration neden yok:** Bilinen tek kurulum maxhandmade.com. Eski anahtarlar veritabanından **silinmiyor** — yeni kod farklı anahtar okuduğu için görünmez oluyorlar. Sonuç: o sitede para birimi ayarları bir kez yeniden girilir (~5 dk); geçmiş siparişlerin çok-para-birimli meta'sı eklenti tarafından okunmaz hale gelir ama veritabanında durur ve ileride tek bir SQL `UPDATE` ile geri bağlanabilir. Geri dönülmez kayıp yoktur.

**Bu son fırsattır.** WP.org'a çıkıp gerçek kullanıcı toplandıktan sonra storage-key rename'i veri kaybı riski nedeniyle kalıcı olarak imkânsız hale gelir — Rentiva tam olarak bu yüzden `mhm_rentiva_` prefix'ine mahkûm kaldı ve 38 uyarıyı reviewer'a açıklamak zorunda kaldı.

---

## 6. WordPress.org uyum kalemleri

| # | Bulgu | Kanıt | Yapılacak |
|---|---|---|---|
| 1 | Lisans başlığı `GPL-3.0-or-later` | `mhm-currency-switcher.php:11`, `readme.txt:8` | **`GPLv2 or later`**'a çevir (header + readme + LICENSE dosyası). WP.org "GPLv2 or later ile uyumlu" ister; GPLv3 GPLv2 ile tek yönlü uyumsuzdur. Rentiva'da aynı sınıf hata (Apache-2.0) ancak bağımsız denetimde yakalanmıştı. |
| 2 | `Plugin URI`/`Author URI` = `maxhandmade.com` | plugin header | **`wpalemi.com`**'a çevir. WP.org profil e-postasının domain'i bu URI'larla aynı olmalı; "bir entity'nin tüm eklentileri tek hesap altında" kuralı gereği Rentiva ile aynı hesap kullanılacak. |
| 3 | `uninstall.php` yok | dizin listesi | Ekle: option'lar, `_mhmcs_*` meta'ları, transient'ler ve cookie temizliği. |
| 4 | Dış servisler belgesiz | `RateProvider.php:178`, `:197` | readme'ye `== External services ==` bölümü: **ExchangeRate-API** (`api.exchangerate-api.com`) ve **Fawaz Currency API** (`cdn.jsdelivr.net`) — ne veri gider, ne zaman, neden + ToS/Privacy linkleri. `wpalemi.com` license-server çağrısı §3 ile **tamamen ortadan kalkıyor**. |
| 5 | readme.txt trialware pazarlaması | `readme.txt:31–62` | "Pro Version" bölümü, "Free: 3 total — base + 2" limit iddiaları ve Free/Pro karşılaştırma SSS'i silinir. readme baştan yazılır. **Changelog'daki lisans/Pro girdileri budanır** — Rentiva'da 136 monolit changelog girdisi crippleware geçmişini admin ekranında sergiliyordu ve denetimde bulgu oldu. |
| 6 | 3 dosyada `phpcs:ignoreFile` | `Integration/Elementor/*.php` | Dosya geneli susturma kaldırılır; çıktılar `wp_kses_post()`/`esc_*` ile escape edilir, kaçınılmaz noktalar satır bazlı gerekçeli `phpcs:ignore` olur. |
| 7 | Sürüm | `0.7.1` | **`1.0.0`** — davranış kıran değişiklik (prefix + lisans kaldırma) ve ilk kamuya açık sürüm. |

### Zaten temiz olanlar (denetimde doğrulandı)
Inline `<script>`/`<style>` yok · i18n çağrıları literal string + literal domain (wrapper yok) · sanitize/nonce/escape doğru · HPOS uyumluluğu doğru beyan edilmiş · bundle edilmiş 3. parti JS yok · **hiç AJAX action yok** (her şey REST) → playbook'taki "common-word AJAX action" tuzağı bu eklentide mevcut değil · `mhm-plugin-updater` bundle **edilmiyor** (WP.org'da self-updater yasak — doğrulandı, temiz).

---

## 7. Kapılar (kanıt — hepsi yeşil olmadan gönderim yok)

| Kapı | Eşik |
|---|---|
| Değişmez taraması | `grep -rn "License\\\\|Mode::" src/` → **0** |
| PHPStan | **Level 0 baseline'sız temiz** (ölü referans avı) + mevcut L6 kapısı |
| PHPCS | 0 hata |
| PHPUnit | Tamamı yeşil (lisans testleri silindikten sonraki yeni taban kaydedilir) |
| **Plugin Check** | **ZIP üzerinde** (dev dizininde **değil**) → **0 ERROR**. Dev dizini `bin/`, `tests/` içerdiği için sahte `application_detected`/`compressed_files` üretir. |
| `WP_DEBUG` | Açıkken **sıfır** notice/warning/deprecation |
| Tarayıcı | 4 admin sekmesi + ön yüz switcher + checkout akışı; konsol ve network temiz |
| **ZIP öncesi Fable gate** | Bağımsız denetim; `standards/wporg-submission-guidelines.md` + `official/CONTROL-RUBRIC.md` fiilen beslenir. 🔴 blokaj → ZIP durur. Sorun bulunmayana kadar tekrarlanır. |
| CI | Push sonrası GitHub Actions tüm job'lar **success** (`gh run view --json conclusion` ile teyit) |

### Gönderim zamanlaması
Tüm iş şimdi tamamlanır, ancak **WordPress.org'a gönderim Rentiva'nın 3. başvurusunun sonucu gelene kadar bekletilir.** Gerekçe: Rentiva'nın cevabı yeni bulgularla dönerse, o bulgular aynı hesap altındaki bu eklentiye de birebir uygulanır ve gönderimden önce içeri katılabilir. İki eklentiyi paralel gönderip iki ayrı red almak hesap itibarı açısından en pahalı senaryodur.

---

## 8. Kapsam dışı (bilinçli olarak ertelenen)

Kapsam şişmesi Rentiva'nın carve'ını aylara yaydı. Aşağıdakiler bu turun **dışındadır**:

- **Cache eklentisi uyumluluğu** (WP Rocket / LiteSpeed / Cloudflare cookie kaydı + cache bypass). Araştırmada WP.org'da en çok düşük puan getiren hata sınıfı olarak öne çıktı; gerçek bir test ortamı gerektirdiği için ayrı tur. **Yüksek öncelikli backlog.**
- **Admin arayüzü yeniden tasarımı** — Claude Design (`DesignSync`) ile. Ayrı faz, bu turdan **sonra**: mevcut carve arayüzü yapısal olarak değiştiriyor (License sekmesi kalkıyor, ProGate siliniyor), dolayısıyla önce nihai şekil oluşmalı. WP.org listeleme ekran görüntüleri bu fazdan sonra çekilir.
- **Gelecekteki Pro eklentisi fikirleri** (§9).
- **Ekosistem emekliliği:** license-server'daki `featuresFor('mhm-currency-switcher')` girdisi, Polar'daki CS Monthly/Yearly ürünleri, wpalemi'deki `/download/mhm-currency-switcher` ve fiyatlandırma sayfası içeriği. Eklenti yayınlandıktan sonra ayrı bir temizlik turu.

---

## 9. Gelecekteki Pro için pazar araştırması (park edilmiş)

Ücretsiz sürüm yayınlandıktan sonra bir Pro hattı istenirse, aşağıdakiler **yeni** özelliklerdir — bugün ücretsiz verilenlerin geri alınması değil. Araştırma 2026-07-22'de yapıldı.

**Rakiplerin ücretsiz sürümlerinde zaten olan (asla Pro olamaz):** otomatik kur güncelleme, geolocation, switcher widget'ı, temel dönüşüm.

**Rakiplerin ücretli sürümlerinde satılan klasik set:** sınırsız para birimi, sabit fiyat, ödeme kısıtı, kur markup'ı, yuvarlama. **Bunların tamamı bizde ücretsiz olacak** — konumlanma avantajı.

**Pazarın çözemediği, ödeme yapılan gerçek acılar:**

1. **🥇 Settlement-farkında ödeme yönlendirme.** Stripe 135+ para biriminde fiyat gösterir ama yalnızca ~40'ında hesaplaşır; PayPal 25 gösterir, 22'sinde hesaplaşır. Uyuşmazlıkta siparişler "on hold"a düşer, ödeme reddedilir, ya da müşteriden yanlış tutar tahsil edilir (CURCY Pro kullanıcıları 100 CAD'lik siparişin 100 USD olarak çekildiğini bildirdi ≈ %37 fazla tahsilat). Doğrudan para kaybettiren sorun = ödemeye en yatkın nokta.
2. **🥈 Çok para birimli muhasebe raporlaması.** WooCommerce'te para birimi kavramı yerel değil; raporlar farklı para birimlerindeki tutarları toplayıp veriyor, gerçek ciro için elle hesap gerekiyor. **Veri temeli bizde zaten mevcut** — `CartFilter.php:120-121` siparişe o anki kuru ve temel para birimini yazıyor, yani bu özellik ilk günden geçmiş veriyle çalışabilir.
3. **🥉 Fiyatlandırma kuralları.** Ülke/kullanıcı rolü bazlı fiyat listeleri, para birimi başına psikolojik yuvarlama, B2B/toptan.

**Ayıltıcı gerçek:** pazar fiyatı $30–49/yıl/tek site. CS tek başına ciddi bir gelir kalemi değil; asıl değer WP.org'un getireceği görünürlük ve kullanıcı tabanıdır.

---

## 10. İlişkili bilgi

- `wp-knowledge/standards/wporg-submission-guidelines.md` — tam playbook, her gönderimden önce okunur
- `feedback_wporg_review_lessons` — red derslerinin özeti
- `feedback_carve_blacklist_vs_whitelist` — neden bu iş *silme* olarak çerçevelendi
- `feedback_pre_zip_fable_gate` — ZIP öncesi bağımsız denetim kanunu
- `feedback_verify_ci_after_push` — push sonrası CI takibi kanunu
- `feedback_gates_blind_spots` — kapıların göremediği alanlar (JS/JSX, build çıktıları, readme, template'ler)
