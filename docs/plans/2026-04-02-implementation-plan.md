# MHM Currency Switcher — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a WooCommerce multi-currency switcher plugin with Options API + Service Layer architecture, React admin panel, and mhm-license-server integration.

**Architecture:** Single wp_option JSON for currency data, modular WC hook integration (one file per responsibility), lazy-loaded compatibility modules, React admin via wp-element, WPCS compliant.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, WooCommerce 7.0+, React (wp-element), @wordpress/scripts, PHPUnit 9.6, PHPCS (WPCS 3.x), PHPStan L6, Composer PSR-4

**Reference Plugin:** mhm-rentiva (`c:\projects\rentiva-dev\plugins\mhm-rentiva\`) — follow its bootstrapping, licensing, and Elementor patterns.

**Reference Analysis:** YayCurrency 3.3.4 (`C:\Users\manag\Downloads\yaycurrency.3.3.4\yaycurrency\`) — reverse-engineered for WC hook strategy.

---

## Task 1: Project Scaffolding

**Files:**
- Create: `mhm-currency-switcher.php`
- Create: `composer.json`
- Create: `package.json`
- Create: `phpunit.xml.dist`
- Create: `phpcs.xml.dist`
- Create: `phpstan.neon`
- Create: `.gitignore`
- Create: `readme.txt`
- Create: `src/Plugin.php`

### Step 1: Create .gitignore

```gitignore
/vendor/
/node_modules/
/admin-app/build/
.phpunit.result.cache
*.log
.DS_Store
Thumbs.db
```

### Step 2: Create composer.json

```json
{
    "name": "maxhandmade/mhm-currency-switcher",
    "description": "WooCommerce Multi-Currency Switcher",
    "type": "wordpress-plugin",
    "license": "GPL-3.0-or-later",
    "require": {
        "php": ">=7.4"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "wp-coding-standards/wpcs": "^3.2",
        "dealerdirect/phpcodesniffer-composer-installer": "^1.1",
        "php-stubs/woocommerce-stubs": "^9.0",
        "php-stubs/wordpress-stubs": "^6.0",
        "phpstan/phpstan": "^1.10",
        "szepeviktor/phpstan-wordpress": "^1.3",
        "yoast/phpunit-polyfills": "^2.0"
    },
    "autoload": {
        "psr-4": {
            "MhmCurrencySwitcher\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "MhmCurrencySwitcher\\Tests\\": "tests/"
        }
    },
    "config": {
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true
        }
    },
    "scripts": {
        "test": "@php ./vendor/bin/phpunit",
        "lint": "@php ./vendor/bin/phpcs",
        "lint:fix": "@php ./vendor/bin/phpcbf",
        "analyze": "@php ./vendor/bin/phpstan analyse"
    }
}
```

### Step 3: Create phpcs.xml.dist

```xml
<?xml version="1.0"?>
<ruleset name="MHM Currency Switcher">
    <description>WPCS rules for MHM Currency Switcher</description>

    <file>src/</file>
    <file>mhm-currency-switcher.php</file>

    <exclude-pattern>vendor/*</exclude-pattern>
    <exclude-pattern>node_modules/*</exclude-pattern>
    <exclude-pattern>admin-app/*</exclude-pattern>
    <exclude-pattern>tests/*</exclude-pattern>

    <arg name="extensions" value="php"/>
    <arg name="colors"/>
    <arg value="sp"/>

    <rule ref="WordPress-Extra"/>
    <rule ref="WordPress-Docs"/>

    <rule ref="WordPress.NamingConventions.PrefixAllGlobals">
        <properties>
            <property name="prefixes" type="array">
                <element value="mhm_cs"/>
                <element value="MhmCurrencySwitcher"/>
                <element value="mhm_currency_switcher"/>
            </property>
        </properties>
    </rule>

    <rule ref="WordPress.WP.I18n">
        <properties>
            <property name="text_domain" type="array">
                <element value="mhm-currency-switcher"/>
            </property>
        </properties>
    </rule>

    <rule ref="WordPress.Security"/>
    <rule ref="WordPress.DB"/>

    <config name="minimum_supported_wp_version" value="6.0"/>
    <config name="testVersion" value="7.4-"/>
</ruleset>
```

### Step 4: Create phpunit.xml.dist

```xml
<?xml version="1.0"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    backupGlobals="false"
    colors="true"
    cacheResult="false">
    <testsuites>
        <testsuite name="Unit">
            <directory suffix="Test.php">./tests/Unit/</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory suffix="Test.php">./tests/Integration/</directory>
        </testsuite>
    </testsuites>
    <coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">./src/</directory>
        </include>
    </coverage>
    <php>
        <const name="WP_TESTS_MULTISITE" value="0"/>
    </php>
</phpunit>
```

### Step 5: Create phpstan.neon

```neon
parameters:
    level: 6
    paths:
        - src/
    scanDirectories:
        - vendor/php-stubs/
    ignoreErrors: []
```

### Step 6: Create main plugin file — mhm-currency-switcher.php

Follow mhm-rentiva bootstrapping pattern:

```php
<?php
/**
 * Plugin Name:       MHM Currency Switcher
 * Plugin URI:        https://maxhandmade.com/mhm-currency-switcher
 * Description:       WooCommerce Multi-Currency Switcher. Let customers shop and pay in their local currency.
 * Version:           0.1.0
 * Author:            MaxHandMade
 * Author URI:        https://maxhandmade.com
 * Text Domain:       mhm-currency-switcher
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.6
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package MhmCurrencySwitcher
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// Prevent double loading.
if ( defined( 'MHM_CS_VERSION' ) ) {
    return;
}

define( 'MHM_CS_VERSION', '0.1.0' );
define( 'MHM_CS_FILE', __FILE__ );
define( 'MHM_CS_PATH', plugin_dir_path( __FILE__ ) );
define( 'MHM_CS_URL', plugin_dir_url( __FILE__ ) );
define( 'MHM_CS_BASENAME', plugin_basename( __FILE__ ) );

// Composer autoload.
if ( file_exists( MHM_CS_PATH . 'vendor/autoload.php' ) ) {
    require_once MHM_CS_PATH . 'vendor/autoload.php';
}

// PSR-4 fallback autoloader (for when Composer is not available).
spl_autoload_register(
    function ( $class_name ) {
        if ( strpos( $class_name, 'MhmCurrencySwitcher\\' ) !== 0 ) {
            return;
        }
        $relative = str_replace(
            array( 'MhmCurrencySwitcher\\', '\\' ),
            array( '', '/' ),
            $class_name
        ) . '.php';
        $path     = __DIR__ . '/src/' . $relative;
        if ( file_exists( $path ) ) {
            require_once $path;
        }
    }
);

// HPOS compatibility declaration.
add_action(
    'before_woocommerce_init',
    function () {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }
);

// Bootstrap on plugins_loaded.
add_action(
    'plugins_loaded',
    function () {
        // WooCommerce dependency check.
        if ( ! function_exists( 'WC' ) ) {
            add_action(
                'admin_notices',
                function () {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__( 'MHM Currency Switcher requires WooCommerce to be installed and activated.', 'mhm-currency-switcher' );
                    echo '</p></div>';
                }
            );
            return;
        }

        if ( class_exists( 'MhmCurrencySwitcher\\Plugin' ) ) {
            \MhmCurrencySwitcher\Plugin::bootstrap();
        }
    }
);

// Activation hook.
register_activation_hook(
    __FILE__,
    function () {
        if ( ! function_exists( 'WC' ) ) {
            wp_die(
                esc_html__( 'MHM Currency Switcher requires WooCommerce.', 'mhm-currency-switcher' ),
                'Plugin Activation Error',
                array( 'back_link' => true )
            );
        }
        // Set default options if not exist.
        if ( false === get_option( 'mhm_currency_switcher_currencies' ) ) {
            $base = get_option( 'woocommerce_currency', 'USD' );
            update_option(
                'mhm_currency_switcher_currencies',
                wp_json_encode(
                    array(
                        'base_currency' => $base,
                        'currencies'    => array(),
                    )
                ),
                true // autoload.
            );
        }
        if ( false === get_option( 'mhm_currency_switcher_settings' ) ) {
            update_option(
                'mhm_currency_switcher_settings',
                wp_json_encode(
                    array(
                        'switcher'       => array(
                            'show_flag'   => true,
                            'show_name'   => false,
                            'show_symbol' => true,
                            'show_code'   => true,
                            'size'        => 'medium',
                        ),
                        'product_widget' => array(
                            'enabled'    => true,
                            'currencies' => array(),
                            'show_flag'  => true,
                        ),
                        'detection'      => array(
                            'method'        => 'cookie',
                            'cookie_expiry' => 30,
                            'url_param'     => false,
                        ),
                    )
                ),
                true
            );
        }
        flush_rewrite_rules();
    }
);

// Deactivation hook.
register_deactivation_hook(
    __FILE__,
    function () {
        wp_clear_scheduled_hook( 'mhm_cs_rate_sync' );
        flush_rewrite_rules();
    }
);
```

### Step 7: Create src/Plugin.php

```php
<?php
declare(strict_types=1);

namespace MhmCurrencySwitcher;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin orchestrator.
 */
