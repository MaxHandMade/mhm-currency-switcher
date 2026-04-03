# Phase 2 Implementation Plan — Bug Fix + Cron + Geolocation

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix the license expires_at display bug, wire up automatic rate update cron scheduling, and implement geolocation-based currency detection with CloudFlare + WC MaxMind provider chain.

**Architecture:** Three independent work items executed sequentially. Task 1 fixes a timestamp normalization bug in LicenseManager. Task 2 completes the missing cron scheduling logic for rate auto-updates. Tasks 3-5 add a new GeolocationService + CountryCurrencyMap and integrate them into DetectionService.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, WooCommerce 7.0+, PHPUnit, React (wp-scripts)

**Design doc:** `docs/plans/2026-04-03-phase2-roadmap-design.md`

---

### Task 1: Fix License `expires_at` Display Bug

**Files:**
- Modify: `src/License/LicenseManager.php:154-195` (activate method)
- Modify: `src/License/LicenseManager.php:243-269` (daily_verification method)
- Test: `tests/Unit/License/LicenseManagerTest.php` (new file)

**Context:** The license server may return `expires_at` as a Unix timestamp (int), ISO date string, or empty string. Currently stored as-is (line 182). When empty or 0, the React admin displays "21 Ocak 1970" (epoch). We need to normalize to ISO 8601 or empty string before storage.

**Step 1: Create test file with failing tests**

Create `tests/Unit/License/LicenseManagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\License;

use MhmCurrencySwitcher\License\LicenseManager;
use PHPUnit\Framework\TestCase;

class LicenseManagerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        LicenseManager::reset();
    }

    public function test_normalize_expires_at_with_iso_string(): void {
        $manager = LicenseManager::instance();
        $result  = $this->call_normalize( $manager, '2026-12-31T23:59:59+00:00' );

        $this->assertSame( '2026-12-31T23:59:59+00:00', $result );
    }

    public function test_normalize_expires_at_with_timestamp(): void {
        $manager = LicenseManager::instance();
        $result  = $this->call_normalize( $manager, 1798761600 );

        $this->assertNotEmpty( $result );
        $this->assertStringContainsString( '2027-', $result );
    }

    public function test_normalize_expires_at_with_numeric_string(): void {
        $manager = LicenseManager::instance();
        $result  = $this->call_normalize( $manager, '1798761600' );

        $this->assertNotEmpty( $result );
        $this->assertStringContainsString( '2027-', $result );
    }

    public function test_normalize_expires_at_with_empty_string(): void {
        $manager = LicenseManager::instance();
        $result  = $this->call_normalize( $manager, '' );

        $this->assertSame( '', $result );
    }

    public function test_normalize_expires_at_with_zero(): void {
        $manager = LicenseManager::instance();
        $result  = $this->call_normalize( $manager, 0 );

        $this->assertSame( '', $result );
    }

    public function test_normalize_expires_at_with_null(): void {
        $manager = LicenseManager::instance();
        $result  = $this->call_normalize( $manager, null );

        $this->assertSame( '', $result );
    }

    public function test_normalize_expires_at_with_date_string(): void {
        $manager = LicenseManager::instance();
        $result  = $this->call_normalize( $manager, '2026-06-15' );

        $this->assertNotEmpty( $result );
        $this->assertStringContainsString( '2026-06-15', $result );
    }

    /**
     * Call the private normalize_expires_at method via reflection.
     *
     * @param LicenseManager $manager  Manager instance.
     * @param mixed          $value    Value to normalize.
     * @return string Normalized value.
     */
    private function call_normalize( LicenseManager $manager, $value ): string {
        $ref = new \ReflectionMethod( $manager, 'normalize_expires_at' );
        $ref->setAccessible( true );

        return $ref->invoke( $manager, $value );
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `cd C:\projects\mhm-currency-switcher && vendor/bin/phpunit tests/Unit/License/LicenseManagerTest.php -v`
Expected: FAIL — `normalize_expires_at` method does not exist

**Step 3: Implement normalize_expires_at in LicenseManager**

Add this private method to `src/License/LicenseManager.php` (before `get_license_data()`):

```php
/**
 * Normalize an expires_at value to ISO 8601 string.
 *
 * Handles Unix timestamps (int or numeric string), ISO date strings,
 * and date strings. Returns empty string for empty/zero/null values.
 *
 * @param mixed $value Raw expires_at value from license server.
 * @return string ISO 8601 date string, or empty string.
 */
