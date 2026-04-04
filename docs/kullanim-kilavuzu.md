# MHM Currency Switcher — Kullanım Kılavuzu

WooCommerce mağazanızda birden fazla para birimini desteklemenizi sağlayan kapsamlı bir döviz çevirici eklentisi.

---

## İçindekiler

1. [Kurulum ve İlk Yapılandırma](#1-kurulum-ve-ilk-yapılandırma)
2. [Para Birimlerini Yönet (Manage Currencies)](#2-para-birimlerini-yönet)
3. [Görüntüleme Seçenekleri (Display Options)](#3-görüntüleme-seçenekleri)
4. [Ödeme Seçenekleri (Checkout Options)](#4-ödeme-seçenekleri--pro)
5. [Gelişmiş Ayarlar (Advanced)](#5-gelişmiş-ayarlar--pro)
6. [Lisans Yönetimi (License)](#6-lisans-yönetimi)
7. [Kısa Kodlar (Shortcodes)](#7-kısa-kodlar)
8. [Navigasyon Menüsü Entegrasyonu](#8-navigasyon-menüsü-entegrasyonu)
9. [Elementor Widget'ları](#9-elementor-widgetları)
10. [WP-CLI Komutları](#10-wp-cli-komutları)
11. [Para Birimi Algılama Mekanizması](#11-para-birimi-algılama-mekanizması)
12. [Döviz Kuru Dönüştürme Mantığı](#12-döviz-kuru-dönüştürme-mantığı)
13. [CSS ile Özelleştirme](#13-css-ile-özelleştirme)
14. [REST API Referansı](#14-rest-api-referansı)
15. [Lite ve Pro Karşılaştırması](#15-lite-ve-pro-karşılaştırması)
16. [Sık Sorulan Sorular](#16-sık-sorulan-sorular)

---

## 1. Kurulum ve İlk Yapılandırma

### Gereksinimler

| Gereksinim | Minimum Sürüm |
|-----------|---------------|
| WordPress | 6.0 |
| WooCommerce | 7.0 |
| PHP | 7.4 |

### Kurulum Adımları

1. `mhm-currency-switcher` klasörünü `/wp-content/plugins/` dizinine yükleyin
2. **Eklentiler** menüsünden eklentiyi etkinleştirin
3. WooCommerce'in yüklü ve aktif olduğundan emin olun
4. **WooCommerce > MHM Para Birimi** menüsüne gidin

### İlk Kurulumda Yapılacaklar

Eklenti etkinleştirildiğinde otomatik olarak:
- Varsayılan para birimleri oluşturulur (USD, EUR, GBP, TRY)
- Kur sağlayıcı ExchangeRate-API olarak ayarlanır
- Önbellek süresi 1 saat olarak belirlenir

> **Not:** Ana para biriminiz (base currency) WooCommerce ayarlarından okunur. Değiştirmek için: **WooCommerce > Ayarlar > Genel > Para birimi seçenekleri** bölümüne gidin.

---

## 2. Para Birimlerini Yönet

**WooCommerce > MHM Para Birimi > Manage Currencies** sekmesi

Bu sekme, mağazanızda kullanılacak para birimlerini yapılandırdığınız ana ekrandır.

### Para Birimi Tablosu

Her para birimi için aşağıdaki alanlar bulunur:

| Sütun | Açıklama |
|-------|----------|
| **Etkin** | Para birimini açıp kapatır. Kapalı birimler switcher'da görünmez. |
| **Kod** | ISO 4217 para birimi kodu (USD, EUR, GBP, TRY vb.) |
| **Kur** | Döviz kuru değeri |
| **Komisyon** | Kura uygulanacak ek komisyon |
| **Sıra** | Switcher'daki görüntüleme sırası |
| **İşlemler** | Silme düğmesi |

### Kur Türleri

- **Otomatik (Auto):** Döviz kuru API'den çekilir. Kur alanı düzenlenemez.
- **Manuel (Manual):** Kuru kendiniz girersiniz. 6 ondalık basamağa kadar destekler.

### Komisyon Türleri

Döviz kuruna ek komisyon uygulamak için üç seçenek:

| Tür | Açıklama | Örnek |
|-----|----------|-------|
| **Yok (None)** | Komisyon uygulanmaz | Ham kur kullanılır |
| **Yüzde (Percent)** | Kura yüzde olarak eklenir | Kur: 0.92, %2.5 komisyon = 0.92 x 1.025 = 0.943 |
| **Sabit (Fixed)** | Kura sabit değer eklenir | Kur: 0.92, 0.03 sabit komisyon = 0.92 + 0.03 = 0.95 |

### Yeni Para Birimi Ekleme

1. **+ Yeni Para Birimi** düğmesine tıklayın
2. Açılır listeden istediğiniz para birimini seçin
3. **Ekle** düğmesine tıklayın
4. Kur türünü ve komisyonu ayarlayın
5. **Değişiklikleri Kaydet** düğmesine tıklayın

> **Lite Sınırlaması:** Ücretsiz sürümde en fazla 2 ek para birimi ekleyebilirsiniz (ana para birimi + 2). Pro sürümde sınırsız para birimi desteklenir.

### Kurları Senkronize Etme

**Kurları Senkronize Et** düğmesine tıklayarak tüm otomatik kurları anlık olarak güncelleyebilirsiniz. Bu işlem, yapılandırılmış kur sağlayıcısından (varsayılan: ExchangeRate-API) güncel kurları çeker.

---

## 3. Görüntüleme Seçenekleri

**WooCommerce > MHM Para Birimi > Display Options** sekmesi

Bu sekme, switcher'ın ve fiyat bileşeninin görünümünü kontrol eder.

### Dönüştürücü Görünümü (Switcher Appearance)

| Ayar | Açıklama | Varsayılan |
|------|----------|-----------|
| **Bayrak simgesi göster** | Para biriminin yanında ülke bayrağı gösterir | Açık |
| **Para birimi adını göster** | Tam para birimi adını gösterir (örn. "ABD Doları") | Açık |
| **Para birimi simgesi göster** | Para birimi simgesini gösterir (örn. "$", "€") | Açık |
| **Para birimi kodunu göster** | ISO kodunu gösterir (örn. "USD", "EUR") | Açık |

### Dönüştürücü Boyutu

Üç boyut seçeneği:
- **Küçük (Small):** 12px yazı boyutu
- **Orta (Medium):** 14px yazı boyutu (varsayılan)
- **Büyük (Large):** 16px yazı boyutu

### Ürün Fiyat Bileşeni (Product Price Widget)

Ürün sayfalarında ana fiyatın altında birden fazla para biriminde fiyat gösteren bileşen.

| Ayar | Açıklama |
|------|----------|
| **Bileşeni etkinleştir** | Ürün sayfasındaki fiyat bileşenini açar/kapatır |
| **Gösterilecek para birimleri** | En fazla 5 para birimi seçilebilir |
| **Bayrakları göster** | Para birimlerinin yanında bayrak simgesi gösterir |

**Örnek Görünüm:**

```
Ürün Fiyatı: ₺500,00

🇪🇺 €14,17 | 🇺🇸 $15,50 | 🇬🇧 £12,30
```

### Canlı Önizleme

Sayfa altında, yaptığınız ayarlara göre anlık bir önizleme gösterilir. Bu basitleştirilmiş bir önizlemedir — gerçek görünüm temanıza göre değişebilir.

---

## 4. Ödeme Seçenekleri (Pro)

**WooCommerce > MHM Para Birimi > Checkout Options** sekmesi

> Bu sekme yalnızca Pro lisans sahiplerine açıktır.

### Ödeme Yöntemi Kısıtlamaları

Her para birimi için hangi ödeme yöntemlerinin kullanılabileceğini belirleyebilirsiniz.

**Kullanım Senaryoları:**
- EUR ile yalnızca banka havalesi kabul etmek
- USD ile yalnızca Stripe ve PayPal'ı aktif tutmak
- GBP için belirli ödeme yöntemlerini devre dışı bırakmak

**Yapılandırma:**

1. Her para birimi satırında **İzin Verilen Ödeme Yöntemleri** alanını görürsünüz
2. Varsayılan: **Tüm ödeme yöntemleri** (hiçbir kısıtlama yok)
3. Belirli yöntemler seçerek kısıtlama uygulayabilirsiniz

---

## 5. Gelişmiş Ayarlar (Pro)

**WooCommerce > MHM Para Birimi > Advanced** sekmesi

> Bu sekme yalnızca Pro lisans sahiplerine açıktır.

### Konum Algılama (Geolocation Detection)

Ziyaretçinin ülkesine göre otomatik para birimi atanması.

| Ayar | Açıklama |
|------|----------|
| **Konum algılamayı etkinleştir** | Ziyaretçi ülkesini otomatik tespit eder |
| **Konum sağlayıcı** | CloudFlare + WooCommerce MaxMind kademeli algılama |

**Kademeli Algılama (Cascade):**

Eklenti, ülke tespiti için iki sağlayıcıyı kademeli olarak kullanır:

1. **CloudFlare (Birincil):** Siteniz CloudFlare arkasındaysa `CF-IPCountry` header'ından ülke kodu okunur. Ekstra yapılandırma gerekmez, ücretsizdir ve çok hızlıdır.
2. **WooCommerce MaxMind (Yedek):** CloudFlare bulunamazsa WooCommerce'in yerleşik MaxMind GeoLite2 veritabanı kullanılır. **WooCommerce > Ayarlar > Entegrasyon > MaxMind** bölümünden lisans anahtarı girilmelidir.

**Nasıl Çalışır:**
1. Ziyaretçi siteye ilk geldiğinde (çerez yoksa) konum algılama devreye girer
2. Önce CloudFlare header kontrol edilir, yoksa MaxMind IP veritabanı sorgulanır
3. Ülke kodu belirlenir ve `CountryCurrencyMap` ile eşleşen para birimi atanır
4. Ziyaretçi isterse switcher'dan farklı para birimi seçebilir (çerez ile kaydedilir)
5. Sonraki ziyaretlerde çerez öncelikli olduğu için konum algılama tekrar çalışmaz

### Otomatik Kur Güncelleme

WordPress cron altyapısı kullanılarak döviz kurları otomatik olarak güncellenir.

| Ayar | Seçenekler |
|------|-----------|
| **Güncelleme aralığı** | Yalnızca manuel, Saatlik, Günde iki kez, Günlük |
| **Kur sağlayıcı** | ExchangeRate-API (ücretsiz), Open Exchange Rates, CurrencyLayer |
| **API Anahtarı** | Premium sağlayıcılar için gerekli |

**Cron Zamanlaması:**

Güncelleme aralığını değiştirdiğinizde:
- Mevcut zamanlanmış görev otomatik olarak temizlenir
- Yeni aralıkla yeni görev oluşturulur
- "Yalnızca manuel" seçildiğinde zamanlanmış görev kaldırılır
- Eklenti devre dışı bırakıldığında tüm cron görevleri temizlenir

> **Not:** WordPress cron, gerçek bir sistem cron'u değildir — ziyaretçi trafiğine bağlı olarak çalışır. Düşük trafikli sitelerde WP-Cron'un düzenli çalışması için sunucu crontab'ına `wp-cron.php` eklemeniz önerilir.

**Kur Sağlayıcıları:**

| Sağlayıcı | API Anahtarı | Açıklama |
|-----------|-------------|----------|
| ExchangeRate-API | Gerekmez | Ücretsiz, varsayılan sağlayıcı |
| Open Exchange Rates | Gerekir | Premium, daha kapsamlı kur verisi |
| CurrencyLayer | Gerekir | Premium, kurumsal kullanıma uygun |

### Önbellek Ayarları

| Ayar | Açıklama | Varsayılan |
|------|----------|-----------|
| **Önbellek uyumluluk modu** | Sayfa önbellekleme eklentileri ile uyumluluk | Kapalı |
| **Kur önbellek süresi** | Kurların ne kadar süre önbelleğe alınacağı (saniye) | 3600 (1 saat) |

**Önbellek Uyumluluk Modu:** WP Super Cache, W3 Total Cache gibi eklentiler kullanıyorsanız bu seçeneği açın. Çerez tabanlı algılama kullanarak sayfa önbelleği ile uyumlu çalışır.

### Çok Dilli Eşleme (Multilingual Mapping)

WPML veya Polylang gibi çok dil eklentileri kullanıyorsanız, her dil için varsayılan para birimi atayabilirsiniz.

| Dil | Varsayılan Para Birimi |
|-----|----------------------|
| Türkçe | TRY |
| İngilizce | USD |
| Almanca | EUR |
| Fransızca | EUR |

Ziyaretçi dil değiştirdiğinde para birimi otomatik olarak eşleşen birime geçer.

---

## 6. Lisans Yönetimi

**WooCommerce > MHM Para Birimi > License** sekmesi

### Lisans Durumları

| Durum | Açıklama |
|-------|----------|
| **Aktif** | Lisans etkin, tüm Pro özellikler kullanılabilir |
| **Pasif** | Lisans etkinleştirilmemiş, Lite mod |
| **Süresi Dolmuş** | Lisans süresi dolmuş, yenileme gerekli |

### Lisans Etkinleştirme

1. Lisans anahtarınızı girin (format: `MHM-XXXX-XXXX-XXXX-XXXX`)
2. **Lisansı Etkinleştir** düğmesine tıklayın
3. Başarılı etkinleştirme sonrası Pro özellikler anında kullanılabilir

### Lisansı Devre Dışı Bırakma

Lisansınızı başka bir siteye taşımak isterseniz:

1. **Lisansı Devre Dışı Bırak** düğmesine tıklayın
2. Lisans boşaltılır ve yeni sitede kullanılabilir

> **Not:** Her lisans tek bir site için geçerlidir. Lisansı yeni siteye taşımadan önce mevcut sitede devre dışı bırakmalısınız.

### Günlük Doğrulama

Eklenti, lisansınızı günde bir kez otomatik olarak doğrular. İnternet bağlantısı kesilse bile 7 günlük bir "grace period" (ödemesiz süre) boyunca Pro özellikler çalışmaya devam eder.

---

## 7. Kısa Kodlar

### Para Birimi Dönüştürücü Açılır Menüsü

```
[mhm_currency_switcher]
```

Ziyaretçilerin para birimi seçmesini sağlayan açılır menü (dropdown).

**Nitelikler (Attributes):**

| Nitelik | Değerler | Varsayılan | Açıklama |
|---------|---------|-----------|----------|
| `size` | `small`, `medium`, `large` | `medium` | Açılır menü boyutu |

**Kullanım Örnekleri:**

```
[mhm_currency_switcher]                    — Orta boyut (varsayılan)
[mhm_currency_switcher size="small"]       — Küçük boyut
[mhm_currency_switcher size="large"]       — Büyük boyut
```

**Nereye Yerleştirilir:**
- Header bölgesine (tema widget alanı veya Elementor)
- Footer bölgesine
- Sidebar'a
- Herhangi bir sayfa veya yazıya

**Nasıl Çalışır:**
1. Ziyaretçi açılır menüye tıklar
2. Mevcut para birimleri bayrak ve simge ile listelenir
3. Bir para birimi seçtiğinde çerez ayarlanır
4. Sayfa otomatik olarak yenilenir
5. Tüm fiyatlar seçilen para biriminde gösterilir

---

### Çoklu Para Birimi Fiyat Gösterimi

```
[mhm_currency_prices]
```

Ürün sayfalarında fiyatı birden fazla para biriminde gösteren bileşen.

**Nitelikler:**

| Nitelik | Örnek | Açıklama |
|---------|-------|----------|
| `currencies` | `USD,EUR,GBP` | Gösterilecek para birimleri (virgülle ayrılmış) |
| `product_id` | `123` | Belirli bir ürünün fiyatı (isteğe bağlı) |
| `price` | `29.99` | Özel fiyat değeri (test amaçlı, isteğe bağlı) |

**Kullanım Örnekleri:**

```
[mhm_currency_prices currencies="USD,EUR,GBP"]

[mhm_currency_prices currencies="EUR,USD" product_id="42"]

[mhm_currency_prices currencies="EUR" price="100"]
```

**Otomatik Ürün Sayfası Gösterimi:**

Admin panelinde **Display Options > Product Price Widget** bölümünü etkinleştirdiyseniz, bu kısa kod otomatik olarak her ürün sayfasında ana fiyatın altında gösterilir. Ayrıca kısa kodu elle eklemenize gerek yoktur.

---

## 8. Navigasyon Menüsü Entegrasyonu

Para birimi dönüştürücüsünü WordPress navigasyon menüsüne doğrudan ekleyebilirsiniz. Kısa kod veya widget kullanmadan, temanızın header menüsünde bir açılır menü olarak görüntülenir.

### Menüye Ekleme

1. **Görünüm > Menüler** sayfasına gidin
2. Sol panelde **Para Birimi Dönüştürücü** meta kutusunu bulun
3. **Menüye Ekle** düğmesine tıklayın
4. Menü öğesini istediğiniz konuma sürükleyin (genellikle en sağ)
5. **Menüyü Kaydet** düğmesine tıklayın

### Nasıl Çalışır

- Menü öğesi frontend'de otomatik olarak para birimi dönüştürücü dropdown'una dönüşür
- Bayrak simgeleri ve para birimi sembolleri ile birlikte tüm etkin para birimleri listelenir
- Ziyaretçi bir para birimi seçtiğinde çerez ayarlanır ve sayfa yenilenir
- Dropdown, mevcut temanızın header stiline uyum sağlar

### Tema Uyumluluğu

Eklenti, tema CSS kurallarının dropdown'u bozmasını önlemek için `!important` koruma kullanır. Aşağıdaki yaygın sorunlar otomatik olarak çözülür:

| Sorun | Çözüm |
|-------|-------|
| Dropdown sayfa yüklendiğinde açık görünür | `display: none !important` koruma |
| Dropdown butonun yanında açılır | `position: absolute !important` koruma |
| Header alanı dropdown açılınca büyür | Absolute konumlandırma ile akış dışına çıkarılır |
| Tema flex layout'u dropdown'u bozar | `display: block !important` koruma |

### Özelleştirme

Menü içindeki dönüştürücünün konumunu CSS ile ayarlayabilirsiniz:

```css
/* Dropdown'u sağa hizala */
.menu-item.mhm-cs-menu-item .mhm-cs-dropdown {
    left: auto;
    right: 0;
    min-width: 160px;
}

/* Menü öğesi genişliğini ayarla */
.menu-item.mhm-cs-menu-item {
    min-width: 100px;
}
```

---

## 9. Elementor Widget'ları

Eklenti, Elementor sayfa oluşturucu için iki adet özel widget sağlar.

### Currency Switcher Widget

| Özellik | Detay |
|---------|-------|
| **Widget Adı** | Currency Switcher |
| **Simge** | Küre simgesi |
| **Kategori** | MHM Currency Switcher |

**İçerik Kontrolleri:**
- **Boyut (Size):** Küçük, Orta (varsayılan), Büyük

**Stil Kontrolleri:**
- **Metin Rengi:** Renk seçici ile özelleştirilebilir

**Kullanım:** Elementor editöründe sol panelden widget'ı sürükleyip sayfanın istediğiniz yerine bırakın. Header template'inde kullanmak için Elementor Pro'nun Theme Builder özelliğini kullanabilirsiniz.

---

### Currency Prices Widget

| Özellik | Detay |
|---------|-------|
| **Widget Adı** | Currency Prices |
| **Simge** | Fiyat listesi simgesi |
| **Kategori** | MHM Currency Switcher |

**İçerik Kontrolleri:**
- **Para Birimleri:** Virgülle ayrılmış para birimi kodları (varsayılan: `USD,EUR,GBP`)
- **Bayrak Göster:** Açık/Kapalı (varsayılan: Açık)

**Kullanım:** Ürün sayfası template'ine eklenebilir. Ürünün fiyatını seçilen para birimlerinde otomatik olarak çevirir ve gösterir.

---

## 10. WP-CLI Komutları

Sunucu terminalinden eklentiyi yönetmek için WP-CLI komutları:

### Kurları Senkronize Et

```bash
wp mhm-cs rates-sync
```

Yapılandırılmış kur sağlayıcısından güncel döviz kurlarını çeker ve "Otomatik" türündeki tüm kurları günceller.

**Çıktı:**
```
Fetching rates for base currency: TRY...
Synced 3 exchange rates successfully.
```

---

### Belirli Bir Kurun Değerini Göster

```bash
wp mhm-cs rates-get EUR
```

Belirtilen para birimi için ham ve efektif (komisyon dahil) kuru gösterir.

**Çıktı:**
```
Currency: EUR
Raw rate: 0.0267
Effective rate: 0.0274
Rate retrieved for EUR.
```

---

### Kur Önbelleğini Temizle

```bash
wp mhm-cs cache-flush
```

Önbelleğe alınmış döviz kurlarını temizler. Bir sonraki istek yeni kurları çekecektir.

---

### Yapılandırılmış Para Birimlerini Listele

```bash
wp mhm-cs currencies-list
```

Tüm para birimlerini tablo formatında listeler.

**Çıktı:**
```
+------+--------+---------+--------+
| Code | Rate   | Enabled | Symbol |
+------+--------+---------+--------+
| EUR  | 0.0267 | Yes     | €      |
| USD  | 0.0293 | Yes     | $      |
| GBP  | 0.0230 | Yes     | £      |
+------+--------+---------+--------+
```

---

### Eklenti Durum Özeti

```bash
wp mhm-cs status
```

Eklentinin genel durumunu gösterir.

**Çıktı:**
```
MHM Currency Switcher v0.3.0
Mode: Lite
Base currency: TRY
Total currencies: 3
Enabled currencies: 3
```

---

## 11. Para Birimi Algılama Mekanizması

Eklenti, ziyaretçinin hangi para birimini görmesi gerektiğini şu öncelik sırasına göre belirler:

```
1. Çerez (Cookie)          ← En yüksek öncelik
2. URL Parametresi
3. Konum Algılama (Pro)     ← Ziyaretçinin ülkesine göre
4. Ana Para Birimi          ← Varsayılan
```

### 1. Çerez Algılama (Birincil)

Ziyaretçi switcher'dan bir para birimi seçtiğinde:
- `mhm_cs_currency` adında bir çerez oluşturulur
- 30 gün süreyle geçerlidir
- Sonraki tüm sayfa ziyaretlerinde bu çerez okunur

**Çerez Özellikleri:**

| Özellik | Değer |
|---------|-------|
| Ad | `mhm_cs_currency` |
| Süre | 30 gün |
| Yol | `/` (tüm site) |
| Güvenli | HTTPS sitelerinde evet |
| SameSite | Lax |
| HttpOnly | Hayır (JavaScript erişimi mümkün) |

### 2. URL Parametresi

Bağlantıya `?currency=EUR` parametresi ekleyerek para birimini değiştirebilirsiniz:

```
https://siteadiniz.com/urun-sayfasi/?currency=EUR
https://siteadiniz.com/shop/?currency=USD
```

Bu özellik, kampanya bağlantıları veya dış yönlendirmeler için kullanışlıdır.

### 3. Ana Para Birimi (Varsayılan)

Çerez veya URL parametresi yoksa, WooCommerce'te ayarlanmış ana para birimi gösterilir.

---

## 12. Döviz Kuru Dönüştürme Mantığı

### Temel Formül

```
Dönüştürülmüş Fiyat = Ana Fiyat × Efektif Kur
```

**Efektif kur hesaplama:**

| Komisyon Türü | Formül |
|-------------|--------|
| Yok | Efektif Kur = Ham Kur |
| Yüzde | Efektif Kur = Ham Kur × (1 + Komisyon% / 100) |
| Sabit | Efektif Kur = Ham Kur + Sabit Komisyon |

### Pratik Örnek

Ana para birimi: TRY, Ürün fiyatı: 500 TRY

| Para Birimi | Ham Kur | Komisyon | Efektif Kur | Dönüştürülmüş Fiyat |
|------------|---------|----------|------------|---------------------|
| EUR | 0.0267 | %2.5 | 0.02737 | 13,68 € |
| USD | 0.0293 | Yok | 0.0293 | 14,65 $ |
| GBP | 0.0230 | 0.002 sabit | 0.0250 | 12,50 £ |

### Hangi Fiyatlar Dönüştürülür?

Eklenti, WooCommerce'in tüm fiyat noktalarını dönüştürür:

- Ürün fiyatı (normal ve indirimli)
- Varyasyon fiyatları (tüm varyasyonlar)
- Sepet toplamı
- Kargo ücreti
- Kupon tutarları
- Sipariş toplamları

### Fiyat Biçimlendirme

Her para birimi için ayrı biçimlendirme ayarları uygulanır:

| Ayar | Açıklama | Örnek |
|------|----------|-------|
| `symbol` | Para birimi simgesi | €, $, ₺, £ |
| `decimals` | Ondalık basamak sayısı | 2 |
| `decimal_sep` | Ondalık ayracı | `.` veya `,` |
| `thousand_sep` | Binler ayracı | `,` veya boşluk |
| `position` | Simge konumu | sol, sağ, sol boşluklu, sağ boşluklu |

**Konum Örnekleri:**

| Konum | Görünüm |
|-------|---------|
| `left` | €50,00 |
| `left_space` | € 50,00 |
| `right` | 50,00€ |
| `right_space` | 50,00 € |

---

## 13. CSS ile Özelleştirme

Eklentinin görünümünü temanızın CSS'i veya WordPress'in Ek CSS bölümünden özelleştirebilirsiniz.

### Switcher CSS Sınıfları

```css
/* Ana kapsayıcı */
.mhm-cs-switcher { }

/* Boyut varyantları */
.mhm-cs-size--small  { font-size: 12px; }
.mhm-cs-size--medium { font-size: 14px; }
.mhm-cs-size--large  { font-size: 16px; }

/* Seçim düğmesi */
.mhm-cs-selected {
    display: inline-flex;
    padding: 6px 10px;
    border: 1px solid #ccc;
    border-radius: 4px;
    background: white;
    cursor: pointer;
}

/* Açılır liste */
.mhm-cs-dropdown {
    position: absolute;
    background: white;
    border: 1px solid #ccc;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

/* Açılır liste açıkken */
.mhm-cs-dropdown.mhm-cs-open {
    display: block;
}

/* Her bir seçenek */
.mhm-cs-option {
    display: flex;
    padding: 6px 10px;
    cursor: pointer;
}

/* Seçenek üzerine gelindiğinde */
.mhm-cs-option:hover {
    background-color: #f5f5f5;
}

/* Aktif (seçili) seçenek */
.mhm-cs-active {
    font-weight: bold;
    background-color: #f0f7ff;
}

/* Bayrak simgesi */
.mhm-cs-flag {
    width: 20px;
    height: 15px;
}

/* Etiket metni */
.mhm-cs-label { }

/* Açılır ok */
.mhm-cs-arrow {
    font-size: 10px;
    color: #666;
}
```

### Ürün Fiyat Bileşeni CSS Sınıfları

```css
/* Fiyat bileşeni kapsayıcı */
.mhm-cs-product-prices {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 8px;
    font-size: 13px;
    color: #666;
}

/* Her bir fiyat öğesi */
.mhm-cs-product-price {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

/* Ayırıcı çizgi */
.mhm-cs-separator {
    color: #ddd;
}

/* Fiyat tutarı */
.mhm-cs-amount { }
```

### Özelleştirme Örnekleri

**Switcher arka plan rengini değiştirme:**
```css
.mhm-cs-selected {
    background-color: #1a1a2e;
    color: #ffffff;
    border-color: #16213e;
}
```

**Açılır menü genişliğini sabitlme:**
```css
.mhm-cs-dropdown {
    min-width: 200px;
}
```

**Ürün fiyat bileşeni yazı boyutunu büyütme:**
```css
.mhm-cs-product-prices {
    font-size: 16px;
    color: #333;
}
```

**Mobilde dikey düzene geçiş (varsayılan olarak aktif):**
```css
@media (max-width: 480px) {
    .mhm-cs-product-prices {
        flex-direction: column;
        align-items: flex-start;
    }
    .mhm-cs-separator {
        display: none;
    }
}
```

---

## 14. REST API Referansı

Eklenti, harici entegrasyonlar için REST API sağlar.

**Temel URL:** `/wp-json/mhm-currency/v1/`

### Herkese Açık Uç Nokta

#### GET `/rates`

Kimlik doğrulaması gerektirmez. Etkin para birimlerinin güncel kurlarını döndürür.

```bash
curl https://siteadiniz.com/wp-json/mhm-currency/v1/rates
```

**Yanıt:**
```json
{
    "base": "TRY",
    "rates": {
        "EUR": 0.0274,
        "USD": 0.0293,
        "GBP": 0.0250
    }
}
```

### Yönetici Uç Noktaları

> Aşağıdaki uç noktalar `manage_woocommerce` yetkisi gerektirir.

| Yöntem | Uç Nokta | Açıklama |
|--------|----------|----------|
| GET | `/settings` | Eklenti ayarlarını getirir |
| POST | `/settings` | Eklenti ayarlarını kaydeder |
| GET | `/currencies` | Yapılandırılmış para birimlerini getirir |
| POST | `/currencies` | Para birimlerini kaydeder |
| POST | `/rates/sync` | Döviz kurlarını senkronize eder |
| GET | `/rates/preview` | Ham ve efektif kurları önizler |
| POST | `/license/activate` | Lisansı etkinleştirir |
| POST | `/license/deactivate` | Lisansı devre dışı bırakır |

---

## 15. Lite ve Pro Karşılaştırması

| Özellik | Lite (Ücretsiz) | Pro |
|---------|-----------------|-----|
| Para birimi limiti | 2 ek para birimi | Sınırsız |
| Manuel kur senkronizasyonu | Var | Var |
| Otomatik kur güncelleme | — | Saatlik / Günlük |
| Çerez tabanlı para birimi seçimi | Var | Var |
| URL parametresi ile değiştirme | Var | Var |
| Kısa kodlar | Var | Var |
| Elementor widget'ları | Var | Var |
| WP-CLI komutları | Var | Var |
| Bayrak simgeleri (283 ülke) | Var | Var |
| Türkçe dil desteği | Var | Var |
| Konum algılama (Geolocation) | — | Var |
| Ödeme yöntemi kısıtlamaları | — | Var |
| Çok dilli para birimi eşleme | — | Var |
| Premium kur sağlayıcıları | — | Var |
| REST API para birimi filtresi | — | Var |
| MHM Rentiva entegrasyonu | — | Var |
| Öncelikli destek | — | Var |

---

## 16. Sık Sorulan Sorular

### Kaç para birimi ekleyebilirim?

Ücretsiz sürümde ana para biriminize ek olarak 2 para birimi ekleyebilirsiniz. Pro sürümde sınır yoktur.

### Döviz kurları ne sıklıkla güncellenir?

- **Lite:** Yalnızca "Kurları Senkronize Et" düğmesine tıkladığınızda (manuel)
- **Pro:** Saatlik, günde iki kez veya günlük otomatik güncelleme seçenekleri mevcuttur

### Dönüştürücüyü header'a nasıl eklerim?

Dört yöntem:
1. **Navigasyon menüsü (Önerilen):** Görünüm > Menüler'den "Para Birimi Dönüştürücü" öğesini menünüze ekleyin
2. **Tema widget alanı:** Temanızın header widget alanına bir "Kısa Kod" widget'ı ekleyin ve `[mhm_currency_switcher]` yazın
3. **Elementor:** Header template'inize Currency Switcher widget'ını sürükleyin
4. **PHP:** Temanızın `header.php` dosyasına `<?php echo do_shortcode('[mhm_currency_switcher]'); ?>` ekleyin

### Siparişler hangi para biriminde kaydedilir?

Siparişler, müşterinin seçtiği para biriminde kaydedilir. Sipariş meta verilerinde hem orijinal fiyat hem de uygulanan döviz kuru saklanır.

### Sayfa önbellekleme eklentileri ile uyumlu mu?

Evet. **Gelişmiş Ayarlar > Önbellek Uyumluluk Modu** seçeneğini açtığınızda çerez tabanlı algılama kullanılır ve sayfa önbellekleme eklentileri ile sorunsuz çalışır.

### WooCommerce HPOS ile uyumlu mu?

Evet. Eklenti, WooCommerce High-Performance Order Storage (HPOS / Custom Order Tables) ile tam uyumlu olarak bildirilmiştir.

### Mevcut bir para birimini kaldırmak verileri siler mi?

Hayır. Para birimini kaldırmak yalnızca yapılandırmadan çıkarır. Daha önce o para biriminde kaydedilmiş siparişler etkilenmez.

### Dönüştürücü çalışmıyor, fiyatlar değişmiyor

Kontrol edin:
1. Para birimlerinin **Etkin** olduğundan emin olun
2. Döviz kurlarının sıfır olmadığını doğrulayın
3. **Kurları Senkronize Et** düğmesine tıklayarak güncel kur çekin
4. Tarayıcı önbelleğini temizleyin (Ctrl+Shift+R)
5. Sayfa önbellekleme eklentisi varsa önbelleği temizleyin

---

**Sürüm:** 0.3.0
**Geliştirici:** [MaxHandMade](https://maxhandmade.com)
**Destek:** info@maxhandmade.com
