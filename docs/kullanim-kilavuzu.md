# MHM Currency Switcher — Kullanım Kılavuzu

WooCommerce mağazanızda birden fazla para birimini desteklemenizi sağlayan döviz çevirici eklentisi.

**Sürüm:** Bu kılavuz **v1.0.0** davranışını anlatır. Eklenti tek ve tamamen ücretsiz bir sürüm olarak dağıtılır; aşağıda anlatılan her özellik kurulumun ardından kutudan çıktığı gibi kullanılabilir.

> **WooCommerce zorunludur.** Eklenti yalnızca WooCommerce etkinken çalışır. WooCommerce yoksa eklenti kendini devreye almaz ve yönetim panelinde bir uyarı gösterir.

---

## İçindekiler

1. [Kurulum ve Gereksinimler](#1-kurulum-ve-gereksinimler)
2. [Para Birimlerini Yönet](#2-para-birimlerini-yönet)
3. [Görüntüleme Seçenekleri](#3-görüntüleme-seçenekleri)
4. [Gelişmiş Ayarlar](#4-gelişmiş-ayarlar)
5. [Ürün Bazında Sabit Fiyatlar](#5-ürün-bazında-sabit-fiyatlar)
6. [Kısa Kodlar](#6-kısa-kodlar)
7. [Navigasyon Menüsü Entegrasyonu](#7-navigasyon-menüsü-entegrasyonu)
8. [Elementor Widget'ları](#8-elementor-widgetları)
9. [WP-CLI Komutları](#9-wp-cli-komutları)
10. [Para Birimi Algılama Mekanizması](#10-para-birimi-algılama-mekanizması)
11. [Döviz Kuru Dönüştürme Mantığı](#11-döviz-kuru-dönüştürme-mantığı)
12. [Dönüştürülen Fiyatlar](#12-dönüştürülen-fiyatlar)
13. [CSS ile Özelleştirme](#13-css-ile-özelleştirme)
14. [REST API Referansı](#14-rest-api-referansı)
15. [Sık Sorulan Sorular](#15-sık-sorulan-sorular)

---

## 1. Kurulum ve Gereksinimler

### Gereksinimler

| Gereksinim | Minimum Sürüm |
|-----------|---------------|
| WordPress | 6.0 |
| PHP | 7.4 |
| WooCommerce | 7.0 (9.0 ile test edildi) |

### Kurulum Adımları

1. `mhm-currency-switcher` klasörünü `/wp-content/plugins/` dizinine yükleyin
2. **Eklentiler** menüsünden eklentiyi etkinleştirin
3. WooCommerce'in yüklü ve etkin olduğundan emin olun
4. **WooCommerce > MHM Para Birimi** sayfasına gidin

Yönetim sayfasını görebilmek için `manage_woocommerce` yetkisine sahip olmanız gerekir (mağaza yöneticisi ve site yöneticisi rolleri bu yetkiye sahiptir).

### Etkinleştirme Sonrası Durum

Eklenti ilk etkinleştirildiğinde:

- **Para birimi listesi boş başlar.** Hazır para birimi eklenmez; istediklerinizi kendiniz eklersiniz.
- Konum algılama **kapalı** olarak gelir (2.2.0'dan itibaren). Ziyaretçinin ülkesini IP'sinden çözmek bir veri işleme kararıdır; bu yüzden bilerek açmanız beklenir.
- Dönüştürücü görünümü şu varsayılanlarla gelir: bayrak **açık**, simge **açık**, kod **açık**, para birimi adı **kapalı**, boyut **Orta**.

> **Not:** Ana para biriminiz (base currency) her zaman WooCommerce ayarlarından okunur. Değiştirmek için **WooCommerce > Ayarlar > Genel > Para birimi seçenekleri** bölümüne gidin. Ana para birimini eklentinin para birimi listesine ekleyemezsiniz — o zaten çevirinin çıkış noktasıdır.

### Yönetim Sekmeleri

Eklentinin yönetim ekranı üç sekmeden oluşur:

| Sekme | İçerik |
|-------|--------|
| **Para Birimlerini Yönet** | Para birimi listesi, kur, komisyon, yuvarlama, sıralama |
| **Görüntüleme Seçenekleri** | Dönüştürücünün görünümü ve ürün fiyat bileşeni |
| **Gelişmiş** | Konum algılama ve otomatik kur güncelleme aralığı |

Değişiklikleriniz kaydedilmemişse sayfanın üstünde bir uyarı çubuğu belirir. **Değişiklikleri Kaydet** düğmesi hem bu çubukta hem de sayfanın altında bulunur ve üç sekmedeki tüm değişiklikleri tek seferde kaydeder.

---

## 2. Para Birimlerini Yönet

**WooCommerce > MHM Para Birimi > Para Birimlerini Yönet** sekmesi

Mağazanızda kullanılacak para birimlerini burada yapılandırırsınız.

### Para Birimi Tablosu

| Sütun | Açıklama |
|-------|----------|
| **Etkin** | Para birimini açıp kapatır. Kapalı birimler dönüştürücüde görünmez. |
| **Kod** | ISO 4217 kodu, bayrak simgesi ve para biriminin tam adı |
| **Kur** | Kur türü (Otomatik / Manuel) ve kur değeri |
| **Komisyon** | Kura uygulanacak ek komisyon (Yok / Yüzde / Sabit) |
| **Yuvarlama** | Çevrilen fiyata uygulanacak yuvarlama kuralı |
| **Sıra** | Yukarı/aşağı okları ile listedeki sırayı değiştirir |
| **İşlemler** | Para birimini listeden kaldırır |

Sıralama, dönüştürücü açılır menüsündeki görüntülenme sırasını belirler. Ana para birimi her zaman listenin en başında yer alır.

### Yeni Para Birimi Ekleme

1. **+ Yeni Para Birimi** düğmesine tıklayın
2. Açılır listeden istediğiniz para birimini seçin (ana para birimi ve zaten ekli olanlar listede çıkmaz)
3. **Ekle** düğmesine tıklayın
4. Kur, komisyon ve yuvarlama ayarlarını yapın
5. **Değişiklikleri Kaydet** düğmesine tıklayın

Yeni eklenen para birimi şu değerlerle gelir: etkin, kur türü **Otomatik**, komisyon **Yok**, yuvarlama **Yok**.

### Kur Türleri

- **Otomatik:** Kur alanı düzenlenemez; değer kur senkronizasyonundan gelir.
- **Manuel:** Kuru kendiniz yazarsınız. Alan 6 ondalık basamağa kadar değer kabul eder.

> **Dikkat:** Kur senkronizasyonu, kur türü ayrımı yapmadan API'den dönen tüm para birimlerinin kurunu günceller. Elle girdiğiniz bir kurun korunmasını istiyorsanız senkronizasyondan sonra değeri kontrol edin.

### Komisyon Türleri

Döviz kuruna ek komisyon uygulamak için üç seçenek vardır:

| Tür | Etki | Örnek (kur 0.92) |
|-----|------|------------------|
| **Yok** | Komisyon uygulanmaz | Efektif kur 0.92 |
| **Yüzde** | Kur, yüzde oranında artırılır | %2.5 → 0.92 × 1.025 = 0.943 |
| **Sabit** | Kura sabit bir değer eklenir | 0.03 → 0.92 + 0.03 = 0.95 |

Komisyon türünü **Yok** yaptığınızda önceden girilmiş komisyon değeri de sıfırlanır.

### Yuvarlama

Çevrilen fiyatı düzgün bir rakama oturtmak için kullanılır. Yuvarlama, kur ve komisyon uygulandıktan **sonra** devreye girer.

| Tür | Etki |
|-----|------|
| **Yok** | Yuvarlama uygulanmaz |
| **En Yakına** | Fiyatı belirttiğiniz adımın en yakın katına yuvarlar |
| **Yukarı Yuvarla** | Belirttiğiniz adımın bir üst katına yuvarlar |
| **Aşağı Yuvarla** | Belirttiğiniz adımın bir alt katına yuvarlar |

Yuvarlamayı **Yok** dışında bir değere ayarladığınızda iki alan açılır:

- **Adım değeri:** Neye göre yuvarlanacağı (örneğin `1`, `5`, `0.5`)
- **Çıkar:** Yuvarlamadan sonra düşülecek miktar — psikolojik fiyatlama için kullanışlıdır

**Örnek:** Çevrilen fiyat 47,30 · Tür: Yukarı Yuvarla · Adım: 1 · Çıkar: 0,01 → sonuç **47,99**.

> Adım değeri `0` bırakılırsa yuvarlama uygulanmaz.

### Kurları Senkronize Etme

**Kurları Senkronize Et** düğmesi güncel kurları anında çeker ve tabloya yazar. Senkronizasyon çevrimdışı çalışmaz — sunucunuzun dışarıya HTTP isteği yapabilmesi gerekir.

Kurlar şu kaynaklardan alınır:

1. **ExchangeRate-API** (birincil) — `api.exchangerate-api.com`
2. **Avrupa Merkez Bankası (ECB) günlük referans kurları** (yedek) — birincil kaynak yanıt vermezse devreye girer; sabit, parametresiz bir XML akışıdır (`eurofxref-daily.xml`) ve yaklaşık otuz para birimini kapsar — **temel para biriminiz** bu kapsamın dışındaysa bu kaynak hiçbir kur döndürmez ve kurlar bir sonraki denemeye kadar değişmeden kalır; yapılandırdığınız **hedef** para birimlerinden yalnızca biri kapsam dışındaysa diğer hedef para birimleri yine güncellenir, sadece kapsam dışı olan için bu kaynaktan bir kur gelmez

Her iki kaynak da ücretsizdir ve **API anahtarı gerektirmez**. Çekilen kurlar 1 gün boyunca önbellekte tutulur; bu süre dolmadan yapılan senkronizasyonlar önbellekteki değeri kullanır. Önbelleği elle boşaltmak için [WP-CLI komutuna](#9-wp-cli-komutları) bakın.

---

## 3. Görüntüleme Seçenekleri

**WooCommerce > MHM Para Birimi > Görüntüleme Seçenekleri** sekmesi

### Dönüştürücü Görünümü

| Ayar | Açıklama | Varsayılan |
|------|----------|-----------|
| **Bayrak simgesi göster** | Para biriminin yanında ülke bayrağı gösterir | Açık |
| **Para birimi adını göster** | Tam adı gösterir (örn. "Türk Lirası") | **Kapalı** |
| **Para birimi simgesi göster** | Simgeyi gösterir (örn. "₺", "€") | Açık |
| **Para birimi kodunu göster** | ISO kodunu gösterir (örn. "TRY", "EUR") | Açık |

Etiket metni açık olan parçalardan sırayla oluşturulur: **simge → kod → ad**. Dördünü birden kapatırsanız etiket boş kalmaz; para birimi kodu yedek olarak gösterilir.

### Dönüştürücü Boyutu

| Boyut | Yazı boyutu |
|-------|-------------|
| **Küçük** | 12px |
| **Orta** (varsayılan) | 14px |
| **Büyük** | 16px |

### Ürün Fiyat Bileşeni

Ürün sayfalarında ana fiyatın altında, fiyatı birkaç para biriminde birden gösteren şerittir.

| Ayar | Açıklama |
|------|----------|
| **Ürün fiyat bileşenini etkinleştir** | Bileşeni açar/kapatır |
| **Gösterilecek para birimleri** | En fazla 5 para birimi seçebilirsiniz |
| **Bileşende bayrakları göster** | Fiyatların yanında bayrak simgesi gösterir |

Bileşen açıkken ürün sayfasında fiyat özetinin içinde otomatik olarak görünür; kısa kodu elle eklemenize gerek yoktur. Hiçbir para birimi seçilmemişse bileşen görünmez.

**Örnek görünüm:**

```
Ürün Fiyatı: ₺500,00

€14,17 | $15,50 | £12,30
```

### Canlı Önizleme

Sekmenin altında, seçtiğiniz ayarlara göre basit bir önizleme gösterilir. Bu sadeleştirilmiş bir temsildir — gerçek görünüm temanıza göre değişebilir.

---

## 4. Gelişmiş Ayarlar

**WooCommerce > MHM Para Birimi > Gelişmiş** sekmesi

Bu sekmede iki ayar bulunur.

### Konum Algılama

| Ayar | Açıklama |
|------|----------|
| **Konuma dayalı para birimi algılamayı etkinleştir** | Ziyaretçinin ülkesini tespit edip eşleşen para birimini gösterir |

Ülke tespiti iki kaynaktan kademeli olarak yapılır ve **hiçbir aşamada dışarıya istek gitmez**:

1. **CloudFlare (birincil):** Siteniz CloudFlare arkasındaysa ülke kodu `CF-IPCountry` başlığından okunur. Ek yapılandırma gerektirmez ve çok hızlıdır. Bilinmeyen ülke ve Tor çıkış düğümü kodları yok sayılır.
2. **Yerel MaxMind veritabanı:** CloudFlare başlığı yoksa WooCommerce'in **sunucunuzda duran** MaxMind GeoIP veritabanı dosyası sorgulanır. Bunun için **WooCommerce > Ayarlar > Entegrasyon > MaxMind Geolocation** bölümünden ücretsiz bir lisans anahtarı girilmiş olması gerekir.

**İkisi de yoksa ne olur:** Algılama sonuç bulamaz ve ziyaretçi, kendisi bir seçim yapana kadar ana para birimini görür. Üçüncü bir yere sorulmaz.

> 🔴 **2.2.0'da değişti.** Önceki sürümler WooCommerce'in `geolocate_ip()` çağrısını varsayılan argümanlarla yapıyordu; bu, MaxMind veritabanı olmayan mağazalarda WooCommerce'in **uzak bir servise** başvurmasına ve **her yeni ziyaretçinin IP adresinin dışarı gönderilmesine** yol açıyordu. 2.2.0 bu yedeği kapatır — WooCommerce çekirdeğinin vitrinde kendi yaptığı gibi. Eğer geriye dönük olarak sitenizde `_transient_geoip_*` kayıtları biriktiyse, bunlar bir günlük ömürleri dolunca kendiliğinden silinir ve yenisi yazılmaz.

**Kaynakların karşılaştırması:**

| Kaynak | Dışarıya istek | Gereken yapılandırma | Sonuç |
|--------|----------------|----------------------|-------|
| CloudFlare başlığı | Yok | Site CloudFlare arkasında olmalı | En hızlı |
| Yerel MaxMind veritabanı | Yok | Ücretsiz GeoLite2 lisans anahtarı | Hızlı |
| İkisi de yoksa | Yok | — | Algılama çalışmaz, ana para birimi gösterilir |

**MaxMind veritabanı nasıl kurulur (ücretsiz):**

1. [maxmind.com](https://www.maxmind.com/en/geolite2/signup) adresinde hesap açıp **GeoLite2** kaydını tamamlayın.
2. **Manage License Keys** bölümünden bir lisans anahtarı üretin.
3. **WooCommerce > Ayarlar > Entegrasyon > MaxMind Geolocation** ekranına anahtarı yapıştırıp kaydedin.
4. WooCommerce veritabanını `wp-content/uploads/woocommerce_uploads/` klasörüne indirir. Dosya adı tahmin edilemez bir önekle başlar; bu bilinçlidir, dosyanın dışarıdan indirilmesini engeller. **`uploads/` klasörünün kökünde aramayın.**

> **Not:** **WooCommerce > Ayarlar > Genel > Varsayılan müşteri konumu** ayarını değiştirmenize gerek yoktur. Bu eklenti `WC_Geolocation` API'sini doğrudan çağırır ve o ayardan bağımsız çalışır.

**Kurulumun işe yaradığını nasıl doğrularsınız:** En basit yol, mağazayı farklı bir ülkeden (veya VPN ile) açıp para biriminin değişip değişmediğine bakmaktır. Veritabanı kurulu değilse algılama sessizce sonuç bulamaz — bir hata görmezsiniz, yalnızca ana para birimi gösterilir.

**Nasıl çalışır:**

1. Ziyaretçide henüz para birimi çerezi yoksa konum algılama devreye girer
2. Bulunan ülke koduna karşılık gelen para birimi belirlenir
3. Bu para birimi mağazanızda **etkin** değilse algılama sonuçsuz sayılır ve ana para birimi kullanılır
4. Başarılı algılamada çerez yazılır — böylece sonraki sayfalarda tespit yeniden çalışmaz
5. Ziyaretçi dönüştürücüden başka bir para birimi seçerse tercihi çereze kaydedilir ve konum algılamanın önüne geçer

### Otomatik Kur Güncelleme

Kurlar WordPress'in zamanlanmış görev (WP Cron) altyapısıyla otomatik güncellenebilir.

| Seçenek | Etki |
|---------|------|
| **Yalnızca manuel** | Otomatik güncelleme yapılmaz; zamanlanmış görev kaldırılır |
| **Saatlik** | Saatte bir güncellenir |
| **Günde iki kez** | Günde iki kez güncellenir |
| **Günlük** | Günde bir kez güncellenir |

Aralığı değiştirdiğinizde mevcut zamanlanmış görev temizlenir ve yeni aralıkla yeniden kurulur. Eklentiyi devre dışı bıraktığınızda görev tamamen kaldırılır.

> **Not:** WP Cron gerçek bir sistem cron'u değildir — siteye gelen trafikle tetiklenir. Düşük trafikli sitelerde güncellemelerin düzenli çalışması için sunucunuzun crontab'ına `wp-cron.php` çağrısı eklemeniz önerilir.

> Kur kaynakları API anahtarı istemediği için bu sekmede sağlayıcı seçimi veya anahtar alanı bulunmaz.

---

## 5. Ürün Bazında Sabit Fiyatlar

Bazı ürünlerde kur çevirisinin sonucunu değil, kendi belirlediğiniz yuvarlak bir fiyatı göstermek isteyebilirsiniz. Bunun için ürün düzenleme ekranını kullanın.

### Basit Ürünler

1. Ürünü düzenleyin
2. **Ürün verileri** kutusunda **Para Birimi Fiyatları** sekmesine geçin
3. Her para birimi için bir alan görürsünüz — istediğiniz fiyatı yazın
4. Ürünü güncelleyin

Boş bıraktığınız alanlar için otomatik kur çevirisi kullanılır (alanın yer tutucusu bunu **Auto** olarak belirtir). Henüz hiç para birimi eklemediyseniz sekme sizi ayarlar sayfasına yönlendiren bir not gösterir.

### Varyasyonlu Ürünler

Varyasyonlu ürünlerde her varyasyonun fiyat bölümünde **Sabit Para Birimi Fiyatları** alanları bulunur. Buraya girilen değerler yalnızca o varyasyon için geçerlidir.

> **Önemli:** Bir para birimi için sabit fiyat girdiğinizde o ürünün hem normal hem de indirimli fiyatı bu tek değere eşitlenir. Bir üründe indirim göstermek istiyorsanız o para birimi için sabit fiyat kullanmayın.

---

## 6. Kısa Kodlar

Eklenti iki kısa kod sağlar.

### Para Birimi Dönüştürücü

```
[mhmcs_currency_switcher]
```

Ziyaretçilerin para birimi seçmesini sağlayan açılır menü.

**Nitelikler:**

| Nitelik | Değerler | Varsayılan | Açıklama |
|---------|---------|-----------|----------|
| `size` | `small`, `medium`, `large` | Ayarlardaki boyut | Açılır menü boyutu |

`size` verilmezse veya geçersiz bir değer verilirse **Görüntüleme Seçenekleri** sekmesindeki boyut ayarı kullanılır.

**Kullanım örnekleri:**

```
[mhmcs_currency_switcher]
[mhmcs_currency_switcher size="small"]
[mhmcs_currency_switcher size="large"]
```

**Nasıl çalışır:**

1. Ziyaretçi açılır menüye tıklar
2. Ana para birimi ve tüm etkin para birimleri listelenir
3. Bir para birimi seçildiğinde 30 günlük çerez yazılır
4. Sayfa yenilenir ve tüm fiyatlar seçilen para biriminde gösterilir

Menü, sayfanın boş bir yerine tıklandığında veya `Esc` tuşuna basıldığında kapanır. Hiç para birimi yapılandırılmamışsa kısa kod hiçbir şey basmaz.

---

### Çoklu Para Birimi Fiyat Gösterimi

```
[mhmcs_currency_prices]
```

Bir fiyatı aynı anda birden fazla para biriminde gösterir.

**Nitelikler:**

| Nitelik | Örnek | Açıklama |
|---------|-------|----------|
| `currencies` | `USD,EUR,GBP` | Gösterilecek para birimleri (virgülle ayrılmış). Verilmezse ayarlardaki liste kullanılır. |
| `product_id` | `123` | Belirli bir ürünün fiyatı. Verilmezse bulunulan sayfadaki ürün kullanılır. |
| `price` | `29.99` | Ürün yerine doğrudan bir tutar çevirir |
| `show_flags` | `true` / `false` | Bayrak simgelerini açar/kapatır. Verilmezse ayarlardaki tercih geçerlidir. |

Ana para birimi, `currencies` listesine yazılsa bile çıktıdan elenir — zaten sayfadaki asıl fiyat odur.

**Kullanım örnekleri:**

```
[mhmcs_currency_prices currencies="USD,EUR,GBP"]

[mhmcs_currency_prices currencies="EUR,USD" product_id="42"]

[mhmcs_currency_prices currencies="EUR" price="100" show_flags="false"]
```

---

## 7. Navigasyon Menüsü Entegrasyonu

Dönüştürücüyü kısa kod kullanmadan doğrudan WordPress menünüze ekleyebilirsiniz.

### Menüye Ekleme

1. **Görünüm > Menüler** sayfasına gidin
2. Sol sütunda **MHM Currency Switcher** kutusunu bulun
3. **Para Birimi Dönüştürücü** öğesini işaretleyip **Menüye Ekle** düğmesine tıklayın
4. Menü öğesini istediğiniz konuma sürükleyin
5. **Menüyü Kaydet** düğmesine tıklayın

### Nasıl Çalışır

- Menü öğesi ön yüzde otomatik olarak dönüştürücü açılır menüsüne dönüşür
- Menü içindeki dönüştürücü her zaman **küçük** boyutta görüntülenir; boyut ayarı burada uygulanmaz
- Menü öğesine `mhmcs-menu-item` sınıfı eklenir; CSS ile bu sınıf üzerinden hedefleyebilirsiniz
- Menü içindeki dönüştürücünün çerçevesi ve arka planı kaldırılır, rengi menünüzden devralınır

### Tema Uyumluluğu

Tema CSS kurallarının açılır menüyü bozmasını önlemek için eklenti bazı kuralları `!important` ile korur:

| Sorun | Koruma |
|-------|--------|
| Açılır liste sayfa yüklenince açık görünüyor | `display: none !important` |
| Açılır liste düğmenin yanında açılıyor | `position: absolute !important` |
| Tema flex düzeni listeyi yatay yayıyor | `flex-direction: column !important` |
| Liste diğer öğelerin altında kalıyor | `z-index: 1000 !important` |

Menü içindeki açılır liste varsayılan olarak sağa hizalanır ve en az 160px genişliktedir.

---

## 8. Elementor Widget'ları

Elementor kuruluysa eklenti **MHM Para Birimi Dönüştürücü** kategorisi altında iki widget sağlar.

### Currency Switcher

| Özellik | Detay |
|---------|-------|
| **Widget adı** | Currency Switcher (Para Birimi Dönüştürücü) |
| **Simge** | Küre |

**İçerik kontrolleri:**
- **Size:** Small / Medium (varsayılan) / Large

**Stil kontrolleri:**
- **Text Color:** Dönüştürücünün metin rengi

### Currency Prices

| Özellik | Detay |
|---------|-------|
| **Widget adı** | Currency Prices (Para Birimi Fiyatları) |
| **Simge** | Fiyat listesi |

**İçerik kontrolleri:**
- **Currencies:** Virgülle ayrılmış para birimi kodları (varsayılan `USD,EUR,GBP`)
- **Show Flags:** Açık (varsayılan) / Kapalı

Bu widget bir ürünün fiyatını gösterir; ürün sayfası şablonlarında kullanılmak üzere tasarlanmıştır. Ürün bağlamı olmayan bir sayfaya konulduğunda çıktı üretmez.

---

## 9. WP-CLI Komutları

Sunucu terminalinden eklentiyi yönetmek için beş komut vardır.

### Kurları senkronize et

```bash
wp mhmcs rates-sync
```

Güncel kurları çeker ve para birimlerine yazar.

```
Fetching rates for base currency: TRY...
Success: Synced 3 exchange rates successfully.
```

### Belirli bir kuru göster

```bash
wp mhmcs rates-get EUR
```

Ham kuru ve komisyon uygulanmış efektif kuru gösterir.

```
Currency:       EUR
Raw rate:       0.0267
Effective rate: 0.0274
Success: Rate retrieved for EUR.
```

### Kur önbelleğini temizle

```bash
wp mhmcs cache-flush
```

Ana para birimi için önbelleğe alınmış kurları siler. Sonraki senkronizasyon değerleri yeniden API'den çeker.

### Para birimlerini listele

```bash
wp mhmcs currencies-list
```

```
Base currency: TRY

+------+--------+---------+--------+
| Code | Rate   | Enabled | Symbol |
+------+--------+---------+--------+
| EUR  | 0.0267 | Yes     | €      |
| USD  | 0.0293 | Yes     | $      |
| GBP  | 0.0230 | Yes     | £      |
+------+--------+---------+--------+
```

### Eklenti durumu

```bash
wp mhmcs status
```

```
MHM Currency Switcher v1.0.0
Base currency:      TRY
Total currencies:   3
Enabled currencies: 3
Success: Status check complete.
```

---

## 10. Para Birimi Algılama Mekanizması

Eklenti, ziyaretçinin hangi para birimini göreceğini şu sırayla belirler:

```
1. Çerez                 ← En yüksek öncelik
2. URL parametresi
3. Konum algılama        ← Yalnızca Gelişmiş sekmesinden açıksa
4. Ana para birimi       ← Varsayılan
```

Hangi adımdan gelirse gelsin, bulunan kod **ana para birimi** veya **etkin bir para birimi** değilse yok sayılır ve sıradaki adıma geçilir.

### 1. Çerez

Ziyaretçi dönüştürücüden bir para birimi seçtiğinde çerez yazılır.

| Özellik | Değer |
|---------|-------|
| Ad | `mhmcs_currency` |
| Süre | 30 gün |
| Yol | `/` (tüm site) |
| Güvenli | HTTPS sitelerinde evet |
| SameSite | Lax |
| HttpOnly | Hayır |

### 2. URL Parametresi

Bağlantıya `?currency=EUR` ekleyerek para birimini belirleyebilirsiniz:

```
https://siteadiniz.com/urun-sayfasi/?currency=EUR
https://siteadiniz.com/magaza/?currency=USD
```

Kampanya bağlantıları ve dış yönlendirmeler için kullanışlıdır. Bu parametre sayfa görüntülemesi sırasında geçerlidir; kalıcılığı sağlayan şey çerezdir. Ziyaretçide zaten bir çerez varsa çerez öncelikli olduğu için parametre etkisiz kalır.

### 3. Konum Algılama

**Gelişmiş** sekmesinden açıldıysa devreye girer. Ayrıntılar için [Gelişmiş Ayarlar](#4-gelişmiş-ayarlar) bölümüne bakın.

### 4. Ana Para Birimi

Yukarıdakilerin hiçbiri sonuç vermezse WooCommerce'te tanımlı ana para birimi kullanılır.

---

## 11. Döviz Kuru Dönüştürme Mantığı

### Temel Formül

```
Çevrilmiş Fiyat = Ana Fiyat × Efektif Kur   → (varsa) yuvarlama
```

**Efektif kur:**

| Komisyon Türü | Formül |
|---------------|--------|
| Yok | Efektif Kur = Ham Kur |
| Yüzde | Efektif Kur = Ham Kur × (1 + Komisyon / 100) |
| Sabit | Efektif Kur = Ham Kur + Sabit Komisyon |

**Yuvarlama** (yalnızca ürün fiyatlarına uygulanır):

| Tür | Formül |
|-----|--------|
| En Yakına | (Fiyat ÷ Adım) en yakına yuvarlanır × Adım − Çıkar |
| Yukarı Yuvarla | (Fiyat ÷ Adım) yukarı yuvarlanır × Adım − Çıkar |
| Aşağı Yuvarla | (Fiyat ÷ Adım) aşağı yuvarlanır × Adım − Çıkar |

### Pratik Örnek

Ana para birimi TRY, ürün fiyatı 500 TRY:

| Para Birimi | Ham Kur | Komisyon | Efektif Kur | Çevrilmiş Fiyat |
|------------|---------|----------|-------------|-----------------|
| EUR | 0.0267 | %2.5 | 0.0274 | 13,68 € |
| USD | 0.0293 | Yok | 0.0293 | 14,65 $ |
| GBP | 0.0230 | 0.002 sabit | 0.0250 | 12,50 £ |

Kur 0 ise veya para birimi bulunamıyorsa fiyat çevrilmeden olduğu gibi bırakılır.

### Fiyat Biçimlendirme

Her para birimi kendi biçimlendirme ayarlarıyla gösterilir. Bu değerler para birimi eklenirken WooCommerce ayarlarınızdan otomatik doldurulur.

| Ayar | Açıklama | Örnek |
|------|----------|-------|
| Simge | Para birimi simgesi | €, $, ₺, £ |
| Ondalık basamak | Kaç hane gösterileceği | 2 |
| Ondalık ayracı | Ondalık işareti | `,` veya `.` |
| Binler ayracı | Binlik işareti | `.` veya `,` |
| Simge konumu | Simgenin yeri | sol, sağ, sol boşluklu, sağ boşluklu |

**Konum örnekleri:**

| Konum | Görünüm |
|-------|---------|
| `left` | €50,00 |
| `left_space` | € 50,00 |
| `right` | 50,00€ |
| `right_space` | 50,00 € |

---

## 12. Dönüştürülen Fiyatlar

Ziyaretçi ana para birimi dışında bir para birimi seçtiğinde şunlar çevrilir:

| Alan | Durum |
|------|-------|
| Ürün fiyatı (normal ve indirimli) | Çevrilir, yuvarlama uygulanır |
| Varyasyon fiyatları ve fiyat aralıkları | Çevrilir, yuvarlama uygulanır |
| Sepet ve sipariş toplamları | Çevrilmiş ürün fiyatları üzerinden hesaplanır |
| Sepet ek ücretleri | Çevrilir |
| Kargo ücretleri | Çevrilir |
| Sabit tutarlı kuponlar | Çevrilir |
| Kuponun asgari/azami harcama sınırı | Çevrilir |

**Çevrilmeyenler:**

- **Yüzde indirimli kuponlar** — oran para biriminden bağımsız olduğu için olduğu gibi uygulanır.
- **Vergi tutarları kargo satırında ayrıca çevrilmez** — kargo ücretinin kendisi çevrilir.

### Siparişler

Sipariş oluşturulurken müşterinin kullandığı para birimi, o anki kur ve ana para birimi siparişe kaydedilir. Sipariş yönetiminde ve sipariş e-postalarında tutarlar müşterinin ödediği para birimiyle gösterilir. Eklenti daha önce oluşturulmuş siparişlerin kayıtlı tutarlarını değiştirmez; eklenti kurulmadan önce alınan siparişler olduğu gibi kalır.

---

## 13. CSS ile Özelleştirme

Görünümü temanızın stil dosyasından veya **Görünüm > Özelleştir > Ek CSS** bölümünden değiştirebilirsiniz. Aşağıdaki sınıflar eklentinin ürettiği işaretlemede gerçekten bulunur.

### Dönüştürücü Sınıfları

```css
/* Ana kapsayıcı */
.mhmcs-switcher { }

/* Boyut varyantları */
.mhmcs-size--small  { }
.mhmcs-size--medium { }
.mhmcs-size--large  { }

/* Seçim düğmesi */
.mhmcs-selected { }

/* Açılır liste */
.mhmcs-dropdown { }

/* Açılır liste açıkken */
.mhmcs-dropdown.mhmcs-open { }

/* Listedeki her bir seçenek */
.mhmcs-option { }

/* Seçili olan seçenek */
.mhmcs-active { }

/* Bayrak görseli */
.mhmcs-flag { }

/* Düğmedeki etiket metni */
.mhmcs-label { }

/* Açılır ok */
.mhmcs-arrow { }

/* Menüye eklendiğinde menü öğesi */
.menu-item.mhmcs-menu-item { }
```

### Ürün Fiyat Bileşeni Sınıfları

```css
/* Bileşen kapsayıcısı */
.mhmcs-product-prices { }

/* Her bir fiyat öğesi */
.mhmcs-product-price { }

/* Fiyatlar arasındaki ayırıcı */
.mhmcs-separator { }

/* Fiyat tutarı */
.mhmcs-amount { }
```

### Özelleştirme Örnekleri

**Dönüştürücü düğmesinin rengini değiştirme:**

```css
.mhmcs-selected {
    background-color: #1a1a2e;
    color: #ffffff;
    border-color: #16213e;
}
```

**Açılır menüyü genişletme:**

```css
.mhmcs-switcher .mhmcs-dropdown {
    min-width: 200px;
}
```

> Açılır listenin kuralları tema çakışmalarına karşı `!important` ile korunduğu için, konum ve görünürlük değerlerini geçersiz kılarken sizin de `.mhmcs-switcher .mhmcs-dropdown` gibi daha özgül bir seçici kullanmanız gerekebilir.

**Menüdeki dönüştürücüyü hizalama:**

```css
.menu-item.mhmcs-menu-item .mhmcs-dropdown {
    left: auto;
    right: 0;
    min-width: 160px;
}
```

**Ürün fiyat bileşenini büyütme:**

```css
.mhmcs-product-prices {
    font-size: 16px;
    color: #333;
}
```

480px altındaki ekranlarda fiyat bileşeni zaten alt alta dizilir ve ayırıcılar gizlenir.

---

## 14. REST API Referansı

**Ad alanı (namespace):** `mhmcs/v1`
**Temel URL:** `/wp-json/mhmcs/v1/`

### Yönetici Uç Noktaları

> Aşağıdaki uç noktaların tamamı `manage_woocommerce` yetkisi gerektirir. Yönetim panelinin kendisi de bu uç noktaları kullanır.

| Yöntem | Uç Nokta | Açıklama |
|--------|----------|----------|
| GET | `/settings` | Eklenti ayarlarını getirir |
| POST | `/settings` | Eklenti ayarlarını kaydeder |
| GET | `/currencies` | Ana para birimini ve yapılandırılmış para birimlerini getirir |
| POST | `/currencies` | Para birimlerini kaydeder |
| POST | `/rates/sync` | Kurları kaynaktan çekip günceller |
| GET | `/rates/preview` | Her para birimi için ham ve efektif kuru döndürür |
| POST | `/rates/preview` | Aynı önizlemeyi, yönetim paneli ayarları henüz kaydetmeden gönderdiği taslak değerlerle hesaplar |
| POST | `/cache-notice/snooze-anomaly` | Sepet-sabiti önbellek uyumsuzluğu bildirimini, o anda kayıtlı anomali için erteler |
| POST | `/cache-notice/snooze-fragments` | Mini sepet parçaları önbellek uyumsuzluğu bildirimini, o anda kayıtlı anomali için erteler |

### WooCommerce Ürün API'sinde Para Birimi

WooCommerce'in kendi ürün uç noktalarına `currency` parametresi ekleyerek fiyatları çevrilmiş olarak alabilirsiniz:

```bash
curl https://siteadiniz.com/wp-json/wc/v3/products?currency=EUR
```

Yanıttaki `price`, `regular_price` ve `sale_price` alanları çevrilir ve yanıta `currency_code` alanı eklenir. Parametre yalnızca mağazanızda etkin olan bir para birimi kodu içeriyorsa dikkate alınır.

---

## 15. Sık Sorulan Sorular

### Kaç para birimi ekleyebilirim?

Sınır yoktur. Yalnızca ürün sayfasındaki fiyat bileşeninde aynı anda en fazla 5 para birimi gösterilebilir.

### Döviz kurları ne sıklıkla güncellenir?

Siz nasıl ayarlarsanız: **Gelişmiş** sekmesinden saatlik, günde iki kez, günlük veya yalnızca manuel. Her zaman **Kurları Senkronize Et** düğmesiyle elle de güncelleyebilirsiniz. Çekilen kurlar 1 gün önbellekte tutulur.

### Kur kaynakları için API anahtarı almam gerekiyor mu?

Hayır. Kullanılan iki kaynak da ücretsizdir ve anahtar istemez.

### Dönüştürücüyü header'a nasıl eklerim?

Dört yol vardır:

1. **Navigasyon menüsü (önerilen):** Görünüm > Menüler'den **Para Birimi Dönüştürücü** öğesini menünüze ekleyin
2. **Widget alanı:** Temanızın header widget alanına bir "Kısa Kod" widget'ı ekleyip `[mhmcs_currency_switcher]` yazın
3. **Elementor:** Header şablonunuza **Currency Switcher** widget'ını sürükleyin
4. **PHP:** Temanızın şablon dosyasına `<?php echo do_shortcode( '[mhmcs_currency_switcher]' ); ?>` ekleyin

### Belirli bir ürüne sabit fiyat verebilir miyim?

Evet. Ürün düzenleme ekranındaki **Para Birimi Fiyatları** sekmesini kullanın. Ayrıntılar için [Ürün Bazında Sabit Fiyatlar](#5-ürün-bazında-sabit-fiyatlar) bölümüne bakın.

### Siparişler hangi para biriminde kaydedilir?

Müşterinin sipariş sırasında kullandığı para biriminde. Siparişe para birimi kodu, uygulanan kur ve mağazanın ana para birimi birlikte kaydedilir.

### WooCommerce HPOS ile uyumlu mu?

Evet. Eklenti, WooCommerce yüksek performanslı sipariş depolama (HPOS / Custom Order Tables) özelliğiyle uyumlu olduğunu bildirir.

### Bir para birimini kaldırmak verileri siler mi?

Hayır. Para birimini listeden çıkarmak yalnızca yapılandırmadan kaldırır. O para biriminde alınmış siparişler etkilenmez.

### Fiyatlar değişmiyor, ne kontrol etmeliyim?

1. Para biriminin **Etkin** olduğundan emin olun
2. Kurun sıfır olmadığını doğrulayın — sıfır kurda fiyat çevrilmeden bırakılır
3. **Kurları Senkronize Et** düğmesiyle güncel kuru çekin
4. Tarayıcı önbelleğini temizleyin (Ctrl+Shift+R)
5. Sayfa önbellekleme eklentiniz varsa önbelleği boşaltın — seçilen para birimi çerezde tutulduğu için önbelleğe alınmış sayfalar eski para birimini gösterebilir

### Eklentiyi silersem verilerim ne olur?

Eklentiyi WordPress üzerinden **sildiğinizde** ayarları, para birimi yapılandırması, zamanlanmış görevleri, önbelleğe alınmış kurları ve ürünlere girilmiş sabit fiyatları temizler. Yalnızca devre dışı bırakmak veri silmez.

---

**Sürüm:** 1.0.0
**Geliştirici:** [MaxHandMade](https://maxhandmade.com)