final class Plugin {

    private static ?self $instance      = null;
    private static bool  $bootstrapped  = false;

    public static function bootstrap(): void {
        if ( self::$bootstrapped ) {
            return;
        }
        self::$bootstrapped = true;
        self::$instance     = new self();
    }

    private function __construct() {
        add_action( 'init', array( $this, 'load_textdomain' ), 1 );
        add_action( 'init', array( $this, 'initialize_services' ), 2 );
    }

    public function load_textdomain(): void {
        load_plugin_textdomain(
            'mhm-currency-switcher',
            false,
            dirname( MHM_CS_BASENAME ) . '/languages'
        );
    }

    public function initialize_services(): void {
        // Core services — always loaded.
        // Phase 1: Core.
        // Phase 2: WooCommerce integration.
        // Phase 3: Frontend.
        // Phase 4: Admin (only on admin pages).
        // Phase 5: License.
        // Phase 6: Elementor (lazy-load).
        // Phase 7: WP-CLI commands.
    }
}
```

### Step 8: Create test bootstrap — tests/bootstrap.php

```php
<?php
declare(strict_types=1);

// Load Composer autoloader.
$autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $autoloader ) ) {
    require_once $autoloader;
}

// Load WordPress test environment if available (integration tests).
$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
if ( file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
    // Load WooCommerce before WP test suite.
    tests_add_filter(
        'muplugins_loaded',
        function () {
            $wc_plugin = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
            if ( file_exists( $wc_plugin ) ) {
                require $wc_plugin;
            }
        }
    );

    // Load our plugin.
    tests_add_filter(
        'muplugins_loaded',
        function () {
            require dirname( __DIR__ ) . '/mhm-currency-switcher.php';
        }
    );

    require $wp_tests_dir . '/includes/bootstrap.php';
}
```

### Step 9: Create first test — tests/Unit/PluginTest.php

```php
<?php
declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Smoke test: plugin classes can be loaded.
 */
class PluginTest extends TestCase {

    public function test_plugin_class_exists(): void {
        $this->assertTrue( class_exists( \MhmCurrencySwitcher\Plugin::class ) );
    }

    public function test_version_constant_defined(): void {
        $this->assertTrue( defined( 'MHM_CS_VERSION' ) );
    }
}
```

### Step 10: Run composer install and verify

```bash
cd /c/projects/mhm-currency-switcher
composer install
composer test
composer lint
```

Expected: Tests pass, lint may show minor issues to fix.

### Step 11: Commit

```bash
git add -A
git commit -m "feat: project scaffolding — composer, phpcs, phpunit, phpstan, main plugin file, Plugin singleton"
```

---

## Task 2: Core — CurrencyStore

**Files:**
- Create: `src/Core/CurrencyStore.php`
- Test: `tests/Unit/Core/CurrencyStoreTest.php`

### Step 1: Write failing tests

```php
<?php
declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use MhmCurrencySwitcher\Core\CurrencyStore;

class CurrencyStoreTest extends TestCase {

    private CurrencyStore $store;

    protected function setUp(): void {
        parent::setUp();
        $this->store = new CurrencyStore();
    }

    public function test_get_base_currency_returns_string(): void {
        $this->assertIsString( $this->store->get_base_currency() );
    }

    public function test_get_currencies_returns_array(): void {
        $this->assertIsArray( $this->store->get_currencies() );
    }

    public function test_get_enabled_currencies_filters_disabled(): void {
        $this->store->set_data(
            'TRY',
            array(
                array( 'code' => 'USD', 'enabled' => true, 'sort_order' => 0, 'rate' => array( 'type' => 'manual', 'value' => 0.029 ), 'fee' => array( 'type' => 'fixed', 'value' => 0 ), 'rounding' => array( 'type' => 'disabled', 'value' => 0, 'subtract' => 0 ), 'format' => array( 'symbol' => '$', 'position' => 'left', 'thousand_sep' => ',', 'decimal_sep' => '.', 'decimals' => 2 ), 'payment_methods' => array( 'all' ), 'countries' => array() ),
                array( 'code' => 'EUR', 'enabled' => false, 'sort_order' => 1, 'rate' => array( 'type' => 'manual', 'value' => 0.026 ), 'fee' => array( 'type' => 'fixed', 'value' => 0 ), 'rounding' => array( 'type' => 'disabled', 'value' => 0, 'subtract' => 0 ), 'format' => array( 'symbol' => "\u{20AC}", 'position' => 'left', 'thousand_sep' => '.', 'decimal_sep' => ',', 'decimals' => 2 ), 'payment_methods' => array( 'all' ), 'countries' => array() ),
            )
        );

        $enabled = $this->store->get_enabled_currencies();
        $this->assertCount( 1, $enabled );
        $this->assertSame( 'USD', $enabled[0]['code'] );
    }

    public function test_get_currency_returns_matching_currency(): void {
        $this->store->set_data(
            'TRY',
            array(
                array( 'code' => 'USD', 'enabled' => true, 'sort_order' => 0, 'rate' => array( 'type' => 'manual', 'value' => 0.029 ), 'fee' => array( 'type' => 'fixed', 'value' => 0 ), 'rounding' => array( 'type' => 'disabled', 'value' => 0, 'subtract' => 0 ), 'format' => array( 'symbol' => '$', 'position' => 'left', 'thousand_sep' => ',', 'decimal_sep' => '.', 'decimals' => 2 ), 'payment_methods' => array( 'all' ), 'countries' => array() ),
            )
        );

        $usd = $this->store->get_currency( 'USD' );
        $this->assertNotNull( $usd );
        $this->assertSame( 'USD', $usd['code'] );
    }

    public function test_get_currency_returns_null_for_unknown(): void {
        $this->store->set_data( 'TRY', array() );
        $this->assertNull( $this->store->get_currency( 'XYZ' ) );
    }

