<?php
/**
 * Plugin Name:       MHM Currency Switcher
 * Plugin URI:        https://wpalemi.com/currency-switcher/
 * Description:       Multi-currency support for WooCommerce with real-time exchange rates and seamless checkout integration.
 * Version:           1.3.1
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            MaxHandMade
 * Author URI:        https://wpalemi.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mhm-currency-switcher
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.4
 * WC tested up to:   11.0
 *
 * @package MhmCurrencySwitcher
 */

declare(strict_types=1);

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 */
define( 'MHMCS_VERSION', '1.3.1' );

/**
 * Plugin main file.
 */
define( 'MHMCS_FILE', __FILE__ );

/**
 * Plugin directory path.
 */
define( 'MHMCS_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL.
 */
define( 'MHMCS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename.
 */
define( 'MHMCS_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Autoloader: prefer Composer, fall back to PSR-4 manual loader.
 */
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'MhmCurrencySwitcher\\';
			$len    = strlen( $prefix );

			if ( strncmp( $prefix, $class, $len ) !== 0 ) {
				return;
			}

			$relative = substr( $class, $len );
			$file     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

/**
 * Declare WooCommerce feature compatibility.
 *
 * Cart & Checkout Blocks read their amounts from the Store API rather than
 * from the classic templates, which is why the declaration is safe to make:
 * ConversionContext treats a Store API request as a money context and converts
 * server-side (see its decision table, branch 5), so the blocks receive
 * already-converted amounts instead of base ones. An undeclared plugin makes
 * WooCommerce warn the shop owner away from the blocks, so staying silent here
 * was itself misleading.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Bootstrap the plugin on plugins_loaded.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		// Check WooCommerce dependency.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__(
							'MHM Currency Switcher requires WooCommerce to be installed and activated.',
							'mhm-currency-switcher'
						)
					);
				}
			);
			return;
		}

		\MhmCurrencySwitcher\Plugin::bootstrap();
	}
);

/**
 * Activation hook: set default options.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		// See CurrencyStore::default_option_value() for the shape rationale.
		if ( false === get_option( 'mhmcs_currencies' ) ) {
			update_option( 'mhmcs_currencies', \MhmCurrencySwitcher\Core\CurrencyStore::default_option_value() );
		}

		// Default settings. The same definition seeds the upgrade path in
		// LegacyOptionMigrator — a second copy here is how the two would
		// drift apart, which is the defect that produced the migration.
		if ( false === get_option( 'mhmcs_settings' ) ) {
			update_option(
				'mhmcs_settings',
				\MhmCurrencySwitcher\Core\LegacyOptionMigrator::default_settings()
			);
		}
	}
);

/**
 * One-time cleanup of licence data left behind by versions before 1.0.0.
 *
 * The licence subsystem was removed in 1.0.0. Its scheduled event would
 * otherwise keep firing a hook nobody listens to, and its option would keep
 * the customer's licence key in the database forever. Uninstall alone does
 * not cover this, because upgrading is not uninstalling.
 *
 * @return void
 */
function mhmcs_cleanup_legacy_license_data(): void {
	if ( 'done' === get_option( 'mhmcs_legacy_license_cleanup' ) ) {
		return;
	}

	wp_clear_scheduled_hook( 'mhm_cs_license_daily' );
	delete_option( 'mhm_currency_switcher_license' );
	delete_transient( 'mhm_cs_license_visit_throttle' );

	update_option( 'mhmcs_legacy_license_cleanup', 'done', true );
}
add_action( 'plugins_loaded', 'mhmcs_cleanup_legacy_license_data' );

/**
 * One-time migration of the option names used before 0.3.0.
 *
 * The prefix rename shipped without one, so a site upgrading across it keeps
 * its data under names nothing reads any more. See LegacyOptionMigrator for
 * what is carried, what is refused, and why.
 *
 * @return void
 */
function mhmcs_migrate_legacy_options(): void {
	( new \MhmCurrencySwitcher\Core\LegacyOptionMigrator() )->run();
}

/*
 * Priority 5 is belt and braces, not a requirement. The cron decision reads
 * the settings row from Plugin::initialize_services(), hooked to `init` at
 * priority 2 — every `plugins_loaded` callback has already run by then, so
 * the default priority would order these correctly too. Running early simply
 * keeps the migration ahead of anything else on this hook that might read the
 * options, without depending on that ordering being documented anywhere.
 */
add_action( 'plugins_loaded', 'mhmcs_migrate_legacy_options', 5 );

/**
 * Deactivation hook: clean up.
 */
register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( 'mhmcs_update_rates' );
		flush_rewrite_rules();
	}
);
