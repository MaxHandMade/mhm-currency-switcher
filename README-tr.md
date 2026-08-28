<p align="center">
  <img src=".wordpress-org/banner-1544x500.png" alt="MHM Currency Switcher — WooCommerce için çok para birimli çözüm" width="900">
</p>

<p align="center">
  <a href="README.md">English</a> · <strong>Türkçe</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/s%C3%BCr%C3%BCm-2.1.0-blue" alt="Sürüm 2.1.0">
  <img src="https://img.shields.io/badge/WordPress-6.6%2B-21759b" alt="WordPress 6.6+">
  <img src="https://img.shields.io/badge/WooCommerce-7.4%2B-96588a" alt="WooCommerce 7.4+">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777bb4" alt="PHP 7.4+">
  <img src="https://img.shields.io/badge/lisans-GPLv2%2B-green" alt="GPLv2 veya sonrası">
</p>

# MHM Currency Switcher

WooCommerce için çok para birimi desteği. Ziyaretçi seçtiği para biriminde gezinir,
sepete ekler ve ödeme yapar; kurlar otomatik çekilir — ve bu, **sayfa önbelleğinin
arkasında da çalışır**, ki para birimi eklentilerinin çoğu tam orada kırılır.

## İçindekiler

- [Bu eklenti neden farklı](#bu-eklenti-neden-farklı)
- [Ekran görüntüleri](#ekran-görüntüleri)
- [Gereksinimler](#gereksinimler)
- [Kurulum](#kurulum)
- [Nasıl kullanılır](#nasıl-kullanılır)
- [Kısa kodlar](#kısa-kodlar)
- [WP-CLI](#wp-cli)
- [Önbellek uyumluluk modu](#önbellek-uyumluluk-modu)
- [Kabul edilen sınırlar](#kabul-edilen-sınırlar)
- [Geliştirme](#geliştirme)
- [Lisans](#lisans)

## Bu eklenti neden farklı

**Sayfa önbelleğinden sağ çıkıyor.** Sayfa önbelleği, ilk isteyene üretilen HTML'i
saklar; fiyatları sunucuda çeviren bir eklenti bu yüzden ilk ziyaretçinin para
birimini ondan sonraki herkese servis eder. Bu eklenti anonim katalog sayfalarını
mağazanın **temel para biriminde** basar — tek bir önbellek kopyası, herkes için
doğru — ve görünen fiyatları tarayıcı sonradan düzeltir. Sepet, ödeme, sipariş
toplamları ve sipariş e-postaları **her zaman** sunucuda hesaplanır; yani tahsil
edilen tutar tarayıcıdan değiştirilemez.

Geri kalan her şey bu karardan çıkar:

| | |
|---|---|
| **Gerçek döviz kurları** | ExchangeRate-API, erişilemezse ECB yedeği |
| **Para birimi başına denetim** | manuel ya da otomatik kur, komisyon (sabit veya yüzde) ve yuvarlama kuralları — her para birimi için ayrı |
| **Ürün başına sabit fiyat** | belirli bir ürün ve para birimi için çevrilmiş fiyatı geçersiz kıl |
| **Switcher'ı yerleştirmenin beş yolu** | iki kısa kod, iki Elementor widget'ı veya bir navigasyon menüsü öğesi |
| **Konum algılama** | ziyaretçinin ülkesini algılayıp uygun para birimini önceden seç |
| **283 bayrak simgesi** | SVG, eklentiyle birlikte gelir, dışarıya istek yok |
| **Türkçe dahil** | yönetim paneli ve vitrin tamamen çevrili |
| **WP-CLI** | kurları senkronla, bir para birimini incele, önbelleği boşalt, yapılandırılanları listele |

## Ekran görüntüleri

Canlı bir mağazanın navigasyon menüsündeki switcher ve ABD doları ile fiyatlanan
bir mağazayı ziyaretçi Türk lirası seçtiğinde gördüğü hâli:

<p>
  <img src=".wordpress-org/screenshot-2.png" alt="Bir sitenin navigasyon menüsünde para birimi switcher'ı; açılır liste Türk lirası, ABD doları, euro ve sterlini gösteriyor" width="420">
</p>

<p>
  <img src=".wordpress-org/screenshot-1.png" alt="Her fiyatı Türk lirasına çevrilmiş ürün ızgarası, üstünde switcher" width="700">
</p>

Ayarlar ekranı — önce para birimleri, sonra görünüm, sonra gerisi:

<p>
  <img src=".wordpress-org/screenshot-3.png" alt="Para Birimlerini Yönet sekmesi; euro, Türk lirası ve sterlin her biri kendi kur, komisyon ve yuvarlama denetimiyle listeli, her satırda müşterinin göreceği çevrilmiş fiyat" width="820">
</p>

<p>
  <img src=".wordpress-org/screenshot-6.png" alt="Nasıl kullanılır sekmesi; kısa kodları, Elementor widget'larını ve navigasyon menüsü öğesini belgeliyor, kısa kodlar kopyalanabilir" width="820">
</p>

## Gereksinimler

- WordPress 6.6 veya sonrası
- WooCommerce 7.4 veya sonrası
- PHP 7.4 veya sonrası

WordPress 6.6 bir tahmin değil, kesin bir taban: ayarlar ekranı bir React
uygulaması ve `react-jsx-runtime` script handle'ına bağımlı; WordPress çekirdeği
onu **ancak 6.6'dan itibaren** kaydediyor. 6.5 ve altında WordPress kurulumu
reddediyor.

## Kurulum

1. `mhm-currency-switcher` klasörünü `wp-content/plugins/` içine kopyalayın ya da
   release ZIP'ini **Eklentiler → Yeni Ekle → Eklenti Yükle** ile yükleyin.
2. **Eklentiler** ekranından etkinleştirin.
3. **WooCommerce → MHM Para Birimi**'ni açıp ilk para biriminizi ekleyin.

## Nasıl kullanılır

Para birimi eklemek vitrininize hiçbir şey koymaz — switcher'ın ziyaretçinin
erişebileceği bir yere **yerleştirilmesi** gerekir. Eklentinin kendi **Nasıl
kullanılır** sekmesi her seçeneği kopyalanabilir kodla listeler; kısaca:

| Nerede | Nasıl |
|---|---|
| Herhangi bir yazı, sayfa veya metin bileşeni | `[mhmcs_currency_switcher]` kısa kodu |
| Elementor | **Currency Switcher** widget'ını tasarıma sürükleyin |
| Navigasyon menüsü (menü veya bileşen destekleyen temalar) | **Görünüm → Menüler**, **Currency Switcher** öğesini ekleyin |
| Blok temalar | çekirdeğin **Kısa kod** bloğu — bu eklenti henüz kendi bloğunu sunmuyor |
| Bir ürün sayfasında, aynı anda birkaç para biriminde | `[mhmcs_currency_prices]` kısa kodu ya da **Currency Prices** Elementor widget'ı |

## Kısa kodlar

### `[mhmcs_currency_switcher]`

Ziyaretçinin para birimi seçtiği açılır liste.

| Öznitelik | Değerler | Varsayılan |
|---|---|---|
| `size` | `small`, `medium`, `large` | Görüntüleme Seçenekleri'nde kayıtlı boyut |

<img src=".wordpress-org/shot-switcher.png" alt="Açık switcher listesi; ABD doları, euro ve Türk lirası bayraklarıyla" width="150">

### `[mhmcs_currency_prices]`

Bir ürünün fiyatını aynı anda birkaç para biriminde gösterir.

| Öznitelik | Ne yapar |
|---|---|
| `currencies` | virgülle ayrılmış kodlar, örn. `USD,EUR`. Verilmezse Görüntüleme Seçenekleri'nde seçilen para birimleri kullanılır. Yapılandırmadığınız kodlar yok sayılır. |
| `product_id` | görüntülenen ürün yerine belirli bir ürünü fiyatlandır |
| `show_flags` | `true` ya da `false`; kayıtlı Görüntüleme Seçenekleri ayarını geçersiz kılar |
| `price` | ürün yerine sabit bir tutarı fiyatlandır — çoğunlukla bir yerleşimi denemek için |

<img src=".wordpress-org/shot-prices.png" alt="Bir ürün fiyatı euro ve Türk lirası olarak yan yana, bayraklarıyla" width="200">

## WP-CLI

```bash
wp mhmcs rates-sync          # güncel döviz kurlarını çek
wp mhmcs rates-get EUR       # bir para biriminin ham ve efektif kurunu göster
wp mhmcs cache-flush         # kur önbelleğini boşalt
wp mhmcs currencies-list     # yapılandırılmış para birimlerini listele
wp mhmcs status              # genel durum: temel para birimi, kurlar, zamanlama
```

## Önbellek uyumluluk modu

Varsayılan olarak açık; **WooCommerce → MHM Para Birimi → Gelişmiş** altında.

Anonim mağaza, arşiv ve ürün sayfaları temel para biriminde basılır ve tarayıcı
görünen fiyatları `POST /wp-json/mhmcs/v1/convert` üzerinden çevirir. Bu uç,
çağıranın belirttiği ürünleri fiyatlandırır ve **her tutarı sunucuda okur** —
tarayıcı bir fiyat dikte edemez. Sepet, ödeme, sipariş toplamları, sipariş
e-postaları ve WooCommerce REST API'si her zaman sunucuda, müşterinin gerçekten
seçtiği para biriminde çevrilir.

Modu kapatmak vitrini yine sunucuda çevirmeye döndürür — bu özellik var olmadan
önceki davranış. Karar vermeden önce aşağıdaki sınırları okuyun: iki ayarın da
sonuçları var ve bunlar birbirinden farklı.

## Kabul edilen sınırlar

Tam açıklamalar [readme.txt](readme.txt) içinde "Known limits" başlığı altında.
Kısaca:

- **Kapalı hâli, 1.1.0 öncesinin birebir aynısı değil.** Üç düzeltme bu ayarın
  üstünde durur ve her iki hâlde de geçerlidir: yönetim ekranlarında ve yönetim
  AJAX'ında çeviri yok, `wc/v3` okumaları temel para birimine sabitli, cron ve
  WP-CLI altında çeviri yok.
- **Tarayıcı botları temel fiyatları görür**, yapılandırılmış ürün verisi dahil.
- **Temel fiyatlar ilk boyamadan itibaren görünür** ve istek döndüğünde
  değiştirilir; her biri 200 ms içinde yumuşak geçişle. `prefers-reduced-motion`
  açıkken geçiş animasyonsuz olur.
- **JavaScript yoksa ya da uç erişilemezse** temel fiyatlar kalır, sebep tarayıcı
  konsoluna yazılır, görünür hiçbir şey bozulmaz.
- **Varyasyonlu ürünler** WooCommerce'in AJAX varyasyon yoluna zorlanır; bu yüzden
  `data-product_variations` değeri `false` olur ve fiyatları o JSON'dan okuyan
  üçüncü parti renk/beden eklentileri fiyat göstermeyi bırakabilir.
- **Mini sepet** temel para biriminde basılır ve WooCommerce'in sepet parçası
  yenilemesiyle düzeltilir. Parçalar devre dışı bırakılmışsa önbelleklenmiş mini
  sepet temelde kalır; eklenti bunu algılar ve yönetim panelinde uyarır.
- **WooCommerce'in tanımadığı bir sayfadaki sepet veya ödeme** ekranını
  önbellekten kendiniz dışlamalısınız.
- **`?currency=` önbellek kayıtlarını çoğaltır.** Switcher böyle adresler
  üretmez — çerez yazar ve adrese dokunmaz. Önbelleğe alınmış bir sayfada
  fiyatları olduğu yerde çevirir; sepet sayfasında, oturum açmış ziyaretçide
  ya da önbellek uyumluluğu kapalıyken aynı URL'yi yeniden yükler.
- **Giriş yapmış ziyaretçiler** sunucuda çevrilir; bu, önbelleğinizin onları
  atladığını varsayar. Kenarda (edge) önbellekliyorsanız bunu doğrulayın.
- **WooCommerce Analytics farklı para birimlerini toplar.** Dolarla verilen bir
  sipariş, dolar tutarıyla ama temel para biriminin sembolü altında raporlanır.

## Geliştirme

### Ön gereksinimler

Composer, Node.js 18+ ve entegrasyon testleri için Docker.

```bash
composer install
npm install && npm run build
```

### Kapılar

```bash
composer test              # PHPUnit, birim — dış bağımlılık yok
composer lint              # PHPCS
composer analyze           # PHPStan
npm run test:js            # Jest + jsdom, assets/js/ kapsamı
npm run lint:js            # ESLint
```

`npm run test:js` var, çünkü `assets/js/` vitrinin taşıyıcı yüzeyi ve hiçbir PHP
kapısı onu göremez.

### Entegrasyon testleri

Gerçek bir WordPress + WooCommerce kurulumu gerekir. Tek komutluk Docker
koşucusu Docker'dan başka hiçbir şey istemez ve CI'ın yaptığının aynısını yapar:

```bash
bin/test-integration-docker.sh                                    # WP en son
PHP_VERSION=7.4 WC_VERSION=9.1.4 bin/test-integration-docker.sh 6.6
```

Her koşumda tek bir WordPress/WooCommerce çifti sınanır; CI üç çift koşar — PHP
7.4/WP 6.6/WC 9.1.4, PHP 8.1/WP 6.8/WC 9.8.5, PHP 8.2/WP en son/WC en son. En alt
çift beyan edilen tabana sabitlidir.

Beyan edilen tabanın kendisini doğrulamak için — test süiti bunu yapamaz, çünkü
hiç yönetim sayfası yüklemez — bir ZIP üretip o sürümde açın:

```bash
python bin/build-release.py
bin/verify-wp-floor.sh up wordpress:6.6-php8.1-apache 8150 floor-ok build/mhm-currency-switcher.2.1.0.zip
bin/verify-wp-floor.sh down floor-ok
```

### Çeviriler

`bin/make-i18n.sh` tüm zinciri yeniden üretir — `.pot`, `.po`, `.mo`,
`.l10n.php` ve WordPress'in React uygulaması için aradığı md5 adlı JSON. Docker
içinde koşturun; betik kendini oraya yeniden çalıştırır, çünkü WP-CLI'ın eklenti
algılaması Windows'ta sessizce başarısız oluyor ve eksik string'li bir katalog
üretiyor.

## Lisans

GPLv2 veya sonrası. Bkz. [LICENSE](LICENSE).

## Yazar

[MaxHandMade](https://maxhandmade.com)