    public function test_currency_count_enforced_in_free(): void {
        $this->store->set_free_limit( 2 );
        $currencies = array(
            array( 'code' => 'USD', 'enabled' => true, 'sort_order' => 0, 'rate' => array( 'type' => 'manual', 'value' => 0.029 ), 'fee' => array( 'type' => 'fixed', 'value' => 0 ), 'rounding' => array( 'type' => 'disabled', 'value' => 0, 'subtract' => 0 ), 'format' => array( 'symbol' => '$', 'position' => 'left', 'thousand_sep' => ',', 'decimal_sep' => '.', 'decimals' => 2 ), 'payment_methods' => array( 'all' ), 'countries' => array() ),
            array( 'code' => 'EUR', 'enabled' => true, 'sort_order' => 1, 'rate' => array( 'type' => 'manual', 'value' => 0.026 ), 'fee' => array( 'type' => 'fixed', 'value' => 0 ), 'rounding' => array( 'type' => 'disabled', 'value' => 0, 'subtract' => 0 ), 'format' => array( 'symbol' => "\u{20AC}", 'position' => 'left', 'thousand_sep' => '.', 'decimal_sep' => ',', 'decimals' => 2 ), 'payment_methods' => array( 'all' ), 'countries' => array() ),
            array( 'code' => 'GBP', 'enabled' => true, 'sort_order' => 2, 'rate' => array( 'type' => 'manual', 'value' => 0.023 ), 'fee' => array( 'type' => 'fixed', 'value' => 0 ), 'rounding' => array( 'type' => 'disabled', 'value' => 0, 'subtract' => 0 ), 'format' => array( 'symbol' => "\u{00A3}", 'position' => 'left', 'thousand_sep' => ',', 'decimal_sep' => '.', 'decimals' => 2 ), 'payment_methods' => array( 'all' ), 'countries' => array() ),
        );

        $result = $this->store->enforce_limit( $currencies );
        $this->assertCount( 2, $result );
    }
}
```

### Step 2: Run tests — verify they fail

```bash
composer test -- --testsuite=Unit
```

### Step 3: Implement CurrencyStore

```php
<?php
declare(strict_types=1);

namespace MhmCurrencySwitcher\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Manages currency data stored as a single wp_option JSON.
 */
final class CurrencyStore {

    public const OPTION_KEY = 'mhm_currency_switcher_currencies';

    private string $base_currency = 'USD';
    private array  $currencies    = array();
    private int    $free_limit    = 2; // Extra currencies (base excluded).
    private bool   $loaded        = false;

    public function load(): void {
        if ( $this->loaded ) {
            return;
        }
        $raw = get_option( self::OPTION_KEY, '' );
        if ( ! empty( $raw ) ) {
            $data = json_decode( $raw, true );
            if ( is_array( $data ) ) {
                $this->base_currency = $data['base_currency'] ?? get_option( 'woocommerce_currency', 'USD' );
                $this->currencies    = $data['currencies'] ?? array();
            }
        }
        $this->loaded = true;
    }

    /**
     * Set data directly (for unit tests and REST API).
     */
    public function set_data( string $base, array $currencies ): void {
        $this->base_currency = $base;
        $this->currencies    = $currencies;
        $this->loaded        = true;
    }

    public function set_free_limit( int $limit ): void {
        $this->free_limit = $limit;
    }

    public function get_base_currency(): string {
        $this->load();
        return $this->base_currency;
    }

    public function get_currencies(): array {
        $this->load();
        return $this->currencies;
    }

    public function get_enabled_currencies(): array {
        return array_values(
            array_filter(
                $this->get_currencies(),
                fn( array $c ): bool => ! empty( $c['enabled'] )
            )
        );
    }

    /**
     * Get a single currency by code, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function get_currency( string $code ): ?array {
        foreach ( $this->get_currencies() as $currency ) {
            if ( $currency['code'] === $code ) {
                return $currency;
            }
        }
        return null;
    }

    /**
     * Enforce free-tier currency limit.
     *
     * @param array $currencies Full currency list.
     * @return array Trimmed to limit.
     */
    public function enforce_limit( array $currencies ): array {
        return array_slice( $currencies, 0, $this->free_limit );
    }

    /**
     * Persist to database.
     */
    public function save(): bool {
        $data = wp_json_encode(
            array(
                'base_currency' => $this->base_currency,
                'currencies'    => $this->currencies,
            )
        );
        return update_option( self::OPTION_KEY, $data, true );
    }
}
```

### Step 4: Run tests — verify they pass

```bash
composer test -- --testsuite=Unit
```

### Step 5: Commit

```bash
git add src/Core/CurrencyStore.php tests/Unit/Core/CurrencyStoreTest.php
git commit -m "feat: CurrencyStore — wp_option JSON CRUD with free-tier limit"
```

---

## Task 3: Core — Converter

**Files:**
- Create: `src/Core/Converter.php`
- Test: `tests/Unit/Core/ConverterTest.php`

### Step 1: Write failing tests

```php
<?php
declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;

class ConverterTest extends TestCase {

    private Converter     $converter;
    private CurrencyStore $store;

    protected function setUp(): void {
        parent::setUp();
        $this->store = new CurrencyStore();
        $this->store->set_data(
            'TRY',
            array(
                array(
                    'code'     => 'USD',
                    'enabled'  => true,
                    'sort_order' => 0,
                    'rate'     => array( 'type' => 'manual', 'value' => 0.03 ),
                    'fee'      => array( 'type' => 'percentage', 'value' => 2.0 ),
                    'rounding' => array( 'type' => 'disabled', 'value' => 0, 'subtract' => 0 ),
                    'format'   => array( 'symbol' => '$', 'position' => 'left', 'thousand_sep' => ',', 'decimal_sep' => '.', 'decimals' => 2 ),
                    'payment_methods' => array( 'all' ),
                    'countries' => array(),
                ),
                array(
                    'code'     => 'EUR',
                    'enabled'  => true,
                    'sort_order' => 1,
                    'rate'     => array( 'type' => 'manual', 'value' => 0.025 ),
                    'fee'      => array( 'type' => 'fixed', 'value' => 0.001 ),
                    'rounding' => array( 'type' => 'nearest', 'value' => 1.0, 'subtract' => 0.01 ),
                    'format'   => array( 'symbol' => "\u{20AC}", 'position' => 'right', 'thousand_sep' => '.', 'decimal_sep' => ',', 'decimals' => 2 ),
                    'payment_methods' => array( 'all' ),
                    'countries' => array(),
                ),
            )
        );
        $this->converter = new Converter( $this->store );
    }

    public function test_convert_basic(): void {
        // 1000 TRY * 0.03 (rate) * 1.02 (2% fee) = 30.6
        $result = $this->converter->convert( 1000.0, 'USD' );
        $this->assertEqualsWithDelta( 30.6, $result, 0.001 );
    }

    public function test_convert_with_fixed_fee(): void {
        // 1000 TRY * (0.025 + 0.001) = 26.0
        $result = $this->converter->convert( 1000.0, 'EUR' );
        $this->assertEqualsWithDelta( 26.0, $result, 0.001 );
    }

    public function test_convert_base_currency_returns_original(): void {
        $result = $this->converter->convert( 1000.0, 'TRY' );
        $this->assertSame( 1000.0, $result );
    }

    public function test_convert_zero_price_returns_zero(): void {
        $result = $this->converter->convert( 0.0, 'USD' );
        $this->assertSame( 0.0, $result );
    }

    public function test_convert_negative_price_returns_original(): void {
        $result = $this->converter->convert( -100.0, 'USD' );
        $this->assertSame( -100.0, $result );
    }

    public function test_convert_unknown_currency_returns_original(): void {
        $result = $this->converter->convert( 1000.0, 'XYZ' );
        $this->assertSame( 1000.0, $result );
    }

    public function test_convert_with_rounding_nearest(): void {
        // 1000 TRY → EUR: 26.0, rounding nearest to 1.0 = 26.0, subtract 0.01 = 25.99
        $result = $this->converter->convert_with_rounding( 1000.0, 'EUR' );
        $this->assertEqualsWithDelta( 25.99, $result, 0.001 );
    }

    public function test_get_effective_rate_with_percentage(): void {
        // USD: 0.03 * 1.02 = 0.0306
        $rate = $this->converter->get_rate( 'USD' );
        $this->assertEqualsWithDelta( 0.0306, $rate, 0.0001 );
    }

