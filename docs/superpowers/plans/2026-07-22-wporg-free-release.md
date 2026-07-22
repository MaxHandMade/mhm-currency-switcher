# MHM Currency Switcher — WordPress.org Ücretsiz Sürüm Uygulama Planı

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lisans alt sistemini ve para birimi kotasını tamamen kaldırıp `mhm-currency-switcher` eklentisini tek, tamamen ücretsiz bir paket olarak WordPress.org'a gönderilebilir hale getirmek (v1.0.0).

**Architecture:** Bu bir *carve* değil, sınırlı bir alt sistemin *silinmesi*. `src/License/` dizini (7 dosya) ve ona bağlı 10 gate çağrısı kaldırılır; altı özellik koşulsuz çalışır. Ardından prefix tek-token `mhmcs_`'e taşınır ve WP.org'un istediği paket hijyeni (GPLv2 başlığı, sahiplik URI'ları, `uninstall.php`, dış servis dokümantasyonu) tamamlanır. İşin doğru bittiğinin kanıtı bir grep değişmezi ve baseline'sız PHPStan level 0 taramasıdır.

**Tech Stack:** PHP 7.4+ · WordPress 6.0+ · WooCommerce 7.0+ · PHPUnit (saf unit testler, WP bağımlılığı yok) · PHPCS (WPCS) · PHPStan L6 · React (`@wordpress/scripts`) admin uygulaması

**Spec:** `docs/superpowers/specs/2026-07-22-wporg-free-release-design.md`

---

## Global Constraints

Aşağıdakiler **her task için** geçerlidir; her task'ın gereksinimlerine örtük olarak dahildir.

- **PHP tabanı:** `composer.json` `php >= 7.4`. `str_contains`, `str_starts_with`, `str_ends_with`, `match`, constructor property promotion, enum **kullanma**. (v0.5.1'de `str_ends_with()` PHP 7.4 CI matrisini kırdı.)
- **Her PHP dosyası** `declare(strict_types=1);` ile başlar ve `if ( ! defined( 'ABSPATH' ) ) { exit; }` guard'ı içerir. **BOM yasak** — dosya `<?php` ile başlamalı, aksi halde `strict_types` fatal verir.
- **Textdomain:** `mhm-currency-switcher` — **değişmez**. Tüm gettext çağrıları literal string + literal domain: `__( 'Metin', 'mhm-currency-switcher' )`. Değişken geçme, wrapper sınıf kullanma.
- **Prefix:** Task 5'ten itibaren tüm yeni PHP sabitleri `MHMCS_`, tüm option/meta/transient/cookie/hook adları `mhmcs_` (iç alt çizgi yok, tek token).
- **Kodlama standardı:** WPCS. `array()` uzun sözdizimi (kısa `[]` değil), Yoda koşulları, tab indent.
- **Escape:** çıkışta `esc_html()`/`esc_attr()`/`esc_url()`/`wp_kses_post()`. Callback dönüş değerleri de escape edilir.
- **Commit mesajları** Türkçe gövde + İngilizce conventional prefix (`feat:`, `fix:`, `refactor:`, `docs:`, `chore:`, `test:`).
- **Dal:** tüm iş `feature/wporg-free-release` dalında; `develop`'a merge Task 12'den sonra.
- **Yasak:** Herhangi bir yeni lisans/Pro/upgrade/limit kavramı eklemek. Bu eklentide artık "Pro" diye bir şey yoktur.

---

## Dosya Yapısı

**Silinecek dosyalar (14 kaynak + 13 test):**

| Dosya | Sorumluluk (kaldırılıyor) |
|---|---|
| `src/License/LicenseManager.php` | Lisans aktivasyon/doğrulama/cron |
| `src/License/Mode.php` | Lite/Pro gate'leri + kota |
| `src/License/ClientSecrets.php` | wp-config secret okuyucu |
| `src/License/ResponseVerifier.php` | Sunucu yanıtı imza doğrulama |
| `src/License/FeatureTokenVerifier.php` | RSA feature token doğrulama |
| `src/License/LicenseServerPublicKey.php` | Gömülü RSA public key |
| `src/License/VerifyEndpoint.php` | Ters-doğrulama REST endpoint'i |
| `admin-app/src/components/shared/ProGate.jsx` | Pro içerik kilidi (bulanık overlay) |
| `admin-app/src/components/tabs/License.jsx` | License sekmesi |
| `tests/Unit/License/*.php` (10 dosya) | Lisans testleri |
| `tests/Unit/REST/ManageSubscriptionEndpointTest.php` | Polar portal endpoint testi |

**Değiştirilecek dosyalar:**

| Dosya | Değişiklik |
|---|---|
| `src/Plugin.php` | 4 gate + Phase 6 (lisans bootstrap) kaldırılır |
| `src/Admin/RestAPI.php` | 4 lisans rotası, `is_pro` payload, 2 gate, kota çağrısı kaldırılır |
| `src/Admin/Settings.php` | Re-validate handler, throttle, lisans localize verisi kaldırılır |
| `src/Core/CurrencyStore.php` | `enforce_limit()` + `set_free_limit()` + `$free_limit` kaldırılır |
| `src/Integration/WooCommerce/ProductPricing.php` | `can_use_fixed_prices()` guard'ı kaldırılır |
| `src/CLI/Commands.php` | "Pro/Lite" mod etiketi kaldırılır |
| `src/Integration/Elementor/*.php` (3) | `phpcs:ignoreFile` → satır bazlı escape |
| `admin-app/src/App.jsx` | 5→4 sekme, `isPro` kaldırılır |
| `admin-app/src/components/tabs/{AdvancedSettings,CheckoutOptions}.jsx` | `ProGate` sarmalayıcısı kaldırılır |
| `tests/bootstrap.php` | Lisans fixture enjeksiyonu kaldırılır |
| `mhm-currency-switcher.php` | Sabitler, header (lisans/URI/sürüm) |
| `readme.txt` | Baştan yazılır |

**Oluşturulacak dosyalar:**

| Dosya | Sorumluluk |
|---|---|
| `uninstall.php` | Kaldırma sırasında option/meta/transient/cron temizliği |
| `bin/check-no-license-refs.sh` | Uyum oracle'ı — lisans kalıntısı taraması (CI kapısı) |
| `tests/Unit/Compliance/NoLicenseSurfaceTest.php` | Kota ve lisans API'sinin geri gelmesini engelleyen regresyon testi |

---

## Görev Sırası ve Gerekçesi

1–2 numaralı task'lar **davranışı** düzeltir (kota + kapılar), 3–4 **kodu** siler, 5 prefix'i taşır, 6–10 paket hijyenini tamamlar, 11–12 doğrulama ve kapanış.

Bu sıra bilinçlidir: önce özellikler açılır ve testlerle kanıtlanır, **sonra** ölü kod silinir. Tersi sırada silme, hâlâ ayakta duran çağrı yerlerini fatal'e düşürür.

---

### Task 1: Para birimi kotasını kaldır

WordPress.org Guideline 5'in birebir ihlali burasıdır ("Functionality may not be disabled after a trial period or **quota** is met"). En kritik tek değişiklik.

**Files:**
- Modify: `src/Core/CurrencyStore.php:57`, `:107-115`, `:220-229`
- Modify: `src/Admin/RestAPI.php:406-409`
- Create: `tests/Unit/Compliance/NoLicenseSurfaceTest.php`

**Interfaces:**
- Consumes: yok (ilk task)
- Produces: `MhmCurrencySwitcher\Tests\Unit\Compliance\NoLicenseSurfaceTest` — Task 3 ve Task 5 bu dosyaya yeni assertion ekler.

- [ ] **Step 1: Dalı oluştur**

```bash
cd /c/projects/mhm-currency-switcher
git checkout develop
git checkout -b feature/wporg-free-release
```

- [ ] **Step 2: Başarısız olan uyum testini yaz**

Yeni dosya `tests/Unit/Compliance/NoLicenseSurfaceTest.php`:

```php
<?php
/**
 * Compliance regression tests — WordPress.org Guideline 5.
 *
 * These tests fail if any licence gate, quota, or Pro-tier API is
 * reintroduced into the plugin. They are the machine-checkable form of
 * the "no restricted or locked functionality" rule.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use MhmCurrencySwitcher\Core\CurrencyStore;
use PHPUnit\Framework\TestCase;

/**
 * Class NoLicenseSurfaceTest
 *
 * @coversNothing
 */
class NoLicenseSurfaceTest extends TestCase {

	/**
	 * CurrencyStore must not expose any free-tier quota API.
	 *
	 * @return void
	 */
	public function test_currency_store_has_no_quota_api(): void {
		$this->assertFalse(
			method_exists( CurrencyStore::class, 'enforce_limit' ),
			'CurrencyStore::enforce_limit() is a free-tier quota — forbidden by WP.org Guideline 5.'
		);

		$this->assertFalse(
			method_exists( CurrencyStore::class, 'set_free_limit' ),
			'CurrencyStore::set_free_limit() is a free-tier quota — forbidden by WP.org Guideline 5.'
		);
	}

	/**
	 * An unlimited number of currencies must survive a store round-trip.
	 *
	 * @return void
	 */
	public function test_store_retains_more_than_two_currencies(): void {
		$store = new CurrencyStore();
		$codes = array( 'EUR', 'GBP', 'TRY', 'JPY', 'CHF' );

		$currencies = array();
		foreach ( $codes as $index => $code ) {
			$currencies[] = array(
				'code'            => $code,
				'enabled'         => true,
				'sort_order'      => $index,
				'rate'            => array(
					'type'  => 'auto',
					'value' => 1.0,
				),
				'fee'             => array(
					'type'  => 'fixed',
					'value' => 0,
				),
				'rounding'        => array(
					'type'     => 'disabled',
					'value'    => 0,
					'subtract' => 0,
				),
				'format'          => array(
					'symbol'       => $code,
					'position'     => 'left',
					'thousand_sep' => ',',
					'decimal_sep'  => '.',
					'decimals'     => 2,
				),
				'payment_methods' => array( 'all' ),
				'countries'       => array(),
			);
		}

		$store->set_data( 'USD', $currencies );

		$this->assertCount(
			5,
			$store->get_currencies(),
			'All five currencies must be retained — there is no free-tier cap.'
		);
	}
}
```

- [ ] **Step 3: Testi çalıştır, başarısız olduğunu doğrula**

```bash
vendor/bin/phpunit tests/Unit/Compliance/NoLicenseSurfaceTest.php
```

Beklenen: `test_currency_store_has_no_quota_api` **FAIL** — "CurrencyStore::enforce_limit() is a free-tier quota". (`test_store_retains_more_than_two_currencies` zaten geçer; `enforce_limit()` yalnız REST katmanından çağrılıyor.)

- [ ] **Step 4: `CurrencyStore`'dan kota API'sini kaldır**

`src/Core/CurrencyStore.php` içinde şu üç öğeyi sil:

1. Satır 57 civarındaki özellik tanımı:

```php
	private int $free_limit = 2;
```

2. `set_free_limit()` metodunun tamamı (DocBlock dahil, ~satır 107-115).

3. `enforce_limit()` metodunun tamamı (DocBlock dahil, ~satır 218-229):

```php
	public function enforce_limit( array $currencies ): array {
		return array_slice( $currencies, 0, $this->free_limit );
	}
```

- [ ] **Step 5: REST katmanındaki kota zorlamasını kaldır**

`src/Admin/RestAPI.php` içinde şu bloğu **tamamen** sil (~satır 406-409):

```php
		// Enforce the free-tier currency limit (Pro users are unlimited).
		if ( Mode::is_lite() ) {
			$currencies = $this->store->enforce_limit( $currencies );
		}
```

- [ ] **Step 6: Testi çalıştır, geçtiğini doğrula**

```bash
vendor/bin/phpunit tests/Unit/Compliance/NoLicenseSurfaceTest.php
```

Beklenen: **OK (2 tests)**

- [ ] **Step 7: Tüm suite'i çalıştır**

```bash
vendor/bin/phpunit
```

Beklenen: `CurrencyStoreTest` içinde `enforce_limit`/`set_free_limit` çağıran testler varsa **FAIL** eder. O testleri sil (kota artık yok, testi de olmamalı). Diğer her şey yeşil kalmalı.

- [ ] **Step 8: Commit**

```bash
git add src/Core/CurrencyStore.php src/Admin/RestAPI.php tests/
git commit -m "feat: para birimi kotasını kaldır (WP.org Guideline 5)

CurrencyStore::enforce_limit() + set_free_limit() + \$free_limit ve
RestAPI'deki Mode::is_lite() zorlaması silindi. Para birimi sayısı
artık sınırsız.

Kotanın geri gelmesini engelleyen NoLicenseSurfaceTest eklendi."
```

---

### Task 2: Altı özelliğin kapısını aç

**Files:**
- Modify: `src/Plugin.php:119-126`, `:147-151`, `:210-211`, `:251-255`
- Modify: `src/Admin/RestAPI.php:341-346`
- Modify: `src/Integration/WooCommerce/ProductPricing.php:60-63`
- Modify: `tests/Unit/Compliance/NoLicenseSurfaceTest.php`

**Interfaces:**
- Consumes: Task 1'in `NoLicenseSurfaceTest` dosyası
- Produces: `ProductPricing::init()` artık koşulsuz hook kaydeder — Task 4'teki React değişikliği bu davranışa dayanır.

- [ ] **Step 1: Başarısız olan testi yaz**

`tests/Unit/Compliance/NoLicenseSurfaceTest.php` dosyasına şu metodu ekle. **Yeni `use` satırı ekleme** — test sınıfı okumakla çalışır, sınıfı örneklemez; kullanılmayan import PHPCS uyarısı üretir.

```php
	/**
	 * ProductPricing must register its hooks unconditionally.
	 *
	 * Fixed per-product prices used to be gated behind a licence check;
	 * a `Mode::` reference in this class means the gate came back.
	 *
	 * @return void
	 */
	public function test_product_pricing_is_not_licence_gated(): void {
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Integration/WooCommerce/ProductPricing.php'
		);

		$this->assertIsString( $source, 'ProductPricing.php must be readable.' );

		$this->assertStringNotContainsString(
			'Mode::',
			$source,
			'ProductPricing must not consult a licence gate.'
		);
	}
```

- [ ] **Step 2: Testi çalıştır, başarısız olduğunu doğrula**

```bash
vendor/bin/phpunit tests/Unit/Compliance/NoLicenseSurfaceTest.php
```

Beklenen: `test_product_pricing_is_not_licence_gated` **FAIL** — "ProductPricing must not consult a licence gate."

- [ ] **Step 3: `ProductPricing` guard'ını kaldır**

`src/Integration/WooCommerce/ProductPricing.php` — `init()` metodunun başındaki bloğu sil:

```php
		if ( ! Mode::can_use_fixed_prices() ) {
			return;
		}
```

Dosyanın başındaki `use MhmCurrencySwitcher\License\Mode;` satırını da sil.

- [ ] **Step 4: `Plugin.php`'deki dört kapıyı aç**

`src/Plugin.php`:

**4a — Geolocation (~satır 119).** Bunu:

```php
		// Geolocation (Pro only).
		if ( Mode::can_use_geolocation() ) {
			$geo_service = new GeolocationService();
			$settings    = get_option( 'mhm_currency_switcher_settings', array() );
			$geo_enabled = is_array( $settings ) && ! empty( $settings['auto_detect'] );

			$detection->set_geolocation( $geo_service, $geo_enabled );
		}
```

şununla değiştir:

```php
		// Geolocation-based currency detection.
		$geo_service = new GeolocationService();
		$settings    = get_option( 'mhm_currency_switcher_settings', array() );
		$geo_enabled = is_array( $settings ) && ! empty( $settings['auto_detect'] );

		$detection->set_geolocation( $geo_service, $geo_enabled );
```

**4b — REST API filtresi (~satır 147).** Bunu:

```php
		// ─── Phase 3: Pro-only WC filters ────────────────────────────
		if ( Mode::can_use_rest_api_filter() ) {
			$rest_api_filter = new RestApiFilter( $converter, $store );
			$rest_api_filter->init();
		}
```

şununla değiştir:

```php
		// ─── Phase 3: WooCommerce REST API currency filter ───────────
		$rest_api_filter = new RestApiFilter( $converter, $store );
		$rest_api_filter->init();
```

**4c — Zamanlanmış kur güncelleme (~satır 210).** `if ( Mode::can_use_auto_rate_update() ) {` satırını ve onun kapanış `}` süslü parantezini kaldır; içerdiği bloğun girintisini bir seviye azalt. Yorum satırını `// ─── Phase 9: Scheduled tasks ────────────────────────────────` olarak güncelle.

> **Dikkat:** Bu blok içinde bir `else { wp_clear_scheduled_hook( 'mhm_cs_update_rates' ); }` dalı var. Kapı kalktığı için `else` dalı ölür — onu da sil. Yalnızca `if` gövdesi kalır.

**4d — Rentiva uyumluluk modülü (~satır 251).** Bunu:

```php
		// ─── Phase 10: Compatibility modules (Pro only) ──────────────
		if ( Mode::is_pro() && MhmRentiva::is_active() ) {
```

şununla değiştir:

```php
		// ─── Phase 10: Compatibility modules ─────────────────────────
		if ( MhmRentiva::is_active() ) {
```

- [ ] **Step 5: REST cron zamanlamasındaki kapıyı aç**

`src/Admin/RestAPI.php` (~satır 341) — bu koşulu:

```php
			if ( 'manual' !== $new_interval
				&& in_array( $new_interval, array( 'hourly', 'twicedaily', 'daily' ), true )
				&& Mode::can_use_auto_rate_update()
			) {
```

şununla değiştir:

```php
			if ( 'manual' !== $new_interval
				&& in_array( $new_interval, array( 'hourly', 'twicedaily', 'daily' ), true )
			) {
```

- [ ] **Step 6: Testleri çalıştır**

```bash
vendor/bin/phpunit
```

Beklenen: `NoLicenseSurfaceTest` **OK (3 tests)**. Suite'in geri kalanı yeşil. (`PluginTest` veya `RestApiFilterTest` lisanssız durumda "özellik kapalı" varsayan bir assertion içeriyorsa, o assertion artık **yanlıştır** — güncelle, çünkü özellik artık her zaman açık.)

- [ ] **Step 7: Commit**

```bash
git add src/ tests/
git commit -m "feat: altı özelliğin lisans kapısını kaldır

Geolocation, sabit ürün fiyatı, otomatik kur güncelleme, REST API
filtresi, ödeme kısıtı ve Rentiva uyumluluk modülü artık koşulsuz
çalışıyor. WP.org Guideline 5 gereği hiçbir özellik kilitli değil."
```

---

### Task 3: Lisans alt sistemini sil (PHP)

**Files:**
- Delete: `src/License/` (7 dosya)
- Delete: `tests/Unit/License/` (10 dosya), `tests/Unit/REST/ManageSubscriptionEndpointTest.php`
- Modify: `src/Plugin.php:39-41`, `:180-190`
- Modify: `src/Admin/RestAPI.php:23-24`, `:165-215`, `:245-257`
- Modify: `src/Admin/Settings.php`
- Modify: `src/CLI/Commands.php:23`, `:229`
- Modify: `tests/bootstrap.php:430-455`
- Create: `bin/check-no-license-refs.sh`

**Interfaces:**
- Consumes: Task 1-2 (tüm gate çağrıları zaten kaldırılmış olmalı)
- Produces: `bin/check-no-license-refs.sh` — Task 12'de CI kapısı olarak bağlanır.

- [ ] **Step 1: Uyum oracle'ını yaz**

Yeni dosya `bin/check-no-license-refs.sh`:

```bash
#!/usr/bin/env bash
#
# WP.org Guideline 5 compliance gate.
#
# Fails when any licence-gating, Pro-tier, or quota surface exists in the
# shipped source. Run in CI on every push.
#
set -uo pipefail

cd "$(dirname "$0")/.." || exit 2

STATUS=0

check() {
	local label="$1"
	local pattern="$2"
	shift 2

	local hits
	hits=$(grep -rniE "$pattern" "$@" 2>/dev/null || true)

	if [ -n "$hits" ]; then
		echo "FAIL [$label]"
		echo "$hits"
		echo
		STATUS=1
	else
		echo "ok   [$label]"
	fi
}

check "License namespace" 'MhmCurrencySwitcher\\\\License|License\\\\(LicenseManager|Mode|ClientSecrets)' src/
check "Mode gates"        'Mode::' src/
check "Quota"             'enforce_limit|free_limit|currency_limit' src/
check "Dev bypass"        'MHM_CS_DEV_PRO|MHMCS_DEV_PRO' src/ admin-app/src/
check "Pro UI"            'ProGate|isPro|is_pro' src/ admin-app/src/

if [ -d src/License ]; then
	echo "FAIL [src/License directory still exists]"
	STATUS=1
else
	echo "ok   [src/License directory absent]"
fi

if [ "$STATUS" -eq 0 ]; then
	echo
	echo "PASS — no licence-gating surface found."
else
	echo
	echo "FAILED — licence-gating surface detected (WP.org Guideline 5)."
fi

exit "$STATUS"
```

Çalıştırılabilir yap:

```bash
chmod +x bin/check-no-license-refs.sh
```

- [ ] **Step 2: Oracle'ı çalıştır, başarısız olduğunu doğrula**

```bash
bin/check-no-license-refs.sh
```

Beklenen: **FAILED** — birden çok `FAIL [...]` satırı (License namespace, Mode gates, Pro UI, src/License directory).

- [ ] **Step 3: Lisans kaynak dosyalarını ve testlerini sil**

```bash
git rm -r src/License
git rm -r tests/Unit/License
git rm tests/Unit/REST/ManageSubscriptionEndpointTest.php
```

- [ ] **Step 4: `Plugin.php`'den lisans bootstrap'ını kaldır**

`src/Plugin.php`:

1. Şu üç `use` satırını sil:

```php
use MhmCurrencySwitcher\License\LicenseManager;
use MhmCurrencySwitcher\License\Mode;
use MhmCurrencySwitcher\License\VerifyEndpoint;
```

2. "Phase 6: License management" bloğunu **tamamen** sil (~satır 182-189) — `LicenseManager::instance()` çağrısı, ona bağlı cron/hook kayıtları ve `VerifyEndpoint::register();` dahil.

3. Kalan faz numaralarını **yeniden numaralandırma** — yorumlardaki boşluk kabul edilebilir ve gereksiz diff üretmez.

- [ ] **Step 5: `RestAPI.php`'den lisans rotalarını ve `is_pro` payload'ını kaldır**

`src/Admin/RestAPI.php`:

1. Şu iki `use` satırını sil:

```php
use MhmCurrencySwitcher\License\LicenseManager;
use MhmCurrencySwitcher\License\Mode;
```

2. Dört `register_rest_route()` çağrısını **tamamen** sil: `/license/activate` (~satır 169), `/license/deactivate` (~satır 180), `/license/status` (~satır 191), `/license/manage-subscription` (~satır 205). Her birinin callback ve `permission_callback` metotlarını da sil (sınıfta başka kullanan yoksa).

3. `get_settings()` içindeki `is_pro` enjeksiyonunu sil:

```php
		$settings['is_pro'] = class_exists( '\MhmCurrencySwitcher\License\Mode' )
			? \MhmCurrencySwitcher\License\Mode::is_pro()
			: false;
```

- [ ] **Step 6: `Settings.php`'den lisans arayüzünü kaldır**

`src/Admin/Settings.php`:

1. `use MhmCurrencySwitcher\License\Mode;` satırını sil.
2. `mhm_cs_revalidate` GET handler bloğunun tamamını sil (~satır 70-110) — nonce kontrolü, `daily_verification()` çağrısı, `wp_safe_redirect()` dahil.
3. Sayfa-ziyareti throttle bloğunu sil (~satır 150-165) — `mhm_cs_license_visit_throttle` transient'i ve `daily_verification()` çağrısı.
4. "Build license info for admin panel" bloğunu sil (~satır 214-232) — `$license_manager`, `$license_data`, `$license_info`, `$license_key`, `$license_active`, `$revalidate_url`.
5. `wp_localize_script()` dizisinden `'isPro' => Mode::is_pro(),` satırını ve varsa `'licenseInfo'`/`'revalidateUrl'` anahtarlarını sil.

- [ ] **Step 7: `Commands.php`'den mod etiketini kaldır**

`src/CLI/Commands.php`:

1. `use MhmCurrencySwitcher\License\Mode;` satırını sil.
2. Satır 229'daki:

```php
		$mode    = class_exists( '\MhmCurrencySwitcher\License\Mode' ) && Mode::is_pro() ? 'Pro' : 'Lite';
```

satırını sil ve `$mode` değişkenini kullanan çıktı satırını da kaldır (WP-CLI `status` çıktısı artık mod bildirmez).

- [ ] **Step 8: `tests/bootstrap.php`'den lisans fixture'ını kaldır**

`tests/bootstrap.php` — dosyanın sonundaki `LicenseServerPublicKey::inject_for_testing()` bloğunu tamamen sil (~satır 430-455, "Pin LicenseServerPublicKey::resource() to the test fixture public PEM" yorumuyla başlar). `tests/fixtures/` altında yalnız bu blok tarafından kullanılan PEM dosyaları varsa onları da sil.

- [ ] **Step 9: Oracle'ı çalıştır, geçtiğini doğrula**

```bash
bin/check-no-license-refs.sh
```

Beklenen:

```
ok   [License namespace]
ok   [Mode gates]
ok   [Quota]
ok   [Dev bypass]
FAIL [Pro UI]
...
```

`Pro UI` hâlâ **FAIL** eder — React tarafı Task 4'te temizlenecek. Diğer beş kontrol **ok** olmalı.

- [ ] **Step 10: PHPStan level 0'ı baseline'sız çalıştır (ölü referans avı)**

```bash
vendor/bin/phpstan analyse --level=0 --no-progress
```

> **`--level=0` bayrağını `phpstan.neon` ile birlikte kullan**, `analyse src/ --level=0` gibi yolu elle vererek değil. Yol elle verildiğinde config'in `bootstrapFiles` girdisi devreye girmez, sabitler tanımsız kalır ve yüzlerce sahte hata çıkar.

Beklenen: **[OK] No errors.** Hata çıkarsa, silinmiş bir sınıfa kalan referanstır — o çağrı yerini de temizle. Bu adım grep'in göremediğini yakalar.

- [ ] **Step 11: Testleri ve PHPCS'i çalıştır**

```bash
vendor/bin/phpunit
vendor/bin/phpcs --standard=phpcs.xml.dist src/
```

Beklenen: PHPUnit tamamı yeşil (test sayısı düştü — **yeni sayıyı not al**, Task 12'de kullanılacak). PHPCS 0 hata.

- [ ] **Step 12: Commit**

```bash
git add -A
git commit -m "refactor: lisans alt sistemini tamamen sil

src/License/ (7 dosya) + 4 REST rotası + admin lisans arayüzü +
WP-CLI mod etiketi + 11 test dosyası kaldırıldı.

bin/check-no-license-refs.sh uyum oracle'ı eklendi; PHPStan level 0
baseline'sız temiz (ölü referans yok)."
```

---

### Task 4: React admin panelinden lisansı sök

**Files:**
- Delete: `admin-app/src/components/shared/ProGate.jsx`
- Delete: `admin-app/src/components/tabs/License.jsx`
- Modify: `admin-app/src/App.jsx:19-23`, `:49`, `:184-230`, `:262-312`
- Modify: `admin-app/src/components/tabs/AdvancedSettings.jsx:15`, `:70`, `:267`
- Modify: `admin-app/src/components/tabs/CheckoutOptions.jsx:11`, `:59`, `:131`

**Interfaces:**
- Consumes: Task 3'ün `bin/check-no-license-refs.sh` oracle'ı
- Produces: 4 sekmeli admin uygulaması (`Manage Currencies`, `Display`, `Checkout`, `Advanced`)

- [ ] **Step 1: Lisans bileşenlerini sil**

```bash
git rm admin-app/src/components/shared/ProGate.jsx
git rm admin-app/src/components/tabs/License.jsx
```

- [ ] **Step 2: `App.jsx`'i temizle**

`admin-app/src/App.jsx`:

1. `import License from './components/tabs/License';` satırını sil.
2. `const isPro = config.isPro || false;` satırını sil.
3. `tabs` dizisinden `License` sekmesi nesnesini sil (~satır 207) — `title: __( 'License', ... )` içeren giriş.
4. `{ ! isPro && ( ... ) }` ve `{ isPro && ( ... ) }` koşullu bloklarını (~satır 218-230) sil. Bunlar Lite/Pro rozetleri veya upsell bildirimleridir; **hiçbiri kalmayacak** — WP.org'da ücretli sürüm reklamı yasaktır.
5. `TabPanel` render fonksiyonundaki `isPro={ isPro }` prop geçişlerini (~satır 270, 290, 302) sil.
6. `return <License isPro={ isPro } />;` dalını (~satır 307) sil.

- [ ] **Step 3: `AdvancedSettings.jsx`'ten ProGate'i çıkar**

`admin-app/src/components/tabs/AdvancedSettings.jsx`:

1. `import ProGate from '../shared/ProGate';` satırını sil.
2. Satır 70'teki `<ProGate isPro={ isPro }>` açılış etiketini ve satır 267'deki `</ProGate>` kapanış etiketini sil; aradaki içeriğin girintisini bir seviye azalt.
3. Bileşenin prop imzasından `isPro`'yu kaldır.
4. Dosya başındaki `Wrapped in ProGate for Lite users.` yorum satırını sil.

- [ ] **Step 4: `CheckoutOptions.jsx`'ten ProGate'i çıkar**

`admin-app/src/components/tabs/CheckoutOptions.jsx`:

1. `import ProGate from '../shared/ProGate';` satırını sil.
2. Satır 59'daki `<ProGate isPro={ isPro }>` ve satır 131'deki `</ProGate>` etiketlerini sil; girintiyi bir seviye azalt.
3. Bileşenin prop imzasından `isPro`'yu kaldır.
4. Dosya başındaki `Wrapped in ProGate for Lite users.` yorum satırını sil.

- [ ] **Step 5: `ManageCurrencies.jsx`'teki Pro referanslarını temizle**

```bash
grep -n "isPro\|Pro\b\|upgrade\|unlock\|limit" admin-app/src/components/tabs/ManageCurrencies.jsx
```

Çıkan her satırı incele: para birimi ekleme limiti uyarısı, "Pro'ya geç" metni veya `isPro` prop'u varsa **sil**. Sınırsız para birimi eklenebildiği için limit uyarısı artık yanlıştır.

- [ ] **Step 6: Bundle'ı yeniden derle**

```bash
npm run build
```

Beklenen: derleme hatasız tamamlanır; `admin-app/build/index.js` güncellenir.

> **Kritik:** `admin-app/build/` **git'te izleniyor** (`.gitignore`'da `/build/` yalnızca kök staging dizinidir). Derlenmiş bundle commit edilmezse, silinen Pro arayüzü ZIP'te yaşamaya devam eder — Rentiva'nın T4 redinde tam olarak bu oldu ("Pro'nun derlenmiş JS bundle'larını Lite ZIP'inde göndermek").

- [ ] **Step 7: Oracle'ı çalıştır, tamamen geçtiğini doğrula**

```bash
bin/check-no-license-refs.sh
```

Beklenen:

```
ok   [License namespace]
ok   [Mode gates]
ok   [Quota]
ok   [Dev bypass]
ok   [Pro UI]
ok   [src/License directory absent]

PASS — no licence-gating surface found.
```

- [ ] **Step 8: Derlenmiş bundle'da kalıntı taraması**

```bash
grep -c "ProGate\|isPro\|Unlock with Pro\|License" admin-app/build/index.js
```

Beklenen: **0**. Sıfır değilse Step 6'daki derleme eski kaynağı almıştır — `admin-app/build/` dizinini silip yeniden derle.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "refactor: React admin panelinden lisans arayüzünü sök

ProGate.jsx ve License.jsx silindi; AdvancedSettings ve CheckoutOptions
artık kilitsiz. Sekme sayısı 5 -> 4. isPro prop'u tüm zincirden kaldırıldı.

Derlenmiş bundle yeniden üretildi ve kalıntı taramasıyla doğrulandı
(Rentiva T4: derlenmiş Pro bundle'ı ZIP'te göndermek red sebebiydi).

check-no-license-refs.sh artık tamamen PASS."
```

---

### Task 5: Prefix'i `mhmcs_`'e taşı

WordPress.org'un prefix denetleyicisi prefix'i ilk `_` karakterinde böler: `mhm_cs_foo` → `mhm` (3 harf) → "too short". İç alt çizgisiz, ≥4 harf tek token gerekir.

**Files:**
- Modify: `mhm-currency-switcher.php:33-61`
- Modify: `src/` altındaki tüm PHP dosyaları (41 adet `mhm_cs_` + 5 sabit kullanımı)
- Modify: `admin-app/src/api/settings.js`, `admin-app/src/App.jsx` (REST namespace)
- Modify: `tests/Unit/Compliance/NoLicenseSurfaceTest.php`

**Interfaces:**
- Consumes: Task 3-4 (lisans kodu silinmiş olmalı — daha az dosya dokunulur)
- Produces: `MHMCS_VERSION`, `MHMCS_FILE`, `MHMCS_PATH`, `MHMCS_URL`, `MHMCS_BASENAME` sabitleri; `mhmcs/v1` REST namespace'i. Task 6 (`uninstall.php`) ve Task 7 bunlara dayanır.

**Migration YOK.** Eski anahtarlar veritabanından silinmez, sadece okunmaz. Bilinen tek kurulumda (maxhandmade.com) para birimi ayarları bir kez yeniden girilir.

- [ ] **Step 1: Prefix değişmezini test et (başarısız)**

`tests/Unit/Compliance/NoLicenseSurfaceTest.php` dosyasına ekle:

```php
	/**
	 * All identifiers must use the single-token `mhmcs` prefix.
	 *
	 * WordPress.org's prefix checker splits on the first underscore, so
	 * `mhm_cs_foo` is read as the 3-letter prefix `mhm` and rejected as
	 * too short. A single token of 4+ characters is required.
	 *
	 * @return void
	 */
	public function test_no_legacy_split_prefix_remains(): void {
		$root  = dirname( __DIR__, 3 );
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/src' )
		);

		$offenders = array();

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$source = file_get_contents( $file->getPathname() );

			if ( is_string( $source ) && preg_match( '/\bMHM_CS_|\bmhm_cs_|mhm_currency_switcher_/', $source ) ) {
				$offenders[] = str_replace( $root . '/', '', $file->getPathname() );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Legacy split prefix found in:\n" . implode( "\n", $offenders )
		);
	}
```

- [ ] **Step 2: Testi çalıştır, başarısız olduğunu doğrula**

```bash
vendor/bin/phpunit tests/Unit/Compliance/NoLicenseSurfaceTest.php --filter test_no_legacy_split_prefix_remains
```

Beklenen: **FAIL** — birçok dosya listelenir.

- [ ] **Step 3: Sabitleri yeniden adlandır**

`mhm-currency-switcher.php` içinde beş `define()` çağrısını güncelle:

```php
define( 'MHMCS_VERSION', '0.7.1' );
define( 'MHMCS_FILE', __FILE__ );
define( 'MHMCS_PATH', plugin_dir_path( __FILE__ ) );
define( 'MHMCS_URL', plugin_dir_url( __FILE__ ) );
define( 'MHMCS_BASENAME', plugin_basename( __FILE__ ) );
```

(Sürüm numarası Task 7'de `1.0.0` olacak — şimdi dokunma.)

Aynı beş sabit **`phpstan-bootstrap.php` içinde de tanımlıdır** ve orada da yeniden adlandırılmalıdır; aksi halde PHPStan "Constant MHMCS_VERSION not found" hatalarıyla dolar. Dosyayı şununla değiştir:

```php
define( 'ABSPATH', '/tmp/' );
define( 'MHMCS_VERSION', '0.1.0' );
define( 'MHMCS_FILE', __DIR__ . '/mhm-currency-switcher.php' );
define( 'MHMCS_PATH', __DIR__ . '/' );
define( 'MHMCS_URL', 'https://example.com/wp-content/plugins/mhm-currency-switcher/' );
define( 'MHMCS_BASENAME', 'mhm-currency-switcher/mhm-currency-switcher.php' );
```

- [ ] **Step 4: Tüm kaynakta sabit kullanımlarını değiştir**

```bash
grep -rl 'MHM_CS_' src/ mhm-currency-switcher.php \
  | xargs sed -i 's/MHM_CS_/MHMCS_/g'
```

Doğrula:

```bash
grep -rn 'MHM_CS_' src/ mhm-currency-switcher.php
```

Beklenen: çıktı yok.

- [ ] **Step 5: Option, meta, transient, cookie ve hook adlarını değiştir**

Sırayla çalıştır (uzun eşleşmeler önce, aksi halde kısa desen uzununun içine girer):

```bash
grep -rl 'mhm_currency_switcher_' src/ | xargs sed -i \
  -e "s/mhm_currency_switcher_currencies/mhmcs_currencies/g" \
  -e "s/mhm_currency_switcher_settings/mhmcs_settings/g"

grep -rl '_mhm_cs_' src/ | xargs sed -i 's/_mhm_cs_/_mhmcs_/g'

grep -rl 'mhm_cs_' src/ | xargs sed -i 's/mhm_cs_/mhmcs_/g'
```

Bu; sipariş/ürün meta anahtarlarını (`_mhmcs_currency_code`, `_mhmcs_exchange_rate`, `_mhmcs_base_currency`, `_mhmcs_fixed_prices`), cookie'yi (`mhmcs_currency`) ve cron hook'unu (`mhmcs_update_rates`) kapsar.

Doğrula:

```bash
grep -rn 'mhm_cs_\|mhm_currency_switcher_' src/
```

Beklenen: çıktı yok.

- [ ] **Step 6: REST namespace'ini değiştir**

`src/Admin/RestAPI.php` — sabiti güncelle:

```php
	const NAMESPACE_V1 = 'mhmcs/v1';
```

React tarafında istemci URL'ini güncelle. `src/Admin/Settings.php` içindeki `wp_localize_script` çağrısında:

```php
				'restUrl'          => rest_url( 'mhmcs/v1/' ),
```

Sonra JS tarafında sabit kodlanmış yol var mı kontrol et:

```bash
grep -rn "mhm-currency/v1" admin-app/src/
```

Çıkan her satırı `mhmcs/v1` yap.

- [ ] **Step 7: Testleri güncelle ve çalıştır**

Test dosyalarında eski adları kullanan yerleri güncelle:

```bash
grep -rl 'MHM_CS_\|mhm_cs_\|mhm_currency_switcher_' tests/ | xargs sed -i \
  -e "s/MHM_CS_/MHMCS_/g" \
  -e "s/mhm_currency_switcher_currencies/mhmcs_currencies/g" \
  -e "s/mhm_currency_switcher_settings/mhmcs_settings/g" \
  -e "s/_mhm_cs_/_mhmcs_/g" \
  -e "s/mhm_cs_/mhmcs_/g"
```

> **Dikkat:** `tests/Unit/Compliance/NoLicenseSurfaceTest.php` içindeki `test_no_legacy_split_prefix_remains` metodunun **regex deseni** bu `sed`'den etkilenmemelidir — desen bilerek eski adları arar. Çalıştırdıktan sonra o metodu aç ve regex'in hâlâ `MHM_CS_|\bmhm_cs_|mhm_currency_switcher_` aradığını doğrula; `sed` bozduysa elle geri yaz.

Sonra:

```bash
vendor/bin/phpunit
```

Beklenen: tamamı yeşil, `test_no_legacy_split_prefix_remains` dahil.

- [ ] **Step 8: Bundle'ı yeniden derle ve PHPCS/PHPStan çalıştır**

```bash
npm run build
vendor/bin/phpcs --standard=phpcs.xml.dist src/
vendor/bin/phpstan analyse --no-progress
```

Beklenen: üçü de temiz.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "refactor: prefix'i tek-token mhmcs_'e taşı

WP.org prefix denetleyicisi ilk _ karakterinde böler; mhm_cs_ -> mhm
(3 harf, 'too short'). Tek token mhmcs (5 harf) doğru okunur.

Kapsam: 5 PHP sabiti, 2 option, 4 meta anahtarı, cookie, cron hook'u,
REST namespace (mhm-currency/v1 -> mhmcs/v1).

Migration YOK (bilinen tek kurulum). Eski anahtarlar DB'de duruyor,
yalnızca okunmuyor. Slug ve textdomain değişmedi -> i18n katalogları
etkilenmedi."
```

---

### Task 6: `uninstall.php` ekle

**Files:**
- Create: `uninstall.php`

**Interfaces:**
- Consumes: Task 5'in yeni anahtar adları
- Produces: yok

- [ ] **Step 1: `uninstall.php` dosyasını oluştur**

```php
<?php
/**
 * Uninstall routine for MHM Currency Switcher.
 *
 * Runs when the plugin is deleted from the WordPress admin. Removes every
 * option, transient, scheduled event, and post meta key the plugin creates.
 *
 * @package MhmCurrencySwitcher
 */

declare(strict_types=1);

// Exit if not called by WordPress during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Options.
delete_option( 'mhmcs_currencies' );
delete_option( 'mhmcs_settings' );

// Scheduled events (current name plus the pre-1.0.0 name, so upgraded
// sites do not leave an orphaned cron entry behind).
wp_clear_scheduled_hook( 'mhmcs_update_rates' );
wp_clear_scheduled_hook( 'mhm_cs_update_rates' );

// Transients.
delete_transient( 'mhmcs_rates_cache' );

// Post and order meta.
$meta_keys = array(
	'_mhmcs_currency_code',
	'_mhmcs_exchange_rate',
	'_mhmcs_base_currency',
	'_mhmcs_fixed_prices',
);

foreach ( $meta_keys as $meta_key ) {
	delete_post_meta_by_key( $meta_key );
}

// HPOS order meta lives in its own table when High-Performance Order
// Storage is active; delete_post_meta_by_key() does not reach it.
$hpos_table = $wpdb->prefix . 'wc_orders_meta';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall cleanup, no cache to invalidate.
$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) );

if ( $table_exists === $hpos_table ) {
	foreach ( $meta_keys as $meta_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall cleanup; table name cannot be prepared.
		$wpdb->delete( $hpos_table, array( 'meta_key' => $meta_key ), array( '%s' ) );
	}
}
```

- [ ] **Step 2: Transient adını doğrula**

```bash
grep -rn "set_transient\|get_transient\|delete_transient" src/
```

Çıkan her transient adının Step 1'deki listede olduğundan emin ol. Eksik varsa `uninstall.php`'ye ekle. Fazla varsa (artık var olmayan bir transient) listeden çıkar.

- [ ] **Step 3: PHP sözdizimini doğrula**

```bash
php -l uninstall.php
```

Beklenen: `No syntax errors detected in uninstall.php`

- [ ] **Step 4: PHPCS çalıştır**

```bash
vendor/bin/phpcs --standard=phpcs.xml.dist uninstall.php
```

Beklenen: 0 hata.

- [ ] **Step 5: Commit**

```bash
git add uninstall.php
git commit -m "feat: uninstall.php ekle

Eklenti silindiğinde option, transient, cron ve post/HPOS meta
anahtarlarını temizler. Eski mhm_cs_update_rates cron hook'u da
temizlenir (prefix geçişinden kalan yetim kayıt)."
```

---

### Task 7: Plugin header — lisans, sahiplik, sürüm

**Files:**
- Modify: `mhm-currency-switcher.php:4-16`
- Modify: `readme.txt:1-12`
- Create/Modify: `LICENSE`

**Interfaces:**
- Consumes: yok
- Produces: yok

- [ ] **Step 1: Plugin header'ını güncelle**

`mhm-currency-switcher.php` — header bloğunu şununla değiştir:

```php
/**
 * Plugin Name:       MHM Currency Switcher
 * Plugin URI:        https://wpalemi.com/plugins/mhm-currency-switcher
 * Description:       Multi-currency support for WooCommerce with real-time exchange rates and seamless checkout integration.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            MaxHandMade
 * Author URI:        https://wpalemi.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mhm-currency-switcher
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.0
 *
 * @package MhmCurrencySwitcher
 */
```

Sürüm sabitini de güncelle:

```php
define( 'MHMCS_VERSION', '1.0.0' );
```

> **Neden GPLv2:** WordPress.org "GPLv2 or later ile **uyumlu**" lisans ister. GPLv3, GPLv2 ile tek yönlü uyumsuzdur ve gönderimde takılabilir. Rentiva'da aynı sınıf hata (Apache-2.0 başlığı) yalnızca bağımsız denetimde yakalanmıştı.

- [ ] **Step 2: `LICENSE` dosyasını GPLv2 ile değiştir**

```bash
curl -sSL https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt -o LICENSE
head -3 LICENSE
```

Beklenen çıktı `GNU GENERAL PUBLIC LICENSE` / `Version 2, June 1991` içermeli.

- [ ] **Step 3: `readme.txt` başlığını güncelle**

`readme.txt` ilk satırlarında:

```
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
```

- [ ] **Step 4: Tutarlılığı doğrula**

```bash
grep -n "Version:\|License:\|License URI:\|Plugin URI:\|Author URI:" mhm-currency-switcher.php
grep -n "Stable tag:\|License" readme.txt | head -5
grep -n "MHMCS_VERSION" mhm-currency-switcher.php
```

Beklenen: header sürümü, `MHMCS_VERSION` ve `Stable tag` üçü de `1.0.0`; lisans üç yerde de GPLv2; URI'lar `wpalemi.com`.

- [ ] **Step 5: Commit**

```bash
git add mhm-currency-switcher.php readme.txt LICENSE
git commit -m "chore: header lisans/sahiplik/sürüm düzeltmesi (v1.0.0)

- GPL-3.0-or-later -> GPLv2 or later (WP.org GPLv2 uyumluluğu ister;
  GPLv3 tek yönlü uyumsuz)
- Plugin/Author URI maxhandmade.com -> wpalemi.com (WP.org profil
  e-postası bu domain'le eşleşmeli, tek hesap kuralı)
- Sürüm 0.7.1 -> 1.0.0 (davranış kıran değişiklik + ilk kamuya açık
  sürüm)"
```

---

### Task 8: `readme.txt`'i baştan yaz

**Files:**
- Modify: `readme.txt` (tamamı)

**Interfaces:**
- Consumes: Task 7'nin başlık blokları
- Produces: yok

- [ ] **Step 1: Trialware pazarlamasını sil**

`readme.txt` içinden şunları **tamamen** kaldır:
- `**Pro Version**` bölümü ve altındaki özellik listesi (~satır 31-40)
- "The free version supports up to 3 currencies total..." SSS girdisi (~satır 54)
- "In the free version, rates are fetched on demand (one-time). The Pro version supports scheduled automatic updates..." (~satır 58)
- `= What is the difference between Free and Pro? =` SSS girdisinin tamamı (~satır 60-62)

- [ ] **Step 2: Changelog'u buda**

Changelog bölümünde lisanslama/Pro/feature-token/license-server anlatan tüm girdileri sil (v0.5.x, v0.6.x, v0.7.x girdilerinin büyük kısmı). Yerine tek bir girdi bırak:

```
== Changelog ==

= 1.0.0 =
* First public release.
* All features are available to everyone: unlimited currencies, automatic
  exchange rate updates, geolocation-based currency detection, fixed
  per-product prices, per-currency payment method restrictions,
  multilingual currency names, and a WooCommerce REST API currency filter.
* Identifiers renamed to the `mhmcs` prefix. Settings from earlier
  development builds are not carried over.
```

> **Neden budanıyor:** Rentiva'da 136 monolit changelog girdisi crippleware geçmişini admin ekranında sergiliyordu ve bağımsız denetimde bulgu oldu. Changelog eklentinin şu anki hâlini anlatır, kaldırılmış bir ticari modelin arkeolojisini değil.

- [ ] **Step 3: Dış servis bölümünü ekle**

`readme.txt` içine (Changelog'dan önce) şu bölümü ekle:

```
== External services ==

This plugin connects to third-party exchange rate APIs to keep currency
conversion rates up to date. No personal data is transmitted; only the
three-letter base currency code (for example `USD`) is sent.

**ExchangeRate-API**

Used as the primary source of exchange rates. A request is sent to
`https://api.exchangerate-api.com/v4/latest/{BASE_CURRENCY}` when you press
"Sync rates" in the admin panel, and on the schedule you configure under
automatic rate updates (hourly, twice daily, or daily). Only the base
currency code is sent.

Terms of service: https://www.exchangerate-api.com/terms
Privacy policy: https://www.exchangerate-api.com/privacy-policy

**Fawaz Ahmed Currency API (served over jsDelivr)**

Used as a fallback when ExchangeRate-API is unreachable. A request is sent
to `https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/{base}.json`
under the same conditions as above. Only the base currency code is sent.

Currency API: https://github.com/fawazahmed0/exchange-api
jsDelivr terms of service: https://www.jsdelivr.com/terms
jsDelivr privacy policy: https://www.jsdelivr.com/privacy-policy-jsdelivr-net
```

- [ ] **Step 4: Etiketleri ve açıklamayı gözden geçir**

```bash
head -20 readme.txt
```

Doğrula:
- `Tags:` satırında **en fazla 5 etiket** (Guideline 12), rakip eklenti adı yok
- `Short Description:` 150 karakteri aşmıyor
- Hiçbir yerde "Pro", "premium", "upgrade", "unlock", "limited" geçmiyor

- [ ] **Step 5: Kalıntı taraması**

```bash
grep -niE "\bpro\b|premium|upgrade|unlock|limited to|free version|license" readme.txt
```

Beklenen: çıktı yok. (Yalnızca `License: GPLv2 or later` başlık satırı kalabilir — o meşrudur.)

- [ ] **Step 6: readme'yi WordPress.org doğrulayıcısıyla kontrol et**

`wporg-mcp-server` MCP aracını kullan: `wporg-plugins--plugin-directory--validate-readme` ile `readme.txt` içeriğini doğrula.

Beklenen: hata yok. Uyarı çıkarsa düzelt.

- [ ] **Step 7: Commit**

```bash
git add readme.txt
git commit -m "docs: readme.txt'i baştan yaz (trialware pazarlaması kaldırıldı)

Pro Version bölümü, limit iddiaları ve Free/Pro karşılaştırma SSS'i
silindi. Changelog tek bir 1.0.0 girdisine budandı (Rentiva'da eski
crippleware changelog'u denetimde bulgu olmuştu).

WP.org zorunluluğu olan == External services == bölümü eklendi:
ExchangeRate-API ve Fawaz/jsDelivr — ne gönderiliyor, ne zaman,
ToS ve gizlilik linkleriyle."
```

---

### Task 9: Elementor dosyalarındaki `phpcs:ignoreFile` susturmasını kaldır

**Files:**
- Modify: `src/Integration/Elementor/ElementorIntegration.php:1`
- Modify: `src/Integration/Elementor/PriceDisplayWidget.php:1`
- Modify: `src/Integration/Elementor/SwitcherWidget.php:1`

**Interfaces:**
- Consumes: yok
- Produces: yok

- [ ] **Step 1: Üç dosyadan dosya-geneli susturmayı kaldır**

Her üç dosyanın ilk satırındaki `<?php // phpcs:ignoreFile` ifadesini sade `<?php` ile değiştir.

- [ ] **Step 2: PHPCS çalıştır, gerçek ihlalleri gör**

```bash
vendor/bin/phpcs --standard=phpcs.xml.dist src/Integration/Elementor/
```

Beklenen: hata listesi çıkar. Bunları not al — çoğu escaping ihlali olacaktır.

- [ ] **Step 3: Escaping ihlallerini düzelt**

Her ihlal için:
- HTML döndüren/yazdıran yer → `wp_kses_post( $html )`
- Düz metin → `esc_html( $text )`
- Öznitelik → `esc_attr( $value )`
- URL → `esc_url( $url )`
- Elementor'un kendi `$this->add_render_attribute()` çıktısı gibi zaten güvenli olan yerler → satır bazlı gerekçeli ignore:

```php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor escapes render attributes internally.
```

**Dosya geneli `phpcs:ignoreFile` geri koyma.** Amaç, susturmanın kapsamını gerçekten gerekli satırlara daraltmak.

- [ ] **Step 4: PHPCS'in temiz olduğunu doğrula**

```bash
vendor/bin/phpcs --standard=phpcs.xml.dist src/
```

Beklenen: 0 hata.

- [ ] **Step 5: Testleri çalıştır**

```bash
vendor/bin/phpunit
```

Beklenen: tamamı yeşil (escaping değişiklikleri davranışı değiştirmemeli).

- [ ] **Step 6: Commit**

```bash
git add src/Integration/Elementor/
git commit -m "fix: Elementor dosyalarında dosya-geneli phpcs susturmasını kaldır

3 dosyadaki phpcs:ignoreFile satır bazlı gerekçeli ignore'lara ve
gerçek wp_kses_post/esc_* çağrılarına indirildi. WP.org incelemesi
dosya-geneli susturmayı gizlenmiş ihlal olarak okur."
```

---

### Task 10: i18n katalogunu yenile

**Files:**
- Modify: `languages/mhm-currency-switcher.pot`
- Modify: `languages/mhm-currency-switcher-tr_TR.po`, `.mo`, `.l10n.php`
- Modify: React JSON katalogları

**Interfaces:**
- Consumes: Task 1-9'un tüm string değişiklikleri
- Produces: yok

- [ ] **Step 1: i18n ajanını dispatch et**

```javascript
Agent({
  subagent_type: "wp-i18n",
  description: "Regenerate i18n catalogues after licence removal",
  prompt: `Plugin context:
- Plugin path: c:/projects/mhm-currency-switcher
- Plugin slug: mhm-currency-switcher
- Text domain: mhm-currency-switcher (UNCHANGED — do not rename)
- Locale: tr_TR
- Mode: release (strict gate)

What changed: the entire licence subsystem was deleted (src/License/,
ProGate.jsx, License.jsx tab), six feature gates were removed, the
currency quota was removed, and all identifiers were renamed from
mhm_cs_/MHM_CS_ to mhmcs_/MHMCS_. The text domain and plugin slug did
NOT change.

Expected outcome: every licence-related string ("Activate License",
"Deactivate", "Re-validate Now", "Manage Subscription", "Unlock with
Pro", "Lite", "Pro", currency-limit warnings) must disappear from the
.pot and from every locale catalogue. No new strings are expected.

Rules:
- Pipeline: .pot -> msgmerge -> translate -> fuzzy clean -> .mo ->
  .l10n.php -> report
- makepot scope must match what actually ships (src/, templates/,
  admin-app/src/, main plugin file) — a mismatched scope leaves dead
  strings in the catalogue
- Cross-check with msgcmp; in this environment 'msgmerge --update' has
  been observed to blank unrelated translations
- Do NOT run browser validation — the controller does runtime checks
- Report back: pipeline steps run, files updated, strings removed,
  untranslated count, fuzzy flag count, risks`
})
```

- [ ] **Step 2: Ölü string kalmadığını doğrula**

```bash
grep -niE "license|activate|deactivate|revalidate|subscription|unlock|\bpro\b|\blite\b" languages/mhm-currency-switcher.pot
```

Beklenen: çıktı yok.

> **Neden önemli:** Rentiva'nın 5 Fable turundan biri tam olarak bunu yakaladı — kod silinmişti ama i18n katalogları hâlâ "license server check-in" gibi stringleri Türkçe çevirisiyle birlikte gönderiyordu.

- [ ] **Step 3: Çevrilmemiş string kalmadığını doğrula**

```bash
grep -c 'msgstr ""' languages/mhm-currency-switcher-tr_TR.po
```

Beklenen: `1` (yalnızca dosya başındaki boş header girdisi).

- [ ] **Step 4: Commit**

```bash
git add languages/
git commit -m "i18n: lisans kaldırma sonrası katalogları yenile

Tüm lisans/Pro stringleri .pot ve tr_TR kataloglarından düştü.
Textdomain değişmedi."
```

---

### Task 11: Kapılar, ZIP ve Plugin Check

**Files:**
- Modify: `.distignore`
- Modify: `.github/workflows/*.yml`

**Interfaces:**
- Consumes: Task 1-10'un tamamı
- Produces: `mhm-currency-switcher.1.0.0.zip` — Task 12 bunu WP.org'a gönderir.

- [ ] **Step 1: Uyum oracle'ını CI'a bağla**

`.github/workflows/` altındaki ana workflow dosyasına, mevcut PHPCS adımından sonra şu adımı ekle:

```yaml
      - name: WP.org compliance gate (no licence surface)
        run: bin/check-no-license-refs.sh
```

- [ ] **Step 2: `.distignore`'ın yeni dosyaları kapsadığını doğrula**

`.distignore` satır 47'de zaten `docs/` var — `docs/superpowers/` ayrıca eklenmez.

Doğrulanacak tek şey `bin/` girdisinin hâlâ mevcut olduğudur; Task 3'te oraya `check-no-license-refs.sh` eklendi ve bu dosya **ZIP'e girmemelidir**:

```bash
grep -n "^bin/" .distignore
```

Beklenen: eşleşen satır var. Yoksa ekle.

- [ ] **Step 3: Tüm kapıları yerelde çalıştır**

```bash
bin/check-no-license-refs.sh
vendor/bin/phpcs --standard=phpcs.xml.dist src/ uninstall.php
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpstan analyse src/ --level=0 --no-progress
vendor/bin/phpunit
npm run build
```

Beklenen: altısı da temiz. PHPUnit test sayısını not al — bu yeni baseline'dır.

- [ ] **Step 4: ZIP oluştur**

```bash
python bin/build-release.py
ls -la build/*.zip
```

> **PowerShell `Compress-Archive` KULLANMA** — Windows ters bölü karakteri sorunu ZIP'i bozar. Yalnızca `bin/build-release.py` (veya Linux container içinde `zip`) kullan.

- [ ] **Step 5: ZIP içeriğini denetle**

```bash
unzip -l build/mhm-currency-switcher.1.0.0.zip | grep -iE "license|test|bin/|docs/|node_modules|\.po~|composer\.lock"
```

Beklenen: `LICENSE` dosyası dışında hiçbir eşleşme yok. `src/License/`, `tests/`, `bin/`, `docs/`, `node_modules/` ZIP'te **olmamalı**.

```bash
unzip -p build/mhm-currency-switcher.1.0.0.zip "*/admin-app/build/index.js" | grep -c "ProGate\|isPro"
```

Beklenen: **0**.

- [ ] **Step 6: Plugin Check'i ZIP üzerinde çalıştır**

```bash
docker cp build/mhm-currency-switcher.1.0.0.zip rentiva-release-wpcli-1:/tmp/
MSYS_NO_PATHCONV=1 docker exec rentiva-release-wpcli-1 \
  wp --allow-root plugin install /tmp/mhm-currency-switcher.1.0.0.zip --force --activate
MSYS_NO_PATHCONV=1 docker exec rentiva-release-wpcli-1 \
  wp --allow-root plugin check mhm-currency-switcher --format=table
```

**Hard gate: 0 ERROR.** Warning'ler triyaj edilir: gerçekse düzelt, false-positive ise gerekçeli `phpcs:ignore` veya reviewer notu.

> **Neden ZIP üzerinde:** dev dizini `bin/`, `tests/`, `build/*.zip` içerir ve sahte `application_detected`/`compressed_files` hataları üretir. Gerçek denetim ZIP'tir.

- [ ] **Step 7: `WP_DEBUG` ile notice taraması**

Release stack'te `WP_DEBUG=true`, `WP_DEBUG_LOG=true`, `WP_DEBUG_DISPLAY=false` iken admin panelini ve ön yüzü gez, sonra:

```bash
MSYS_NO_PATHCONV=1 docker exec rentiva-release-wpcli-1 \
  cat /var/www/html/wp-content/debug.log 2>/dev/null | grep -i "mhm\|currency"
```

Beklenen: çıktı yok. **Sıfır** notice/warning/deprecation.

- [ ] **Step 8: Commit**

```bash
git add .distignore .github/
git commit -m "ci: uyum oracle'ını CI kapısı olarak bağla + ZIP hijyeni

check-no-license-refs.sh her push'ta koşar. ZIP içeriği denetlendi:
lisans kodu, testler, bin/, docs/ paketten hariç; derlenmiş bundle'da
Pro kalıntısı yok. Plugin Check ZIP üzerinde 0 ERROR."
```

---

### Task 12: Bağımsız denetim, tarayıcı doğrulaması ve kapanış

**Files:**
- Modify: bulgulara göre

**Interfaces:**
- Consumes: Task 11'in ZIP'i
- Produces: gönderime hazır paket

- [ ] **Step 1: ZIP öncesi bağımsız Fable denetimi**

Bağımsız bir Fable ajanı dispatch et. Ajana **fiilen besle**:
- Üretilen ZIP'in içeriği (dosya listesi + kaynak)
- `wp-knowledge/standards/wporg-submission-guidelines.md` (18 guideline + playbook)
- `wp-knowledge/official/CONTROL-RUBRIC.md` ilgili bölümleri (Hooks, Security, i18n, Data)
- Bu plan ve spec

Denetim sorusu: *"Bu paket WordPress.org'a gönderilse hangi guideline'dan takılır? Özellikle: kalan herhangi bir trialware/kilit/kota izi, ölü UI (handler'ı olmayan buton), belgesiz harici çağrı, escape/nonce/yetki eksiği, prefix, i18n."*

> **Bu bir kanundur** (`feedback_pre_zip_fable_gate`). 529/rate-limit alırsan borç olarak kullanıcıya bildir, "atlandı" diye işaretleme.

- [ ] **Step 2: Bulguları düzelt ve denetimi tekrarla**

🔴 blokaj veya HIGH bulgu → düzelt → **yeni bir Fable turu**. Sorun bulunmayana kadar tekrarla.

> Rentiva'da bu 5 tur sürdü ve **her tur** kapıların kaçırdığı gerçek sorunlar buldu (Apache-2.0 lisans başlığı, bayat i18n katalogları, crippleware changelog'u, CSV formül enjeksiyonu). "İlk tur temiz çıktı" beklentisiyle başlama.

- [ ] **Step 3: Ölü UI çapraz kontrolü**

Her JS'ten çağrılan REST rotasının PHP tarafında kayıtlı olduğunu doğrula:

```bash
grep -rhoE "apiFetch\(\s*\{\s*path:\s*['\"][^'\"]+" admin-app/src/ | grep -oE "mhmcs/v1/[a-z/-]+" | sort -u
grep -n "NAMESPACE_V1," -A1 src/Admin/RestAPI.php | grep -oE "'/[a-z/-]+'" | sort -u
```

İki liste eşleşmeli. JS'in çağırdığı ama PHP'nin kaydetmediği bir rota = **ölü buton** — Rentiva'nın T4 turunda GDPR butonları tam olarak böyle yakalandı ve HIGH bulgu sayıldı.

- [ ] **Step 4: Tarayıcı doğrulaması (Chrome DevTools MCP)**

Release stack'te (`release.localhost`) gerçek bir tarayıcı oturumunda:

1. **Admin paneli** — 4 sekmenin **hepsini** aç (`Manage Currencies`, `Display`, `Checkout`, `Advanced`). Doğrula:
   - License sekmesi **yok**
   - Hiçbir sekmede bulanık overlay / kilit / "Pro" ibaresi **yok**
   - 5 para birimi eklenebiliyor (kota yok)
   - Konsol ve network temiz (404 yok)
2. **Ön yüz** — switcher görünüyor, para birimi değişimi fiyatları güncelliyor
3. **Checkout** — seçili para birimi checkout'a taşınıyor
4. **Ödeme kısıtı** — para birimine göre gateway filtreleme çalışıyor (artık ücretsiz)
5. **Sabit fiyat** — ürün düzenleme ekranında para birimi sekmesi görünüyor (artık ücretsiz)

Her ekran için ekran görüntüsü al.

> **Kapı yeşilliği bunun yerine geçmez.** Rentiva'da 6 kapı + 820 test yeşilken lisans sayfası FATAL veriyordu; kullanıcı tarayıcıyı açınca tek ekranda çıktı.

- [ ] **Step 5: Kullanıcıya kanıt sun**

Ekran görüntüleri + kapı çıktıları + Plugin Check tablosu + Fable denetim sonucu ile birlikte rapor et. Onay al.

- [ ] **Step 6: Merge ve push**

```bash
git checkout develop
git merge --no-ff feature/wporg-free-release
git push origin develop
```

- [ ] **Step 7: CI'ın yeşil olduğunu doğrula**

```bash
gh run watch --exit-status
gh run view --json conclusion,jobs
```

**`gh run watch` tek başına yeterli değil** — Rentiva'da kırık bir run'da `0` döndürdüğü görüldü. `gh run view --json conclusion` ile teyit et; **tüm job'lar `success`** olmalı.

> "Push edildi" bir kapı değildir (`feedback_verify_ci_after_push`).

- [ ] **Step 8: GitHub release oluştur**

```bash
gh release create v1.0.0 build/mhm-currency-switcher.1.0.0.zip \
  --title "v1.0.0 — first public release" \
  --notes "All features free. Licence subsystem removed, currency quota removed, identifiers moved to the mhmcs prefix. Prepared for the WordPress.org plugin directory."
```

- [ ] **Step 9: WordPress.org gönderimini BEKLET**

⛔ **Gönderme.** Spec §7 gereği gönderim, MHM Rentiva'nın 3. WP.org başvurusunun cevabı gelene kadar bekletilir. Cevap yeni bulgularla dönerse, aynı hesap altındaki bu eklentiye de uygulanır ve gönderimden önce içeri katılır.

Bu adım **kullanıcı onayıyla** açılır.

- [ ] **Step 10: Kapanış ritüeli**

- `wp-reflect` invoke et (öğrenmeler global bilgi bankasına)
- `fingerprint.md` güncelle (yeni sürüm + PHPUnit baseline)
- Memory hijyeni: `project_currency_switcher.md`'deki "WP.org gönderim engeli — carve gerekli" bölümünü **güncelle** (blokaj çözüldü); `MEMORY.md` "Pending Tasks" altındaki 🔴 CS carve satırını **sil**; `hot.md`'ye yeni durum satırı ekle
- Çalışma ağacı temizliği: `git branch -d feature/wporg-free-release`, `build/*.zip` temizle
- Memory diff brief sun, kullanıcı onayı al

---

## Backlog (bu planın dışında, kaydedildi)

1. **Cache eklentisi uyumluluğu** — WP Rocket / LiteSpeed / Cloudflare için `mhmcs_currency` cookie'sinin cache anahtarına dahil edilmesi. Araştırmada WP.org'da en çok düşük puan getiren hata sınıfı. **Yüksek öncelik.**
2. **Admin arayüzü yeniden tasarımı** — Claude Design (`DesignSync`) ile. Bu plandan **sonra**, çünkü Task 4 arayüzü yapısal olarak değiştiriyor. WP.org listeleme ekran görüntüleri bu fazdan sonra çekilir.
3. **Ekosistem emekliliği** — license-server `featuresFor('mhm-currency-switcher')`, Polar CS Monthly/Yearly ürünleri, wpalemi `/download/mhm-currency-switcher` ve fiyatlandırma sayfası.
4. **Gelecekteki Pro fikirleri** — spec §9 (settlement-farkında ödeme yönlendirme, çok para birimli muhasebe raporu, fiyatlandırma kuralları).