private function normalize_expires_at( $value ): string {
    if ( null === $value || '' === $value ) {
        return '';
    }

    // Numeric timestamp (int or numeric string).
    if ( is_numeric( $value ) ) {
        $ts = (int) $value;

        return $ts > 0 ? gmdate( 'c', $ts ) : '';
    }

    // Already an ISO 8601 or parseable date string.
    if ( is_string( $value ) ) {
        $ts = strtotime( $value );

        return false !== $ts && $ts > 0 ? gmdate( 'c', $ts ) : '';
    }

    return '';
}
```

Then update `activate()` method — change line 182 from:
```php
'expires_at'    => $result['expires_at'] ?? '',
```
to:
```php
'expires_at'    => $this->normalize_expires_at( $result['expires_at'] ?? '' ),
```

And update `daily_verification()` — after line 263 (`$data['status'] = $result['status'];`), add:
```php
if ( isset( $result['expires_at'] ) ) {
    $data['expires_at'] = $this->normalize_expires_at( $result['expires_at'] );
}
```

**Step 4: Run tests to verify they pass**

Run: `cd C:\projects\mhm-currency-switcher && vendor/bin/phpunit tests/Unit/License/LicenseManagerTest.php -v`
Expected: 7 tests, 7+ assertions, all PASS

**Step 5: Commit**

```bash
git add src/License/LicenseManager.php tests/Unit/License/LicenseManagerTest.php
git commit -m "fix: normalize license expires_at to ISO 8601 before storage