    public function test_get_raw_rate(): void {
        $rate = $this->converter->get_raw_rate( 'USD' );
        $this->assertEqualsWithDelta( 0.03, $rate, 0.0001 );
    }

    public function test_revert_price(): void {
        // Reverse: 30.6 USD / 0.0306 = 1000 TRY
        $result = $this->converter->revert( 30.6, 'USD' );
        $this->assertEqualsWithDelta( 1000.0, $result, 0.1 );
    }
}
```

### Step 2: Run tests — verify they fail

### Step 3: Implement Converter

```php
<?php
declare(strict_types=1);

namespace MhmCurrencySwitcher\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Price conversion engine.
 */
final class Converter {

    private CurrencyStore $store;

    public function __construct( CurrencyStore $store ) {
        $this->store = $store;
    }

    public function convert( float $price, string $to ): float {
        if ( $price <= 0.0 || $to === $this->store->get_base_currency() ) {
            return $price;
        }

        $rate = $this->get_rate( $to );
        if ( 0.0 === $rate ) {
            return $price;
        }

        return $price * $rate;
    }

    public function convert_with_rounding( float $price, string $to ): float {
        $converted = $this->convert( $price, $to );
        if ( $converted === $price ) {
            return $converted;
        }

        $currency = $this->store->get_currency( $to );
        if ( null === $currency ) {
            return $converted;
        }

        return $this->apply_rounding( $converted, $currency['rounding'] );
    }

    /**
     * Get effective rate including fee.
     */
    public function get_rate( string $code ): float {
        $currency = $this->store->get_currency( $code );
        if ( null === $currency ) {
            return 0.0;
        }

        $raw_rate = (float) ( $currency['rate']['value'] ?? 0 );
        $fee      = $currency['fee'] ?? array( 'type' => 'fixed', 'value' => 0 );

        if ( 'percentage' === $fee['type'] ) {
            return $raw_rate * ( 1 + ( (float) $fee['value'] / 100 ) );
        }

        return $raw_rate + (float) $fee['value'];
    }

    public function get_raw_rate( string $code ): float {
        $currency = $this->store->get_currency( $code );
        if ( null === $currency ) {
            return 0.0;
        }
        return (float) ( $currency['rate']['value'] ?? 0 );
    }

    /**
     * Reverse conversion (converted price back to base currency).
     */
    public function revert( float $price, string $from ): float {
        if ( $price <= 0.0 || $from === $this->store->get_base_currency() ) {
            return $price;
        }

        $rate = $this->get_rate( $from );
        if ( 0.0 === $rate ) {
            return $price;
        }

        return $price / $rate;
    }

