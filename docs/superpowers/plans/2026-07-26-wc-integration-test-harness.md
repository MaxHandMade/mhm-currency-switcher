# WooCommerce Entegrasyon Test Altyapısı — Uygulama Planı (Plan B)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gerçek WordPress + WooCommerce üzerinde koşan bir PHPUnit entegrasyon suite'i kurmak ve CI'ın **iddia ettiği** WP sürümlerini gerçekten test eder hale getirmek.

**Architecture:** Mevcut saf-stub unit suite hızlı kalır ve hiç değişmez; yanına ayrı bir `Integration` suite eklenir. Sağlama yöntemi WordPress'in kanonik `wordpress-tests-lib` yolu — repo'nun `tests/bootstrap.php`'i bunu zaten destekliyor, yani en az değişiklikle çalışır. CI'da bir `mysql` service ile, yerelde (Windows host'ta `mysqli` yok) tek kullanımlık bir Docker konteynerinde koşar.

**Tech Stack:** PHP 7.4+ · WordPress 6.0 / 6.4 / latest · WooCommerce · PHPUnit 9.6 · `yoast/phpunit-polyfills` · GitHub Actions (`services: mysql`) · Docker (yerel)

**Kaynak:** Cache-friendly spec §9B (`docs/superpowers/specs/2026-07-26-cache-friendly-switcher-design.md`) entegrasyon-only test listesini sayar. Plan C'nin en kritik regresyon kilitleri bu altyapı olmadan **yazılamaz**, bu yüzden B, C'den önce gelir.

---

## Neden bu plan var — CI bugün yalan söylüyor

`.github/workflows/*.yml` içindeki `phpunit` job'ı şu matrisi ilan ediyor:

```yaml
matrix:
  include:
    - php: '7.4'
      wp: '6.0'
    - php: '8.1'
      wp: '6.4'
    - php: '8.2'
      wp: 'latest'
    - php: '8.3'
      wp: 'latest'
```

Ama adımları yalnızca `composer install` + `composer test`. **WordPress hiçbir yerde kurulmuyor**; `composer test` saf-stub unit suite'i koşuyor. Yani `wp` matris boyutu hiçbir şey yapmıyor — dört job aynı testi dört PHP sürümünde koşuyor, WP sürümü sahte.

Bunun bedeli 2026-07-26'da somut olarak ödendi: `render_shortcode( array $atts )` imzası WP **6.5 öncesinde** fatal veriyordu (`shortcode_parse_atts('')` string döner), `readme.txt` "Requires at least: 6.0" diyordu, CI'da "WP 6.0" job'ı **yeşildi**, ve hata ancak bağımsız bir final denetimiyle bulundu. Unit suite `render_shortcode()`'u doğrudan çağırdığı için `do_shortcode()` yolunu hiç görmüyor.

**Bu planın birinci işi CI'ın matrisini gerçek yapmak; ikinci işi o gerçekliğin yakalayabildiği testleri yazmak.**

## Global Constraints

- Prefix **`mhmcs_` / `MHMCS_`** — yeni her global tanımlayıcı.
- **PHP 7.4 tabanı.** `str_contains`, `match`, named arguments, enum, tipli sınıf sabiti **yok**.
- Metin domaini `mhm-currency-switcher`; kullanıcıya görünür her string `__()` içinde **literal**.
- **Unit suite'e dokunulmaz.** `vendor/bin/phpunit --no-coverage` her task sonunda yeşil olmalı ve **saniyeler** içinde bitmeli. ⚠️ **Task 1'den SONRA `WP_TESTS_DIR=/nonexistent` ön eki artık GEREKMİYOR ve kullanılmamalı** — Task 1 ayak kapanını kaldırdı; ön eki kullanmaya devam etmek düzeltmenin gerçekten çalıştığını gizler. Taban sayı Task 1 sonrası **120/0**. Entegrasyon testleri asla `Unit` suite'ine sızmaz.
- `composer lint` → **0 ERROR** (~20 önceden var olan WARNING sizin değil; toplamı `| tail` ile okuma, o yalnız son dosyanın bloğunu gösterir).
- `vendor/bin/phpstan analyse --memory-limit=1G` → **No errors** (`composer analyze` bu makinede OOM oluyor).
- Entegrasyon testleri **CI'da zorunlu**, yerelde **opsiyonel** — Docker'ı olmayan biri repo'da çalışabilmeli.

---

### Task 1: İki suite'i gerçekten ayır + bootstrap'ın ayak kapanını kaldır

Bugün `tests/bootstrap.php:428` şunu yapıyor: `$dir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib'; if ( is_dir( $dir ) ) { …WP boot… }`. Yani makinede **başka bir projeden kalma** bir `/tmp/wordpress-tests-lib` varsa, unit suite kendiliğinden WP entegrasyon yoluna girip `mysqli` yokluğundan fatal veriyor. Bu yüzden bu repoda herkes `WP_TESTS_DIR=/nonexistent` yazmak zorunda kalıyor — bir workaround'un kalıcılaşmış hâli.

**Files:**
- Modify: `tests/bootstrap.php` (WP dalının koşulu, ~satır 426-454)
- Create: `tests/bootstrap-integration.php`
- Modify: `phpunit.xml.dist`
- Modify: `composer.json` (`scripts`)

**Interfaces:**
- Consumes: yok
- Produces: `composer test` = yalnız Unit (stub) · `composer test:integration` = yalnız Integration (gerçek WP). Task 5 ve 6 bu iki komuta dayanır.

- [ ] **Step 1: Mevcut davranışı kilitleyen testi yaz**

`tests/Unit/BootstrapTest.php` oluştur:

```php
<?php
/**
 * Guards the unit bootstrap's isolation from any WordPress install.
 *
 * @package MhmCurrencySwitcher\Tests\Unit
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Class BootstrapTest
 */
class BootstrapTest extends TestCase {

	/**
	 * The unit suite must never boot WordPress, even when a tests-lib
	 * directory happens to exist on the machine.
	 *
	 * Regression: the bootstrap used to enter the WP path whenever
	 * `/tmp/wordpress-tests-lib` existed — a directory another project
	 * may have left behind — which made the unit suite fatal on hosts
	 * without mysqli and forced every contributor to pass
	 * WP_TESTS_DIR=/nonexistent by hand.
	 *
	 * @return void
	 */
	public function test_unit_suite_does_not_boot_wordpress(): void {
		$this->assertFalse(
			function_exists( 'wp_install' ),
			'The unit suite must run against stubs, not a real WordPress.'
		);
		$this->assertFalse(
			defined( 'MHMCS_INTEGRATION_TESTS' ),
			'MHMCS_INTEGRATION_TESTS must only be defined by the integration bootstrap.'
		);
	}
}
```

- [ ] **Step 2: Koş — YEŞİL beklenir**

```bash
WP_TESTS_DIR=/nonexistent vendor/bin/phpunit --testsuite Unit --filter test_unit_suite_does_not_boot_wordpress --no-coverage
```

Bu test mevcut doğru davranışı sabitler; Step 3'ün refactor'ünün onu bozmadığını kanıtlayacak. Kırmızı görmeden geçilen bilinçli bir kilit.

- [ ] **Step 3: WP dalını `tests/bootstrap.php`'ten TAMAMEN çıkar**

`tests/bootstrap.php` sonundaki `if ( is_dir( $mhmcs_wp_tests_dir ) ) { … }` bloğunu (ve `$mhmcs_wp_tests_dir` değişkenini) **sil**. Unit bootstrap artık yalnız stub kurar; hiçbir koşulda WordPress yüklemez.

- [ ] **Step 4: Entegrasyon bootstrap'ını oluştur**

`tests/bootstrap-integration.php`:

```php
<?php
/**
 * PHPUnit bootstrap for integration tests against a real WordPress.
 *
 * Requires a WordPress test library. Point WP_TESTS_DIR at it, or let
 * this file fall back to the conventional location.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

define( 'MHMCS_INTEGRATION_TESTS', true );

$mhmcs_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $mhmcs_tests_dir ) {
	$mhmcs_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! is_dir( $mhmcs_tests_dir . '/includes' ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test library at {$mhmcs_tests_dir}.\n" .
		"Run bin/install-wp-tests.sh first, or set WP_TESTS_DIR.\n"
	);
	exit( 1 );
}

require_once $mhmcs_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		$wc = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';

		if ( ! file_exists( $wc ) ) {
			fwrite( STDERR, "WooCommerce is not installed in the test environment.\n" );
			exit( 1 );
		}

		require $wc;
		require dirname( __DIR__ ) . '/mhm-currency-switcher.php';
	}
);

require $mhmcs_tests_dir . '/includes/bootstrap.php';
```

> **Neden WooCommerce yoksa `exit(1)`:** sessizce atlanan bir entegrasyon suite'i, koşmadığı hâlde yeşil görünür — bu planın var oluş sebebi olan yanılsamanın aynısı.

- [ ] **Step 5: `phpunit.xml.dist` — her suite kendi bootstrap'ını kullansın**

PHPUnit 9'da `bootstrap` niteliği dosya genelindedir, suite başına verilemez. Bu yüzden **ikinci bir config** dosyası kullanılır. `phpunit.xml.dist` içinden `Integration` testsuite'ini **kaldır** (Unit tek başına kalsın), ve `phpunit-integration.xml.dist` oluştur:

```xml
<?xml version="1.0"?>
<phpunit
	bootstrap="tests/bootstrap-integration.php"
	backupGlobals="false"
	colors="true"
	convertErrorsToExceptions="true"
	convertNoticesToExceptions="true"
	convertWarningsToExceptions="true"
>
	<testsuites>
		<testsuite name="Integration">
			<directory suffix="Test.php">./tests/Integration/</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

- [ ] **Step 6: composer script'leri**

`composer.json` `scripts` bölümü:

```json
"test": "phpunit",
"test:integration": "phpunit -c phpunit-integration.xml.dist",
```

- [ ] **Step 7: Doğrula**

```bash
vendor/bin/phpunit --no-coverage
```

Artık **`WP_TESTS_DIR` ön eki OLMADAN** çalışmalı ve 120/0 vermeli (119 + yeni bootstrap testi). Makinede `/tmp/wordpress-tests-lib` durmasına rağmen WP boot etmemeli — ayak kapanı kalktı.

```bash
vendor/bin/phpunit -c phpunit-integration.xml.dist --no-coverage
```

Test kütüphanesi henüz kurulu olmadığı için **anlaşılır bir hata** ile çıkmalı (sessizce başarılı olmamalı).

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "test: unit ve entegrasyon suite'lerini gerçekten ayır

Unit bootstrap artık hiçbir koşulda WordPress boot etmiyor. Eskiden
makinede başka bir projeden kalma /tmp/wordpress-tests-lib varsa
kendiliğinden WP yoluna girip mysqli yokluğundan fatal veriyordu;
bu yüzden herkes WP_TESTS_DIR=/nonexistent yazmak zorundaydı."
```

---

### Task 2: WordPress test kütüphanesi kurucusu

**Files:**
- Create: `bin/install-wp-tests.sh`
- Modify: `README.md` (geliştirici bölümü)

**Interfaces:**
- Consumes: Task 1'in `bootstrap-integration.php`'i (aynı `WP_TESTS_DIR` sözleşmesi)
- Produces: `bin/install-wp-tests.sh <db> <user> <pass> [host] [wp-version]` — WP core + tests-lib indirir, DB kurar, **ve WooCommerce'i kurar**. Task 4 (yerel) ve Task 5 (CI) bunu çağırır.

- [ ] **Step 1: Script'i yaz**

WordPress'in kanonik `install-wp-tests.sh`'ini temel al (`wp scaffold plugin-tests` üretir; `https://raw.githubusercontent.com/wp-cli/scaffold-command/main/templates/install-wp-tests.sh` referans). Üzerine **iki ekleme** yap:

1. **WooCommerce kurulumu** — sürümü sabitlenebilir olsun:
   ```bash
   WC_VERSION=${WC_VERSION-latest}
   install_woocommerce() {
       local plugin_dir="$WP_CORE_DIR/wp-content/plugins"
       mkdir -p "$plugin_dir"
       if [ "$WC_VERSION" = "latest" ]; then
           local url="https://downloads.wordpress.org/plugin/woocommerce.zip"
       else
           local url="https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip"
       fi
       download "$url" /tmp/woocommerce.zip
       unzip -q -o /tmp/woocommerce.zip -d "$plugin_dir"
   }
   ```
2. **Kurulan sürümleri yazdır** — sessiz sürüm sürüklenmesi olmasın:
   ```bash
   echo "[install-wp-tests] WP=${WP_VERSION} WC=${WC_VERSION} dir=${WP_TESTS_DIR}"
   ```

Script `set -euo pipefail` ile başlasın; `curl` yoksa `wget`'e düşsün.

- [ ] **Step 2: Elle bir kez koştur ve çıktısını rapora al**

Bu adım bir konteyner içinde yapılır (Windows host'ta `mysqli` yok — Task 4'e bak). Amaç: script'in gerçekten çalıştığını görmek, varsayımla geçmemek.

- [ ] **Step 3: README'ye geliştirici notu ekle**

`README.md`'ye kısa bir bölüm: unit testler bağımlılıksız koşar (`composer test`); entegrasyon testleri MySQL + WP test kütüphanesi ister (`bin/install-wp-tests.sh` → `composer test:integration`), ya da tek komutla Docker üzerinden (Task 4).

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "test: WP test kütüphanesi + WooCommerce kurucusu ekle"
```

---

### Task 3: İlk entegrasyon testi — bugün CI'ın kaçırdığı fatal

Bu task altyapının **değerini kanıtlar**: stub suite'in göremediği, gerçek WP'nin gördüğü bir hatayı yakalar.

**Files:**
- Create: `tests/Integration/ShortcodeRenderTest.php`

**Interfaces:**
- Consumes: Task 1 + Task 2
- Produces: `tests/Integration/` içindeki ilk gerçek test; sonraki task'lar bu dosyanın kalıbını izler.

- [ ] **Step 1: Testi yaz**

```php
<?php
/**
 * Integration tests for the plugin's shortcodes against real WordPress.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use WP_UnitTestCase;

/**
 * Class ShortcodeRenderTest
 */
class ShortcodeRenderTest extends WP_UnitTestCase {

	/**
	 * A bare shortcode with no attributes must render.
	 *
	 * Regression: before WordPress 6.5, shortcode_parse_atts() returns an
	 * empty STRING when a shortcode carries no attributes. The callbacks
	 * were typed `array $atts` under strict_types, so `[mhm_currency_switcher]`
	 * — the documented, most common usage — was a fatal on every WordPress
	 * between the declared minimum (6.0) and 6.5. The unit suite could not
	 * see it because it calls render_shortcode() directly instead of going
	 * through do_shortcode().
	 *
	 * @return void
	 */
	public function test_bare_switcher_shortcode_renders(): void {
		$output = do_shortcode( '[mhm_currency_switcher]' );

		$this->assertIsString( $output );
		$this->assertStringNotContainsString( 'Fatal error', $output );
	}

	/**
	 * The same for the product price shortcode.
	 *
	 * @return void
	 */
	public function test_bare_price_shortcode_renders(): void {
		$output = do_shortcode( '[mhm_currency_prices]' );

		$this->assertIsString( $output );
		$this->assertStringNotContainsString( 'Fatal error', $output );
	}

	/**
	 * WooCommerce must actually be loaded — otherwise this whole suite
	 * would pass while testing nothing.
	 *
	 * @return void
	 */
	public function test_woocommerce_is_active(): void {
		$this->assertTrue( class_exists( 'WooCommerce' ) );
	}
}
```

- [ ] **Step 2: WP 6.4 ile koş ve YEŞİL gör**

```bash
WP_VERSION=6.4 bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 6.4
composer test:integration
```

Beklenen: PASS (fatal `a6bbd72` ile düzeltildi).

- [ ] **Step 3: 🔴 Harness'in gerçekten yakaladığını KANITLA**

Bir kapı kurar kurmaz neyi göremediğini sına — aksi hâlde sahte güven üretir.

Geçici olarak `src/Frontend/Switcher.php`'deki imzayı eski hâline döndür (`array $atts = array()`), entegrasyon suite'ini tekrar koş, **testin düştüğünü gör**, sonra düzeltmeyi geri al. Kırmızı çıktıyı rapora **birebir** yapıştır. Bu adım atlanırsa harness'in değeri kanıtlanmamış olur.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "test(integration): çıplak shortcode render regresyon kilidi

WP 6.5 öncesi shortcode_parse_atts('') string döndürüyor; typed
array parametresi fatal veriyordu ve stub unit suite bunu göremiyordu.
Probe ile doğrulandı: imza geri alınınca test düşüyor."
```

---

### Task 4: Yerel Docker koşucusu (Windows host'ta `mysqli` yok)

**Files:**
- Create: `bin/test-integration-docker.sh`
- Modify: `README.md`

**Interfaces:**
- Consumes: Task 2 + Task 3
- Produces: `bin/test-integration-docker.sh [wp-version]` — tek komut, host'a hiçbir şey kurmadan entegrasyon suite'ini koşturur.

- [ ] **Step 1: Script'i yaz**

Tek kullanımlık bir `mysql:8` konteyneri + `php:7.4-cli` (veya matris PHP'si) konteyneri ayağa kaldır, repo'yu mount et, `install-wp-tests.sh`'i içeride koştur, `composer test:integration` çalıştır, sonra **her durumda** konteynerleri temizle (`trap … EXIT`). Docker yoksa anlaşılır bir mesajla çık.

Konteyner içinde gereken PHP eklentileri: `mysqli`, `zip`, `gd` (WC için). `docker-php-ext-install mysqli` yeterli.

- [ ] **Step 2: Koştur ve YEŞİL gör** — çıktıyı rapora al.

- [ ] **Step 3: İki kez üst üste koştur** — ikinci koşum da temiz geçmeli (artık konteyner/veritabanı kalıntısı yok).

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "test: yerel entegrasyon koşucusu (Docker) ekle"
```

---

### Task 5: CI matrisini GERÇEK yap

**Files:**
- Modify: `.github/workflows/` içindeki test workflow'u

**Interfaces:**
- Consumes: Task 2
- Produces: `wp` matris boyutu artık gerçekten o WP sürümünü kurup entegrasyon suite'ini koşuyor.

- [ ] **Step 1: `phpunit` job'ına MySQL service ekle**

```yaml
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ALLOW_EMPTY_PASSWORD: 'yes'
          MYSQL_DATABASE: wordpress_test
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=5
```

- [ ] **Step 2: Kurulum + entegrasyon adımlarını ekle**

`Run PHPUnit` adımından **sonra**:

```yaml
      - name: Install WordPress test library and WooCommerce
        run: bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 ${{ matrix.wp }}

      - name: Run PHPUnit (integration)
        run: composer test:integration
```

`setup-php` adımına `extensions: mysqli` ekle.

- [ ] **Step 3: 🔴 Matrisin artık gerçek olduğunu KANITLA**

Bir dal aç, Task 3'ün probe'unu **kalıcı olarak** uygula (imzayı bozan tek satırlık bir commit), push et ve **CI'ın WP 6.0 job'ının KIRMIZI olduğunu gör**. Ekran çıktısını rapora al, sonra commit'i geri al.

Bu, planın merkezî iddiasının kanıtıdır: eskiden aynı bozukluk yeşil geçiyordu. Bu adım atlanırsa matrisin gerçekten gerçekleştiğini kimse bilmez.

- [ ] **Step 4: Commit + push + CI yeşil doğrula**

```bash
git add -A
git commit -m "ci: WP matrisini gerçek yap — WordPress+WooCommerce kur ve entegrasyon suite'ini koştur

Matris wp: 6.0/6.4/latest ilan ediyordu ama WordPress hiç kurulmuyordu;
dört job aynı stub testini dört PHP sürümünde koşuyordu. WP boyutu
dekoratifti ve bu yüzden WP 6.0'da fatal veren bir imza yeşil CI'dan
geçti."
git push
gh run list --branch <dal> --limit 1 --json databaseId --jq '.[0].databaseId'
gh run watch <ID> --exit-status
gh run view <ID> --json conclusion,jobs
```

Beklenen: tüm job'lar success, **ve** entegrasyon adımının log'unda gerçekten kurulan WP/WC sürümleri görünüyor.

---

### Task 6: Plan C'nin gerektirdiği entegrasyon testleri

Spec §9B'nin "yalnız gerçek WC ile test edilebilir" dediği kilitler. Bunlar Plan C'nin ön koşulu — C'nin bağlam-ayrımı mimarisi bu testler olmadan doğrulanamaz.

**Files:**
- Create: `tests/Integration/PriceHtmlShapesTest.php`
- Create: `tests/Integration/VariationPricesTest.php`
- Create: `tests/Integration/CartAndFeesTest.php`
- Create: `tests/Integration/RestConversionTest.php`

**Interfaces:**
- Consumes: Task 1-5
- Produces: Plan C'nin §9B kilitlerinin koşabileceği zemin.

- [ ] **Step 1: `price_html` şekilleri**

Basit / indirimli / aralıklı (variable) / varyasyon / sabit-fiyatlı ürün oluştur (`WC_Helper_Product` ya da elle `WC_Product_Simple`/`WC_Product_Variable`), bir para birimi yapılandır, `$product->get_price_html()` çıktısının dönüştürülmüş tutarı **ve** doğru sembolü taşıdığını doğrula. Her şekil ayrı test metodu.

- [ ] **Step 2: Variation-prices transient**

`woocommerce_get_variation_prices_hash` filtresinin para birimini hash'e kattığını gerçek `get_variation_prices()` çağrısıyla doğrula: para değiştir → aralık değişmeli; aynı para → transient'ten aynı sonuç.

> Plan C bu hash'e **bağlam** da ekleyecek; bu test o değişikliğin regresyon kilidinin temelidir.

- [ ] **Step 3: Sepet + ücret + kupon**

Sepete ürün ekle, `WC()->cart->calculate_totals()` çağır, kalem fiyatının / ücretlerin / kupon indiriminin **aynı** para biriminde olduğunu doğrula. `CartFilter::recalculate_fees` ve `CouponFilter::convert_coupon_amount` bu testin kapsamında.

- [ ] **Step 4: REST çifte dönüşüm YOK**

`rest_do_request()` ile `/wc/v3/products/<id>` çağır (kimlik doğrulamalı), hem `?currency=EUR` ile hem parametresiz. Beklenen: parametresiz → temel para; parametreli → **bir kez** dönüşmüş. `RestApiFilter` + `PriceFilter` üst üste binmemeli.

- [ ] **Step 5: Her testin ayırt edici olduğunu kanıtla**

Her yeni test için ilgili üretim davranışını geçici olarak boz, **testin düştüğünü gör**, geri al. Boş geçen bir entegrasyon testi, hiç test olmamasından kötüdür — yeşil görünür.

- [ ] **Step 6: Kapılar + Commit + CI**

```bash
vendor/bin/phpunit --no-coverage                       # Unit hâlâ hızlı ve yeşil
bin/test-integration-docker.sh                          # Integration yeşil
composer lint
vendor/bin/phpstan analyse --memory-limit=1G
git add -A && git commit -m "test(integration): fiyat şekilleri, varyasyon transient'i, sepet/ücret, REST"
git push
```

Push sonrası CI'ı `gh run watch --exit-status` + `gh run view --json conclusion` ile yeşil doğrula.

---

## Kapsam dışı (bilinçli)

- **Cache-friendly switcher'ın kendisi** → Plan C. Bu plan yalnız zemini kurar.
- **Tarayıcı/E2E testleri** (Playwright vb.) — ayrı bir yatırım; Chrome DevTools MCP ile elle doğrulama süregeliyor.
- **Kod kapsamı raporlaması** — entegrasyon suite'i yavaş; coverage ayrı bir karar.
- **Unit suite'in stub'larını gerçek WP ile değiştirmek** — hız kasıtlı; iki suite bir arada yaşar.