Handles Unix timestamps, ISO strings, date strings, and empty/zero
values. Prevents '1970' display bug in admin license card."
```

---

### Task 2: Wire Up Cron Rate Update Scheduling

**Files:**
- Modify: `src/Plugin.php:195-222` (Phase 9)
- Modify: `src/Admin/RestAPI.php:236-308` (save_settings)
- Test: `tests/Unit/PluginTest.php` (add scheduling tests)

**Context:** Plugin.php Phase 9 defines the `mhm_cs_update_rates` action callback but never calls `wp_schedule_event()`. Admin panel saves `rate_update_interval` in settings but it's not read on the backend. The deactivation hook already clears the cron — only scheduling is missing.

**Step 1: Write failing tests**

Add to `tests/Unit/PluginTest.php` (or create if needed — check existing file first):

```php
public function test_schedule_rate_update_registers_event(): void {
    // Simulate settings with hourly interval.
    global $mhm_test_options;
    $mhm_test_options['mhm_currency_switcher_settings'] = array(
        'rate_update_interval' => 'hourly',
    );

    // The wp_schedule_event stub should have been called.
    // Check via the global stub tracker.
    $this->assertTrue(
        function_exists( 'wp_schedule_event' ),
        'wp_schedule_event stub must exist'
    );
}
```

> Note: The bootstrap stubs `wp_schedule_event()` and `wp_next_scheduled()`. Since Plugin is tightly coupled to WordPress init, this task focuses on the scheduling helper method and REST API integration which are more testable.

**Step 2: Add scheduling logic to Plugin.php Phase 9**

Replace the current Phase 9 block in `src/Plugin.php` (around lines 195-222) with:

```php
// ─── Phase 9: Scheduled tasks (Pro only) ─────────────────────
if ( Mode::can_use_auto_rate_update() ) {
    // Register the rate update callback.
    add_action(
        'mhm_cs_update_rates',
        static function () use ( $store, $rate_provider ) {
            $base  = $store->get_base_currency();
            $rates = $rate_provider->fetch_rates( $base );

            if ( empty( $rates ) ) {
                return;
            }

            $currencies = $store->get_currencies();

            foreach ( $currencies as &$currency ) {
                $code = $currency['code'] ?? '';

                if ( '' !== $code && isset( $rates[ $code ] ) ) {
                    $currency['rate']['value'] = $rates[ $code ];
                }
            }
            unset( $currency );

            $store->set_data( $base, $currencies );
            $store->save();
        }
    );

    // Schedule cron based on settings interval.
    $settings = get_option( 'mhm_currency_switcher_settings', array() );
    $interval = $settings['rate_update_interval'] ?? 'manual';

    if ( 'manual' !== $interval && in_array( $interval, array( 'hourly', 'twicedaily', 'daily' ), true ) ) {
        if ( ! wp_next_scheduled( 'mhm_cs_update_rates' ) ) {
            wp_schedule_event( time(), $interval, 'mhm_cs_update_rates' );
        }
    } else {
        // Manual mode or invalid — clear any existing schedule.
        wp_clear_scheduled_hook( 'mhm_cs_update_rates' );
    }
}
```

**Step 3: Add reschedule logic to RestAPI save_settings**

In `src/Admin/RestAPI.php`, inside `save_settings()` method, add after `update_option( self::SETTINGS_KEY, $merged );` (around line 304):

```php
// Reschedule cron if rate_update_interval changed.
if ( isset( $sanitized['rate_update_interval'] ) ) {
    wp_clear_scheduled_hook( 'mhm_cs_update_rates' );

    $new_interval = $sanitized['rate_update_interval'];

    if ( 'manual' !== $new_interval
        && in_array( $new_interval, array( 'hourly', 'twicedaily', 'daily' ), true )
        && Mode::can_use_auto_rate_update()
    ) {
        wp_schedule_event( time(), $new_interval, 'mhm_cs_update_rates' );
    }
}
```

Also add sanitization for `rate_update_interval` in the sanitization block (before the merge):

```php
if ( isset( $params['rate_update_interval'] ) ) {
    $interval = sanitize_text_field( $params['rate_update_interval'] );
    $allowed  = array( 'manual', 'hourly', 'twicedaily', 'daily' );
    $sanitized['rate_update_interval'] = in_array( $interval, $allowed, true ) ? $interval : 'manual';
}
```

**Step 4: Run full test suite**

Run: `cd C:\projects\mhm-currency-switcher && vendor/bin/phpunit -v`
Expected: All existing tests pass + no regressions

**Step 5: Commit**

```bash
git add src/Plugin.php src/Admin/RestAPI.php
git commit -m "feat: wire up automatic rate update cron scheduling

Reads rate_update_interval from settings (hourly/twicedaily/daily/manual),
schedules wp_cron event on plugin init, reschedules when settings change.
Pro feature only, gated by Mode::can_use_auto_rate_update()."
```

---

### Task 3: Create GeolocationService

**Files:**
- Create: `src/Core/GeolocationService.php`
- Test: `tests/Unit/Core/GeolocationServiceTest.php` (new file)

**Context:** Detects visitor country code using a cascade: CloudFlare `CF-IPCountry` header first (zero-cost), then WC MaxMind `WC_Geolocation::geolocate_ip()` as fallback. Returns ISO 3166-1 alpha-2 country code or null.

**Step 1: Write failing tests**

Create `tests/Unit/Core/GeolocationServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use MhmCurrencySwitcher\Core\GeolocationService;
use PHPUnit\Framework\TestCase;

class GeolocationServiceTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
    }

    protected function tearDown(): void {
        unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
        parent::tearDown();
    }

    public function test_detect_from_cloudflare_header(): void {
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'TR';
        $service = new GeolocationService();

        $this->assertSame( 'TR', $service->detect_country() );
    }

    public function test_detect_cloudflare_lowercases_to_upper(): void {
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'de';
        $service = new GeolocationService();

        $this->assertSame( 'DE', $service->detect_country() );
    }

    public function test_detect_cloudflare_ignores_xx(): void {
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'XX';
        $service = new GeolocationService();

        // XX means unknown in CloudFlare — should skip.
        $this->assertNull( $service->detect_country() );
    }

    public function test_detect_cloudflare_ignores_t1(): void {
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'T1';
        $service = new GeolocationService();

        // T1 means Tor in CloudFlare — should skip.
        $this->assertNull( $service->detect_country() );
    }

    public function test_detect_returns_null_without_providers(): void {
        $service = new GeolocationService();

        // No CF header, no WC — should return null.
        $this->assertNull( $service->detect_country() );
    }

    public function test_detect_cloudflare_validates_format(): void {
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'INVALID';
        $service = new GeolocationService();

        $this->assertNull( $service->detect_country() );
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `cd C:\projects\mhm-currency-switcher && vendor/bin/phpunit tests/Unit/Core/GeolocationServiceTest.php -v`
Expected: FAIL — class GeolocationService does not exist

**Step 3: Implement GeolocationService**

Create `src/Core/GeolocationService.php`:

```php
<?php
/**
 * Geolocation service — detect visitor country from IP.
 *
 * Uses a cascading provider chain: CloudFlare header first (zero-cost),
 * then WooCommerce MaxMind GeoIP database as fallback.
 *
 * @package MhmCurrencySwitcher\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GeolocationService — country detection via IP geolocation.
 *
 * @since 0.4.0
 */
final class GeolocationService {

    /**
     * CloudFlare country codes that should be ignored.
     *
     * XX = unknown, T1 = Tor exit node.
     *
     * @var array<int, string>
     */
    const IGNORED_CODES = array( 'XX', 'T1' );

    /**
     * Detect the visitor's country code.
     *
     * @return string|null ISO 3166-1 alpha-2 country code, or null when unavailable.
     */
    public function detect_country(): ?string {
        $country = $this->detect_from_cloudflare();

        if ( null !== $country ) {
            return $country;
        }

        return $this->detect_from_wc_maxmind();
    }

    /**
     * Detect country from CloudFlare CF-IPCountry header.
     *
     * @return string|null Country code, or null.
     */
    private function detect_from_cloudflare(): ?string {
        if ( ! isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
            return null;
        }

        $code = strtoupper( trim( (string) $_SERVER['HTTP_CF_IPCOUNTRY'] ) );

        if ( ! preg_match( '/^[A-Z]{2}$/', $code ) ) {
            return null;
        }

        if ( in_array( $code, self::IGNORED_CODES, true ) ) {
            return null;
        }

        return $code;
    }

    /**
     * Detect country from WooCommerce MaxMind GeoIP database.
     *
     * @return string|null Country code, or null.
     */
    private function detect_from_wc_maxmind(): ?string {
        if ( ! class_exists( 'WC_Geolocation' ) ) {
            return null;
        }

        $geo = \WC_Geolocation::geolocate_ip();

        if ( empty( $geo['country'] ) ) {
            return null;
        }

        $code = strtoupper( trim( $geo['country'] ) );

        return preg_match( '/^[A-Z]{2}$/', $code ) ? $code : null;
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `cd C:\projects\mhm-currency-switcher && vendor/bin/phpunit tests/Unit/Core/GeolocationServiceTest.php -v`
Expected: 6 tests, 6 assertions, all PASS

**Step 5: Commit**

```bash
git add src/Core/GeolocationService.php tests/Unit/Core/GeolocationServiceTest.php
git commit -m "feat: add GeolocationService with CloudFlare + WC MaxMind cascade

Detects visitor country via CF-IPCountry header (zero-cost) first,
falls back to WC_Geolocation::geolocate_ip() if available.
Ignores CloudFlare XX (unknown) and T1 (Tor) codes."
```

---

### Task 4: Create CountryCurrencyMap

**Files:**
- Create: `src/Core/CountryCurrencyMap.php`
- Test: `tests/Unit/Core/CountryCurrencyMapTest.php` (new file)

**Context:** Static mapping from ISO 3166-1 alpha-2 country codes to ISO 4217 currency codes. Covers ~80 common countries. Eurozone countries all map to EUR.

**Step 1: Write failing tests**

Create `tests/Unit/Core/CountryCurrencyMapTest.php`:

```php
<?php

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use MhmCurrencySwitcher\Core\CountryCurrencyMap;
use PHPUnit\Framework\TestCase;

class CountryCurrencyMapTest extends TestCase {

    public function test_turkey_maps_to_try(): void {
        $this->assertSame( 'TRY', CountryCurrencyMap::get_currency( 'TR' ) );
    }

    public function test_united_states_maps_to_usd(): void {
        $this->assertSame( 'USD', CountryCurrencyMap::get_currency( 'US' ) );
    }

    public function test_germany_maps_to_eur(): void {
        $this->assertSame( 'EUR', CountryCurrencyMap::get_currency( 'DE' ) );
    }

    public function test_france_maps_to_eur(): void {
        $this->assertSame( 'EUR', CountryCurrencyMap::get_currency( 'FR' ) );
    }

    public function test_united_kingdom_maps_to_gbp(): void {
        $this->assertSame( 'GBP', CountryCurrencyMap::get_currency( 'GB' ) );
    }

    public function test_japan_maps_to_jpy(): void {
        $this->assertSame( 'JPY', CountryCurrencyMap::get_currency( 'JP' ) );
    }

    public function test_unknown_country_returns_null(): void {
        $this->assertNull( CountryCurrencyMap::get_currency( 'ZZ' ) );
    }

    public function test_lowercase_input_works(): void {
        $this->assertSame( 'TRY', CountryCurrencyMap::get_currency( 'tr' ) );
    }

    public function test_map_has_at_least_60_entries(): void {
        $map = CountryCurrencyMap::get_map();
        $this->assertGreaterThanOrEqual( 60, count( $map ) );
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `cd C:\projects\mhm-currency-switcher && vendor/bin/phpunit tests/Unit/Core/CountryCurrencyMapTest.php -v`
Expected: FAIL — class CountryCurrencyMap does not exist

**Step 3: Implement CountryCurrencyMap**

Create `src/Core/CountryCurrencyMap.php`:

```php
<?php
/**
 * Country to currency mapping.
 *
 * Maps ISO 3166-1 alpha-2 country codes to their default
 * ISO 4217 currency codes for geolocation-based detection.
 *
 * @package MhmCurrencySwitcher\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CountryCurrencyMap — static country→currency lookup.
 *
 * @since 0.4.0
 */
final class CountryCurrencyMap {

    /**
     * Country code → currency code mapping.
     *
     * @var array<string, string>
     */
    private static array $map = array(
        // Eurozone.
        'AT' => 'EUR', 'BE' => 'EUR', 'CY' => 'EUR', 'DE' => 'EUR',
        'EE' => 'EUR', 'ES' => 'EUR', 'FI' => 'EUR', 'FR' => 'EUR',
        'GR' => 'EUR', 'HR' => 'EUR', 'IE' => 'EUR', 'IT' => 'EUR',
        'LT' => 'EUR', 'LU' => 'EUR', 'LV' => 'EUR', 'MT' => 'EUR',
        'NL' => 'EUR', 'PT' => 'EUR', 'SI' => 'EUR', 'SK' => 'EUR',

        // Americas.
        'US' => 'USD', 'CA' => 'CAD', 'MX' => 'MXN', 'BR' => 'BRL',
        'AR' => 'ARS', 'CL' => 'CLP', 'CO' => 'COP', 'PE' => 'PEN',

        // Europe (non-euro).
        'GB' => 'GBP', 'CH' => 'CHF', 'SE' => 'SEK', 'NO' => 'NOK',
        'DK' => 'DKK', 'PL' => 'PLN', 'CZ' => 'CZK', 'HU' => 'HUF',
        'RO' => 'RON', 'BG' => 'BGN', 'UA' => 'UAH', 'RU' => 'RUB',
        'TR' => 'TRY', 'IS' => 'ISK',

        // Asia.
        'JP' => 'JPY', 'CN' => 'CNY', 'KR' => 'KRW', 'IN' => 'INR',
        'ID' => 'IDR', 'TH' => 'THB', 'VN' => 'VND', 'MY' => 'MYR',
        'SG' => 'SGD', 'PH' => 'PHP', 'TW' => 'TWD', 'HK' => 'HKD',
        'BD' => 'BDT', 'PK' => 'PKR', 'LK' => 'LKR', 'KH' => 'KHR',
        'MM' => 'MMK', 'NP' => 'NPR',

        // Middle East.
        'AE' => 'AED', 'SA' => 'SAR', 'QA' => 'QAR', 'KW' => 'KWD',
        'BH' => 'BHD', 'OM' => 'OMR', 'IL' => 'ILS', 'JO' => 'JOD',
        'LB' => 'LBP',

        // Africa.
        'ZA' => 'ZAR', 'NG' => 'NGN', 'EG' => 'EGP', 'KE' => 'KES',
        'GH' => 'GHS', 'TZ' => 'TZS', 'MA' => 'MAD', 'TN' => 'TND',
        'DZ' => 'DZD',

        // Oceania.
        'AU' => 'AUD', 'NZ' => 'NZD', 'FJ' => 'FJD',
    );

    /**
     * Get the default currency for a country code.
     *
     * @param string $country_code ISO 3166-1 alpha-2 country code.
     * @return string|null ISO 4217 currency code, or null when unmapped.
     */
    public static function get_currency( string $country_code ): ?string {
        $code = strtoupper( trim( $country_code ) );

        return self::$map[ $code ] ?? null;
    }

    /**
     * Get the full country→currency map.
     *
     * @return array<string, string> Country code → currency code.
     */
    public static function get_map(): array {
        return self::$map;
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `cd C:\projects\mhm-currency-switcher && vendor/bin/phpunit tests/Unit/Core/CountryCurrencyMapTest.php -v`
Expected: 9 tests, 9 assertions, all PASS

**Step 5: Commit**

```bash
git add src/Core/CountryCurrencyMap.php tests/Unit/Core/CountryCurrencyMapTest.php
git commit -m "feat: add CountryCurrencyMap for geolocation currency lookup

Static mapping of ~80 country codes to default currencies.
Covers Eurozone, Americas, Europe, Asia, Middle East, Africa, Oceania."
```

---

### Task 5: Integrate Geolocation into DetectionService

**Files:**
- Modify: `src/Core/DetectionService.php:76-79` (constructor) and `101-115` (get_current_currency)
- Modify: `src/Plugin.php` (wire GeolocationService)
- Modify: `admin-app/src/components/tabs/AdvancedSettings.jsx:90-119` (simplify provider UI)
- Modify: `src/Admin/RestAPI.php` (sanitize geolocation settings)
- Test: `tests/Unit/Core/DetectionServiceTest.php` (add geolocation tests)
- Build: `admin-app/build/` (rebuild React)

**Context:** DetectionService currently chains: cookie → URL param → base currency. We add geolocation as step 3 (before base currency fallback). Requires GeolocationService + CountryCurrencyMap from Tasks 3-4. Only active when Pro + enabled in settings.

**Step 1: Add failing tests to DetectionServiceTest**

Read the existing `tests/Unit/Core/DetectionServiceTest.php` first, then append these test methods:

```php
public function test_geolocation_detects_currency_from_country(): void {
    $store = $this->create_store_with_currencies( 'USD', array( 'EUR', 'TRY', 'GBP' ) );
    $geo   = new \MhmCurrencySwitcher\Core\GeolocationService();
    $map   = new \MhmCurrencySwitcher\Core\CountryCurrencyMap();

    $_SERVER['HTTP_CF_IPCOUNTRY'] = 'TR';

    $detection = new \MhmCurrencySwitcher\Core\DetectionService( $store, false );
    $detection->set_geolocation( $geo, true );

    $this->assertSame( 'TRY', $detection->get_current_currency() );
}

public function test_geolocation_skipped_when_disabled(): void {
    $store = $this->create_store_with_currencies( 'USD', array( 'EUR', 'TRY', 'GBP' ) );
    $geo   = new \MhmCurrencySwitcher\Core\GeolocationService();

    $_SERVER['HTTP_CF_IPCOUNTRY'] = 'TR';

    $detection = new \MhmCurrencySwitcher\Core\DetectionService( $store, false );
    $detection->set_geolocation( $geo, false );

    // Geolocation disabled — should fall back to base currency.
    $this->assertSame( 'USD', $detection->get_current_currency() );
}

public function test_geolocation_skipped_when_currency_not_enabled(): void {
    $store = $this->create_store_with_currencies( 'USD', array( 'EUR', 'GBP' ) );
    $geo   = new \MhmCurrencySwitcher\Core\GeolocationService();

    $_SERVER['HTTP_CF_IPCOUNTRY'] = 'TR';

    $detection = new \MhmCurrencySwitcher\Core\DetectionService( $store, false );
    $detection->set_geolocation( $geo, true );

    // TRY not in enabled currencies — should fall back to base.
    $this->assertSame( 'USD', $detection->get_current_currency() );
}

public function test_cookie_takes_priority_over_geolocation(): void {
    $store = $this->create_store_with_currencies( 'USD', array( 'EUR', 'TRY', 'GBP' ) );
    $geo   = new \MhmCurrencySwitcher\Core\GeolocationService();

    $_SERVER['HTTP_CF_IPCOUNTRY'] = 'TR';
    $_COOKIE['mhm_cs_currency']   = 'EUR';

    $detection = new \MhmCurrencySwitcher\Core\DetectionService( $store, false );
    $detection->set_geolocation( $geo, true );

    // Cookie takes priority over geolocation.
    $this->assertSame( 'EUR', $detection->get_current_currency() );
}
```

> Note: The `create_store_with_currencies` helper may need to be created or adapted from existing test helpers. Check the existing test file pattern first.

**Step 2: Add geolocation support to DetectionService**

In `src/Core/DetectionService.php`:

Add new properties after `$url_param_enabled`:
```php
/**
 * Geolocation service instance.
 *
 * @var GeolocationService|null
 */
private ?GeolocationService $geolocation = null;

/**
 * Whether geolocation detection is enabled.
 *
 * @var bool
 */
private bool $geolocation_enabled = false;
```

Add setter method after `set_url_param_enabled()`:
```php
/**
 * Set the geolocation service and enable/disable it.
 *
 * @param GeolocationService $geolocation Geolocation service.
 * @param bool               $enabled     Whether geolocation is enabled.
 * @return void
 */
public function set_geolocation( GeolocationService $geolocation, bool $enabled ): void {
    $this->geolocation         = $geolocation;
    $this->geolocation_enabled = $enabled;
}
```

Update `get_current_currency()` to insert geolocation between URL param and base currency:
```php
public function get_current_currency(): string {
    $from_cookie = $this->detect_from_cookie();

    if ( null !== $from_cookie ) {
        return $from_cookie;
    }

    $from_url = $this->detect_from_url_param();

    if ( null !== $from_url ) {
        return $from_url;
    }

    $from_geo = $this->detect_from_geolocation();

    if ( null !== $from_geo ) {
        return $from_geo;
    }

    return $this->store->get_base_currency();
}
```

Add the private detection method:
```php
/**
 * Detect currency from visitor geolocation.
 *
 * @return string|null Currency code, or null when unavailable/disabled.
 */
private function detect_from_geolocation(): ?string {
    if ( ! $this->geolocation_enabled || null === $this->geolocation ) {
        return null;
    }

    $country = $this->geolocation->detect_country();

    if ( null === $country ) {
        return null;
    }

    $currency = CountryCurrencyMap::get_currency( $country );

    if ( null === $currency ) {
        return null;
    }

    // Validate that the detected currency is enabled.
    if ( ! $this->validate_code( $currency ) ) {
        return null;
    }

    // Set cookie so geolocation doesn't re-run on next page load.
    $this->set_currency( $currency );

    return $currency;
}
```

Add `use` statement at top of file:
```php
use MhmCurrencySwitcher\Core\CountryCurrencyMap;
```

**Step 3: Wire GeolocationService in Plugin.php**

In `src/Plugin.php`, after creating `$detection` in Phase 1 (around line 113):

```php
// Geolocation (Pro only).
if ( Mode::can_use_geolocation() ) {
    $geo_service = new GeolocationService();
    $settings    = get_option( 'mhm_currency_switcher_settings', array() );
    $geo_enabled = ! empty( $settings['auto_detect'] );

    $detection->set_geolocation( $geo_service, $geo_enabled );
}
```

Add use statement:
```php
use MhmCurrencySwitcher\Core\GeolocationService;
```

**Step 4: Simplify admin geolocation provider UI**

In `admin-app/src/components/tabs/AdvancedSettings.jsx`, replace the provider SelectControl (lines 90-119) with a description text:

```jsx
{ settings.auto_detect && (
    <p className="description">
        { __(
            'CloudFlare sites are detected automatically. Other sites use WooCommerce MaxMind GeoIP database.',
            'mhm-currency-switcher'
        ) }
    </p>
) }
```

Remove the `geo_provider` SelectControl entirely — the backend uses cascade, no user choice needed.

**Step 5: Add geolocation settings sanitization to RestAPI**

In `src/Admin/RestAPI.php` `save_settings()`, add in the sanitization block:

```php
if ( isset( $params['auto_detect'] ) ) {
    $sanitized['auto_detect'] = (bool) $params['auto_detect'];
}
```

> Note: This key is likely already sanitized. Verify and skip if duplicate.

**Step 6: Add translations for new description text**

In `languages/mhm-currency-switcher-tr_TR-8cd971876f635cc43f0d9e68f8f44c64.json`, add:

```json
"CloudFlare sites are detected automatically. Other sites use WooCommerce MaxMind GeoIP database.": ["CloudFlare kullanan siteler otomatik algılanır. Diğer siteler WooCommerce MaxMind GeoIP veritabanını kullanır."]
```

Also add to `.l10n.php` and `.po` files.

**Step 7: Build React app**

Run: `cd C:\projects\mhm-currency-switcher\admin-app && npm run build`
Expected: webpack compiled successfully

**Step 8: Run full test suite**

Run: `cd C:\projects\mhm-currency-switcher && vendor/bin/phpunit -v`
Expected: All tests pass including new geolocation tests

**Step 9: Commit**

```bash
git add src/Core/DetectionService.php src/Plugin.php src/Admin/RestAPI.php \
    admin-app/src/components/tabs/AdvancedSettings.jsx admin-app/build/ \
    tests/Unit/Core/DetectionServiceTest.php \
    languages/mhm-currency-switcher-tr_TR-8cd971876f635cc43f0d9e68f8f44c64.json \
    languages/mhm-currency-switcher-tr_TR.l10n.php \
    languages/mhm-currency-switcher-tr_TR.po
git commit -m "feat: integrate geolocation into DetectionService

Detection chain: cookie → URL param → geolocation → base currency.
Geolocation uses CloudFlare CF-IPCountry header + WC MaxMind cascade.
Sets cookie on first detection to avoid re-running on every page load.
Pro feature, gated by Mode::can_use_geolocation() + settings toggle."
```

---

### Task 6: Final Verification & Push

**Step 1: Run full test suite**

Run: `cd C:\projects\mhm-currency-switcher && vendor/bin/phpunit -v`
Expected: All tests pass, 0 failures, 0 errors

**Step 2: Verify git log**

Run: `git log --oneline -8`
Expected: 5 new commits (tasks 1-5) on top of existing history

**Step 3: Push to remote**

```bash
git push origin develop
```

**Step 4: Update version if needed**

If all features are complete for v0.4.0, bump version in:
- `mhm-currency-switcher.php` (plugin header + constant)
- `package.json`
- `readme.txt`

This is optional and can be done in a separate release commit.