    private function apply_rounding( float $price, array $rounding ): float {
        $type     = $rounding['type'] ?? 'disabled';
        $value    = (float) ( $rounding['value'] ?? 0 );
        $subtract = (float) ( $rounding['subtract'] ?? 0 );

        if ( 'disabled' === $type || $value <= 0.0 ) {
            return $price;
        }

        switch ( $type ) {
            case 'nearest':
                $price = round( $price / $value ) * $value;
                break;
            case 'up':
                $price = ceil( $price / $value ) * $value;
                break;
            case 'down':
                $price = floor( $price / $value ) * $value;
                break;
        }

        return $price - $subtract;
    }
}
```

### Step 4: Run tests — verify they pass

### Step 5: Commit

```bash
git add src/Core/Converter.php tests/Unit/Core/ConverterTest.php
git commit -m "feat: Converter — price conversion engine with fee, rounding, revert"
```

---

## Task 4: Core — RateProvider

**Files:**
- Create: `src/Core/RateProvider.php`
- Test: `tests/Unit/Core/RateProviderTest.php`

### Step 1: Write failing tests

Test the rate parsing logic (mock HTTP responses). Key tests:
- `test_parse_exchangerate_api_response` — valid JSON → rates array
- `test_parse_fawaz_api_response` — valid JSON → rates array
- `test_fallback_on_primary_failure` — primary returns error → fallback used
- `test_get_rate_for_valid_pair` — TRY→USD returns float
- `test_get_rate_for_invalid_pair` — returns null

### Step 2: Implement RateProvider

Core methods:
- `fetch_rates( string $base ): array` — fetches from API chain, returns `['USD' => 0.029, 'EUR' => 0.025, ...]`
- `fetch_single_rate( string $base, string $target ): ?float`
- Private: `fetch_from_exchangerate_api()`, `fetch_from_fawaz_api()`
- Uses `wp_remote_get()` with 10s timeout
- Stores in transient: `mhm_cs_rates_{base}`

### Step 3: Run tests, verify pass

### Step 4: Commit

```bash
git add src/Core/RateProvider.php tests/Unit/Core/RateProviderTest.php
git commit -m "feat: RateProvider — exchange rate API with fallback chain"
```

---

## Task 5: Core — DetectionService

**Files:**
- Create: `src/Core/DetectionService.php`
- Test: `tests/Unit/Core/DetectionServiceTest.php`

### Step 1: Write failing tests

Key tests:
- `test_returns_default_when_no_cookie_no_param` — returns base currency
- `test_returns_currency_from_cookie` — simulated cookie → returns that code
- `test_returns_currency_from_url_param` — simulated `$_GET['currency']` → returns code
- `test_ignores_invalid_currency_code` — cookie has 'INVALID' → returns default
- `test_cookie_priority_over_default` — cookie set → returns cookie value, not default

### Step 2: Implement DetectionService

Core logic:
- `get_current_currency(): string` — returns currency code
- Detection order: Cookie → URL param (if enabled) → Default
- Validates detected code against enabled currencies list
- `set_currency( string $code ): void` — sets cookie

### Step 3: Run tests, verify pass

### Step 4: Commit

```bash
git add src/Core/DetectionService.php tests/Unit/Core/DetectionServiceTest.php
git commit -m "feat: DetectionService — cookie/URL param currency detection"
```

---

## Task 6: WooCommerce Integration — PriceFilter + FormatFilter

**Files:**
- Create: `src/Integration/WooCommerce/PriceFilter.php`
- Create: `src/Integration/WooCommerce/FormatFilter.php`
- Test: `tests/Integration/WooCommerce/PriceFilterTest.php`

### Step 1: Write integration tests

Tests require WP test environment (`WP_UnitTestCase`). Key tests:
- `test_product_price_converted` — create WC product $100, switch to EUR, assert displayed price is converted
- `test_variation_prices_converted` — variable product, all variation prices converted
- `test_base_currency_price_unchanged` — no conversion when base selected
- `test_currency_symbol_changes` — `woocommerce_currency_symbol` returns correct symbol
- `test_decimal_separator_changes` — EUR shows comma as decimal

### Step 2: Implement PriceFilter.php

Hooks (all at priority 100):
```php
add_filter( 'woocommerce_product_get_price', ... );
add_filter( 'woocommerce_product_get_regular_price', ... );
add_filter( 'woocommerce_product_get_sale_price', ... );
add_filter( 'woocommerce_product_variation_get_price', ... );
add_filter( 'woocommerce_variation_prices_price', ... );
add_filter( 'woocommerce_variation_prices_regular_price', ... );
add_filter( 'woocommerce_variation_prices_sale_price', ... );
add_filter( 'woocommerce_get_variation_prices_hash', ... ); // Add currency to cache hash.
```

### Step 3: Implement FormatFilter.php

Hooks:
```php
add_filter( 'woocommerce_currency', ... );
add_filter( 'woocommerce_currency_symbol', ... );
add_filter( 'pre_option_woocommerce_currency_pos', ... );
add_filter( 'wc_get_price_thousand_separator', ... );
add_filter( 'wc_get_price_decimal_separator', ... );
add_filter( 'wc_get_price_decimals', ... );
```

### Step 4: Run integration tests

```bash
composer test -- --testsuite=Integration
```

### Step 5: Commit

```bash
git add src/Integration/WooCommerce/PriceFilter.php src/Integration/WooCommerce/FormatFilter.php tests/Integration/WooCommerce/PriceFilterTest.php
git commit -m "feat: PriceFilter + FormatFilter — WC product price conversion and formatting"
```

---

## Task 7: WooCommerce Integration — CartFilter

**Files:**
- Create: `src/Integration/WooCommerce/CartFilter.php`
- Test: `tests/Integration/WooCommerce/CartFilterTest.php`

### Step 1: Write integration tests

Key tests:
- `test_cart_fees_converted` — cart fee in base currency → displayed in target
- `test_cart_total_converted` — cart total reflects converted prices
- `test_checkout_order_stores_currency_meta` — after checkout, order meta has `_mhm_cs_currency_code`
- `test_cart_item_has_currency_meta` — double-conversion protection meta exists

### Step 2: Implement CartFilter.php

Hooks:
```php
add_action( 'woocommerce_cart_calculate_fees', ... );
add_filter( 'woocommerce_cart_product_subtotal', ... );
add_filter( 'woocommerce_cart_subtotal', ... );
add_filter( 'woocommerce_cart_totals_fee_html', ... );
add_filter( 'woocommerce_cart_total', ... );
add_filter( 'woocommerce_cart_tax_totals', ... );
add_action( 'woocommerce_checkout_create_order', ... ); // Save order meta.
add_filter( 'woocommerce_cart_contents', ... ); // Add mhm_cs_currency_code to cart items.
```

### Step 3: Run tests, verify pass

### Step 4: Commit

```bash
git add src/Integration/WooCommerce/CartFilter.php tests/Integration/WooCommerce/CartFilterTest.php
git commit -m "feat: CartFilter — cart/checkout conversion with order meta storage"
```

---

## Task 8: WooCommerce Integration — ShippingFilter + CouponFilter

**Files:**
- Create: `src/Integration/WooCommerce/ShippingFilter.php`
- Create: `src/Integration/WooCommerce/CouponFilter.php`
- Test: `tests/Integration/WooCommerce/ShippingFilterTest.php`
- Test: `tests/Integration/WooCommerce/CouponFilterTest.php`

### Step 1: Write tests and implement ShippingFilter

Hook: `woocommerce_package_rates` → iterate rates, convert `$rate->cost`.

### Step 2: Write tests and implement CouponFilter

Hooks:
- `woocommerce_coupon_get_amount` → convert fixed amount coupons
- `woocommerce_coupon_get_minimum_amount` → convert min threshold
- `woocommerce_coupon_get_maximum_amount` → convert max threshold
- Percentage coupons: do NOT convert (percentage is currency-agnostic)

### Step 3: Run all tests

### Step 4: Commit

```bash
git add src/Integration/WooCommerce/ShippingFilter.php src/Integration/WooCommerce/CouponFilter.php tests/Integration/WooCommerce/ShippingFilterTest.php tests/Integration/WooCommerce/CouponFilterTest.php
git commit -m "feat: ShippingFilter + CouponFilter — shipping cost and coupon amount conversion"
```

---

## Task 9: WooCommerce Integration — OrderFilter

**Files:**
- Create: `src/Integration/WooCommerce/OrderFilter.php`
- Test: `tests/Integration/WooCommerce/OrderFilterTest.php`

### Step 1: Write tests

Key tests:
- `test_order_item_totals_display_in_order_currency` — order paid in USD shows USD
- `test_email_uses_order_currency` — email hooks return order's currency, not base
- `test_revert_to_base_for_analytics` — optional sync back to base for WC reports

### Step 2: Implement OrderFilter

Hooks:
```php
add_filter( 'woocommerce_get_order_item_totals', ... );       // Display in order currency.
add_filter( 'woocommerce_order_subtotal_to_display', ... );    // Subtotal format.
add_action( 'woocommerce_email_order_details', ... );          // Store order ID in context.
add_filter( 'woocommerce_currency', ... );                     // In email context, return order currency.
add_filter( 'woocommerce_currency_symbol', ... );              // In email context, return order symbol.
```

### Step 3: Run tests, commit

```bash
git add src/Integration/WooCommerce/OrderFilter.php tests/Integration/WooCommerce/OrderFilterTest.php
git commit -m "feat: OrderFilter — order display and email currency handling"
```

---

## Task 10: WooCommerce Integration — RestApiFilter

**Files:**
- Create: `src/Integration/WooCommerce/RestApiFilter.php`
- Test: `tests/Integration/WooCommerce/RestApiFilterTest.php`

### Step 1: Write tests

- `test_products_endpoint_with_currency_param` — `?currency=USD` returns converted prices
- `test_products_endpoint_without_param_returns_base` — no param = base currency

### Step 2: Implement RestApiFilter

Hook into `rest_prepare_product_object` (or `woocommerce_rest_prepare_product_object`).
Check `$request->get_param('currency')`. If set and valid, convert price fields in response.

### Step 3: Commit

```bash
git add src/Integration/WooCommerce/RestApiFilter.php tests/Integration/WooCommerce/RestApiFilterTest.php
git commit -m "feat: RestApiFilter — WC REST API currency parameter support (Pro)"
```

---

## Task 11: Frontend — Switcher (Shortcode + Widget)

**Files:**
- Create: `src/Frontend/Switcher.php`
- Create: `src/Frontend/Enqueue.php`
- Create: `assets/css/switcher.css`
- Create: `assets/js/switcher.js`
- Test: `tests/Integration/Frontend/SwitcherTest.php`

### Step 1: Write tests

- `test_shortcode_renders_dropdown` — `do_shortcode('[mhm_currency_switcher]')` contains expected HTML
- `test_shortcode_includes_enabled_currencies` — all enabled currencies in dropdown
- `test_shortcode_marks_current_as_selected` — current currency has `selected` class
- `test_widget_registered` — widget class exists in global widgets

### Step 2: Implement Switcher.php

- Register shortcode: `mhm_currency_switcher`
- Register widget: `MHM_CS_Switcher_Widget extends WP_Widget`
- Register block: `mhm-cs/currency-switcher` (block.json + server-side render)
- Nav menu filter: `wp_nav_menu_items` (optional, admin-toggled)

Output HTML:
```html
<div class="mhm-cs-switcher mhm-cs-size--{size}" data-current="{current}">
    <button class="mhm-cs-selected" aria-expanded="false">
        <img src="{flag_url}" alt="{code}" class="mhm-cs-flag" />
        <span class="mhm-cs-label">{symbol} {code}</span>
        <span class="mhm-cs-arrow">&#9662;</span>
    </button>
    <ul class="mhm-cs-dropdown" role="listbox">
        {foreach enabled currency}
        <li role="option" data-currency="{code}">
            <img src="{flag_url}" alt="{code}" class="mhm-cs-flag" />
            <span>{symbol} {code}</span>
        </li>
        {/foreach}
    </ul>
</div>
```

### Step 3: Implement switcher.js

```javascript
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.mhm-cs-switcher').forEach(function(switcher) {
        var btn = switcher.querySelector('.mhm-cs-selected');
        var dropdown = switcher.querySelector('.mhm-cs-dropdown');

        btn.addEventListener('click', function() {
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            btn.setAttribute('aria-expanded', !expanded);
            dropdown.classList.toggle('mhm-cs-open');
        });

        dropdown.querySelectorAll('li').forEach(function(item) {
            item.addEventListener('click', function() {
                var code = item.getAttribute('data-currency');
                document.cookie = 'mhm_cs_currency=' + code + ';path=/;max-age=' + (30 * 86400) + ';SameSite=Lax';
                window.location.reload();
            });
        });
    });

    // Close dropdown on outside click.
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.mhm-cs-switcher')) {
            document.querySelectorAll('.mhm-cs-dropdown').forEach(function(d) {
                d.classList.remove('mhm-cs-open');
            });
            document.querySelectorAll('.mhm-cs-selected').forEach(function(b) {
                b.setAttribute('aria-expanded', 'false');
            });
        }
    });
});
```

### Step 4: Implement switcher.css (minimal, clean)

### Step 5: Implement Enqueue.php

Conditional loading: only enqueue on frontend when WC is active.

### Step 6: Run tests, commit

```bash
git add src/Frontend/ assets/css/switcher.css assets/js/switcher.js tests/Integration/Frontend/SwitcherTest.php
git commit -m "feat: Currency Switcher — shortcode, widget, block, JS, CSS"
```

---

## Task 12: Frontend — ProductWidget

**Files:**
- Create: `src/Frontend/ProductWidget.php`
- Create: `assets/css/product-widget.css`
- Test: `tests/Integration/Frontend/ProductWidgetTest.php`

### Step 1: Write tests

- `test_shortcode_renders_flagged_prices` — `[mhm_currency_prices]` outputs flag + price for configured currencies
- `test_widget_shows_on_product_page` — hook fires on single product
- `test_empty_when_no_currencies_configured` — returns empty if product_widget currencies empty

### Step 2: Implement ProductWidget.php

- Hook: `woocommerce_single_product_summary` (priority 15 — after price)
- Shortcode: `mhm_currency_prices`
- Reads `product_widget.currencies` from settings
- For each currency: convert current product price, render with flag SVG

Output:
```html
<div class="mhm-cs-product-prices">
    <span class="mhm-cs-product-price">
        <img src="flags/us.svg" alt="USD" class="mhm-cs-flag" />
        <span class="mhm-cs-amount">$43.50</span>
    </span>
    <span class="mhm-cs-separator">|</span>
    <!-- more currencies -->
</div>
```

### Step 3: CSS for product widget (inline-flex, responsive)

### Step 4: Run tests, commit

```bash
git add src/Frontend/ProductWidget.php assets/css/product-widget.css tests/Integration/Frontend/ProductWidgetTest.php
git commit -m "feat: ProductWidget — flagged price display on product pages"
```

---

## Task 13: Frontend — SVG Flags

**Files:**
- Create: `assets/images/flags/` directory with SVG flag files

### Step 1: Download and add SVG flags

Use flag-icons open source project (MIT license). Only include flags for currencies supported by WooCommerce (~100 flags). Each flag ~1-2 KB SVG.

File naming: `{country_code_lowercase}.svg` — e.g., `us.svg`, `eu.svg`, `gb.svg`, `tr.svg`.

Create a mapping utility: `src/Core/FlagMapper.php` — maps currency code → country code for flag lookup (e.g., USD → us, EUR → eu, GBP → gb, TRY → tr).

### Step 2: Commit

```bash
git add assets/images/flags/ src/Core/FlagMapper.php
git commit -m "feat: SVG country flags + currency-to-flag mapper"
```

---

## Task 14: Admin — REST API

**Files:**
- Create: `src/Admin/RestAPI.php`
- Test: `tests/Integration/Admin/RestAPITest.php`

### Step 1: Write tests

- `test_get_settings_requires_auth` — unauthenticated → 401
- `test_get_settings_returns_data` — authenticated admin → JSON settings
- `test_post_settings_saves_data` — POST with valid data → saved
- `test_sync_rates_fetches_new_rates` — POST sync → rates updated
- `test_public_rates_endpoint` — GET rates → returns rate object (no auth required)

### Step 2: Implement RestAPI.php

Register routes on `rest_api_init`:

```php
register_rest_route( 'mhm-currency/v1', '/settings', array(
    array( 'methods' => 'GET', 'callback' => ..., 'permission_callback' => ... ),
    array( 'methods' => 'POST', 'callback' => ..., 'permission_callback' => ... ),
) );
register_rest_route( 'mhm-currency/v1', '/currencies', array( ... ) );
register_rest_route( 'mhm-currency/v1', '/rates/sync', array( ... ) );
register_rest_route( 'mhm-currency/v1', '/rates/preview', array( ... ) );
register_rest_route( 'mhm-currency/v1', '/rates', array(
    'methods' => 'GET',
    'callback' => ...,
    'permission_callback' => '__return_true', // Public endpoint.
) );
```

Permission callback for admin routes: `current_user_can( 'manage_woocommerce' )`.

All inputs: sanitized with `sanitize_text_field()`, `absint()`, `rest_sanitize_boolean()`.
All outputs: validated schema.

### Step 3: Run tests, commit

```bash
git add src/Admin/RestAPI.php tests/Integration/Admin/RestAPITest.php
git commit -m "feat: REST API — settings, currencies, rates endpoints"
```

---

## Task 15: Admin — React App Setup

**Files:**
- Create: `package.json`
- Create: `admin-app/src/index.js`
- Create: `admin-app/src/App.jsx`
- Create: `src/Admin/Settings.php`

### Step 1: Create package.json

```json
{
    "name": "mhm-currency-switcher",
    "version": "0.1.0",
    "private": true,
    "scripts": {
        "build": "wp-scripts build admin-app/src/index.js --output-path=admin-app/build",
        "start": "wp-scripts start admin-app/src/index.js --output-path=admin-app/build",
        "lint:js": "wp-scripts lint-js admin-app/src/",
        "format": "wp-scripts format admin-app/src/"
    },
    "devDependencies": {
        "@wordpress/scripts": "^28.0"
    },
    "dependencies": {
        "@dnd-kit/core": "^6.0",
        "@dnd-kit/sortable": "^8.0",
        "@wordpress/api-fetch": "^7.0",
        "@wordpress/components": "^28.0",
        "@wordpress/element": "^6.0",
        "@wordpress/i18n": "^5.0"
    }
}
```

### Step 2: Create admin-app/src/index.js — entry point

```javascript
import { createRoot } from '@wordpress/element';
import App from './App';

const container = document.getElementById( 'mhm-cs-admin-root' );
if ( container ) {
    createRoot( container ).render( <App /> );
}
```

### Step 3: Create admin-app/src/App.jsx — tabbed layout shell

Use `@wordpress/components` TabPanel. Localized data via `wp_localize_script` (settings, currencies, is_pro flag, WC payment methods, WC currencies list).

### Step 4: Implement src/Admin/Settings.php

- Add submenu: `add_submenu_page( 'woocommerce', 'MHM Currency', 'MHM Currency', 'manage_woocommerce', 'mhm-currency-switcher', ... )`
- Render: single `<div id="mhm-cs-admin-root"></div>`
- Enqueue: `admin-app/build/index.js` + `admin-app/build/index.css`
- Localize: pass settings, currencies, is_pro, nonce, rest URL

### Step 5: npm install && npm run build

### Step 6: Commit

```bash
git add package.json admin-app/ src/Admin/Settings.php
git commit -m "feat: Admin panel — React app shell with wp-scripts build"
```

---

## Task 16: Admin — React Tabs Implementation

**Files:**
- Create: `admin-app/src/components/TabNavigation.jsx`
- Create: `admin-app/src/components/tabs/ManageCurrencies.jsx`
- Create: `admin-app/src/components/tabs/CurrencyTable.jsx`
- Create: `admin-app/src/components/tabs/CurrencyRow.jsx`
- Create: `admin-app/src/components/tabs/AddCurrencyModal.jsx`
- Create: `admin-app/src/components/tabs/DisplayOptions.jsx`
- Create: `admin-app/src/components/tabs/CheckoutOptions.jsx`
- Create: `admin-app/src/components/tabs/AdvancedSettings.jsx`
- Create: `admin-app/src/components/tabs/License.jsx`
- Create: `admin-app/src/components/shared/ProGate.jsx`
- Create: `admin-app/src/api/settings.js`

### Step 1: Implement api/settings.js — REST API client

Uses `@wordpress/api-fetch` with nonce.

### Step 2: Implement ManageCurrencies tab

- Currency table with drag-drop sorting (@dnd-kit)
- Each row: currency dropdown, preview, rate (auto/manual), fee (fixed/percentage), actions
- "+ New Currency" button (respects free limit)
- "Save Changes" button → POST to REST API
- "Sync Rates" button → POST rates/sync

### Step 3: Implement DisplayOptions tab

- Switcher config: show flag, name, symbol, code toggles
- Size: small/medium radio
- Product widget config: enable toggle, currency multi-select (max 5), show flag toggle
- Live preview component

### Step 4: Implement CheckoutOptions tab (Pro-gated)

Wrapped in `<ProGate>` component. Shows payment method restrictions per currency.

### Step 5: Implement AdvancedSettings tab (Pro-gated)

Geolocation, auto rate cron interval, cache compat, multilingual mapping.

### Step 6: Implement License tab

License key input, activation status, Pro feature list with lock/unlock status.

### Step 7: Implement ProGate.jsx

```jsx
function ProGate({ isPro, children }) {
    if (isPro) return children;
    return (
        <div className="mhm-cs-pro-gate">
            <div className="mhm-cs-pro-overlay">
                <span className="dashicons dashicons-lock"></span>
                <p>{__('This feature requires MHM Currency Switcher Pro', 'mhm-currency-switcher')}</p>
                <a href="#license" className="button button-primary">
                    {__('Unlock with Pro', 'mhm-currency-switcher')}
                </a>
            </div>
            <div className="mhm-cs-pro-blurred">{children}</div>
        </div>
    );
}
```

### Step 8: npm run build, test in browser

### Step 9: Commit

```bash
git add admin-app/src/
git commit -m "feat: Admin React tabs — currencies, display, checkout, advanced, license"
```

---

## Task 17: Elementor Integration

**Files:**
- Create: `src/Integration/Elementor/ElementorIntegration.php`
- Create: `src/Integration/Elementor/SwitcherWidget.php`
- Create: `src/Integration/Elementor/PriceDisplayWidget.php`

### Step 1: Implement ElementorIntegration.php

Follow mhm-rentiva pattern exactly:
- `is_elementor_active()` check
- `init()` with `elementor/loaded` hook
- `register_widgets()` on `elementor/widgets/register`
- `register_category()` — "MHM Currency Switcher" category

### Step 2: Implement SwitcherWidget.php

Extends `\Elementor\Widget_Base`. Controls:
- Content: size select, show_flag, show_name, show_symbol, show_code toggles
- Style: typography, colors, spacing
- Render: delegates to `Switcher::render_shortcode()` with atts from controls

### Step 3: Implement PriceDisplayWidget.php

Controls:
- Content: currencies multi-select, show_flag, layout (inline/stacked)
- Style: typography, flag size, separator style
- Render: delegates to `ProductWidget::render_shortcode()` with atts

### Step 4: Commit

```bash
git add src/Integration/Elementor/
git commit -m "feat: Elementor integration — switcher and price display widgets"
```

---

## Task 18: License Integration

**Files:**
- Create: `src/License/LicenseManager.php`
- Create: `src/License/Mode.php`
- Test: `tests/Unit/License/ModeTest.php`

### Step 1: Write tests for Mode

- `test_is_pro_returns_false_by_default` — no license = lite
- `test_is_pro_returns_true_when_active` — mock active license = pro
- `test_can_use_geolocation_requires_pro` — feature gate
- `test_can_use_fixed_prices_requires_pro` — feature gate

### Step 2: Implement Mode.php

```php
<?php
declare(strict_types=1);

namespace MhmCurrencySwitcher\License;

defined( 'ABSPATH' ) || exit;

final class Mode {

    public static function is_pro(): bool {
        return LicenseManager::instance()->is_active();
    }

    public static function is_lite(): bool {
        return ! self::is_pro();
    }

    public static function can_use_geolocation(): bool {
        return self::is_pro();
    }

    public static function can_use_fixed_prices(): bool {
        return self::is_pro();
    }

    public static function can_use_payment_restrictions(): bool {
        return self::is_pro();
    }

    public static function can_use_auto_rate_update(): bool {
        return self::is_pro();
    }

    public static function can_use_multilingual(): bool {
        return self::is_pro();
    }

    public static function can_use_rest_api_filter(): bool {
        return self::is_pro();
    }

    public static function get_currency_limit(): int {
        return self::is_pro() ? PHP_INT_MAX : 2; // 2 extra (3 total with base).
    }
}
```

### Step 3: Implement LicenseManager.php

Follow mhm-rentiva LicenseManager pattern:
- Singleton
- Option key: `mhm_currency_switcher_license`
- Daily cron validation: `mhm_cs_license_daily`
- Weekly instance check-in: `mhm_cs_instance_checkin`
- Admin page: license key input + activate/deactivate buttons
- `is_active(): bool` — checks license status

### Step 4: Integrate mhm-plugin-updater

In main plugin file or LicenseManager:
```php
if ( class_exists( 'MHM\\PluginUpdater\\Updater' ) ) {
    \MHM\PluginUpdater\Updater::init( array(
        'file'  => MHM_CS_FILE,
        'repo'  => 'MaxHandMade/mhm-currency-switcher',
        'token' => null, // Embedded for customer builds.
    ) );
}
```

### Step 5: Run tests, commit

```bash
git add src/License/ tests/Unit/License/ModeTest.php
git commit -m "feat: License integration — Mode feature gates + LicenseManager"
```

---

## Task 19: WP-CLI Commands

**Files:**
- Create: `src/CLI/Commands.php`
- Test: `tests/Integration/CLI/CommandsTest.php`

### Step 1: Implement Commands.php

```php
<?php
declare(strict_types=1);

namespace MhmCurrencySwitcher\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * MHM Currency Switcher WP-CLI commands.
 */
final class Commands {

    /**
     * Sync exchange rates from API.
     *
     * ## EXAMPLES
     *     wp mhm-cs rates sync
     *
     * @subcommand rates sync
     */
    public function rates_sync( $args, $assoc_args ): void {
        // Fetch rates via RateProvider, update CurrencyStore, report.
    }

    /**
     * Get rate for a specific currency.
     *
     * ## OPTIONS
     * <currency>
     * : Currency code (e.g., USD)
     *
     * ## EXAMPLES
     *     wp mhm-cs rates get USD
     *
     * @subcommand rates get
     */
    public function rates_get( $args, $assoc_args ): void {
        // Display rate for given currency.
    }

    /**
     * Flush transient cache.
     *
     * ## EXAMPLES
     *     wp mhm-cs cache flush
     *
     * @subcommand cache flush
     */
    public function cache_flush( $args, $assoc_args ): void {
        // Delete transients, report.
    }

    /**
     * List all configured currencies.
     *
     * ## EXAMPLES
     *     wp mhm-cs currencies list
     *
     * @subcommand currencies list
     */
    public function currencies_list( $args, $assoc_args ): void {
        // Table format output via WP_CLI\Utils\format_items.
    }

    /**
     * Show plugin status.
     *
     * ## EXAMPLES
     *     wp mhm-cs status
     */
    public function status( $args, $assoc_args ): void {
        // Version, license status, currency count, last rate sync.
    }
}
```

Register in Plugin.php:
```php
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    \WP_CLI::add_command( 'mhm-cs', \MhmCurrencySwitcher\CLI\Commands::class );
}
```

### Step 2: Run WP-CLI tests, commit

```bash
git add src/CLI/Commands.php tests/Integration/CLI/CommandsTest.php
git commit -m "feat: WP-CLI commands — rates sync/get, cache flush, currencies list, status"
```

---

## Task 20: Wire Everything in Plugin.php

**Files:**
- Modify: `src/Plugin.php`

### Step 1: Update initialize_services()

```php
public function initialize_services(): void {
    // Core services.
    $store     = new \MhmCurrencySwitcher\Core\CurrencyStore();
    $store->load();
    $converter = new \MhmCurrencySwitcher\Core\Converter( $store );
    $detection = new \MhmCurrencySwitcher\Core\DetectionService( $store );

    // WooCommerce integration (always when WC active).
    ( new \MhmCurrencySwitcher\Integration\WooCommerce\PriceFilter( $converter, $detection ) )->init();
    ( new \MhmCurrencySwitcher\Integration\WooCommerce\FormatFilter( $store, $detection ) )->init();
    ( new \MhmCurrencySwitcher\Integration\WooCommerce\CartFilter( $converter, $detection ) )->init();
    ( new \MhmCurrencySwitcher\Integration\WooCommerce\ShippingFilter( $converter, $detection ) )->init();
    ( new \MhmCurrencySwitcher\Integration\WooCommerce\CouponFilter( $converter, $detection ) )->init();
    ( new \MhmCurrencySwitcher\Integration\WooCommerce\OrderFilter( $store, $detection ) )->init();

    // Pro-only WC integration.
    if ( \MhmCurrencySwitcher\License\Mode::can_use_rest_api_filter() ) {
        ( new \MhmCurrencySwitcher\Integration\WooCommerce\RestApiFilter( $converter, $store ) )->init();
    }

    // Frontend.
    if ( ! is_admin() ) {
        ( new \MhmCurrencySwitcher\Frontend\Switcher( $store, $detection ) )->init();
        ( new \MhmCurrencySwitcher\Frontend\ProductWidget( $store, $converter ) )->init();
        ( new \MhmCurrencySwitcher\Frontend\Enqueue() )->init();
    }

    // Admin.
    if ( is_admin() ) {
        ( new \MhmCurrencySwitcher\Admin\Settings() )->init();
        ( new \MhmCurrencySwitcher\Admin\RestAPI( $store, $converter ) )->init();
    }

    // REST API (must be available outside admin too).
    add_action( 'rest_api_init', function () use ( $store, $converter ) {
        ( new \MhmCurrencySwitcher\Admin\RestAPI( $store, $converter ) )->register_routes();
    } );

    // License.
    \MhmCurrencySwitcher\License\LicenseManager::instance()->register();

    // Elementor (lazy-load).
    if ( did_action( 'elementor/loaded' ) ) {
        \MhmCurrencySwitcher\Integration\Elementor\ElementorIntegration::init();
    }

    // WP-CLI.
    if ( defined( 'WP_CLI' ) && \WP_CLI ) {
        \WP_CLI::add_command( 'mhm-cs', \MhmCurrencySwitcher\CLI\Commands::class );
    }

    // Scheduled rate sync (Pro only).
    if ( \MhmCurrencySwitcher\License\Mode::can_use_auto_rate_update() ) {
        add_action( 'mhm_cs_rate_sync', array( new \MhmCurrencySwitcher\Core\RateProvider(), 'scheduled_sync' ) );
    }
}
```

### Step 2: Run full test suite

```bash
composer test
composer lint
npm run build
```

### Step 3: Commit

```bash
git add src/Plugin.php
git commit -m "feat: wire all services in Plugin.php — core, WC, frontend, admin, license, CLI"
```

---

## Task 21: Compatibility Layer — Interface + MhmRentiva Module

**Files:**
- Create: `src/Integration/Compatibles/CompatibleInterface.php`
- Create: `src/Integration/Compatibles/MhmRentiva.php`

### Step 1: Implement CompatibleInterface

```php
<?php
declare(strict_types=1);

namespace MhmCurrencySwitcher\Integration\Compatibles;

defined( 'ABSPATH' ) || exit;

interface CompatibleInterface {
    /**
     * Whether the target plugin is installed and active.
     */
    public static function is_active(): bool;

    /**
     * Register hooks for compatibility.
     */
    public function init(): void;
}
```

### Step 2: Implement MhmRentiva.php stub

```php
<?php
declare(strict_types=1);

namespace MhmCurrencySwitcher\Integration\Compatibles;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility module for MHM Rentiva plugin.
 * Handles: daily rates, extras, commissions, transfer prices.
 */
final class MhmRentiva implements CompatibleInterface {

    public static function is_active(): bool {
        return defined( 'MHM_RENTIVA_VERSION' );
    }

    public function init(): void {
        if ( ! self::is_active() ) {
            return;
        }
        // Hook into Rentiva pricing filters.
        // add_filter( 'mhm_rentiva_daily_price', ... );
        // add_filter( 'mhm_rentiva_extra_price', ... );
        // add_filter( 'mhm_rentiva_transfer_price', ... );
    }
}
```

### Step 3: Load compatibles in Plugin.php

```php
// Compatibility modules (Pro only).
if ( \MhmCurrencySwitcher\License\Mode::is_pro() ) {
    $compatibles = array(
        \MhmCurrencySwitcher\Integration\Compatibles\MhmRentiva::class,
    );
    foreach ( $compatibles as $compatible_class ) {
        if ( $compatible_class::is_active() ) {
            ( new $compatible_class() )->init();
        }
    }
}
```

### Step 4: Commit

```bash
git add src/Integration/Compatibles/
git commit -m "feat: Compatibility layer — interface + MhmRentiva module stub"
```

---

## Task 22: Final QA + readme.txt

**Files:**
- Update: `readme.txt`
- Run: full test suite, PHPCS, PHPStan, npm build

### Step 1: Write readme.txt

WordPress.org format with description, installation, FAQ, changelog, screenshots sections.

### Step 2: Run full QA

```bash
composer lint
composer analyze
composer test
npm run build
npm run lint:js
```

### Step 3: Fix any issues found

### Step 4: Final commit

```bash
git add -A
git commit -m "chore: readme.txt, QA fixes, v0.1.0 ready"
```

---

## Task 23: GitHub Repository Setup

### Step 1: Create private GitHub repo

```bash
cd /c/projects/mhm-currency-switcher
gh repo create MaxHandMade/mhm-currency-switcher --private --source=. --push
```

### Step 2: Verify push successful

```bash
gh repo view MaxHandMade/mhm-currency-switcher
```

---

## Summary

| Task | Component | Est. Commits |
|------|-----------|-------------|
| 1 | Project scaffolding | 1 |
| 2 | CurrencyStore | 1 |
| 3 | Converter | 1 |
| 4 | RateProvider | 1 |
| 5 | DetectionService | 1 |
| 6 | PriceFilter + FormatFilter | 1 |
| 7 | CartFilter | 1 |
| 8 | ShippingFilter + CouponFilter | 1 |
| 9 | OrderFilter | 1 |
| 10 | RestApiFilter | 1 |
| 11 | Switcher (shortcode/widget/block) | 1 |
| 12 | ProductWidget | 1 |
| 13 | SVG Flags | 1 |
| 14 | Admin REST API | 1 |
| 15 | Admin React setup | 1 |
| 16 | Admin React tabs | 1 |
| 17 | Elementor integration | 1 |
| 18 | License integration | 1 |
| 19 | WP-CLI commands | 1 |
| 20 | Plugin.php wiring | 1 |
| 21 | Compatibility layer | 1 |
| 22 | Final QA + readme | 1 |
| 23 | GitHub repo setup | 1 |
| **Total** | | **23 commits** |

**Execution order is sequential** — each task builds on previous. Tasks 2-5 (Core) can potentially be parallelized. Tasks 6-10 (WC Integration) must be sequential. Tasks 15-16 (React) can run parallel to backend tasks if frontend dev is separate.
