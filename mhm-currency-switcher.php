<?php
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

declare(strict_types=1);

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 *
 * @var string
 */
define( 'MHMCS_VERSION', '1.0.0' );

/**
 * Plugin main file.
 *
 * @var string
 */
define( 'MHMCS_FILE', __FILE__ );

/**
 * Plugin directory path.
 *
 * @var string
 */
define( 'MHMCS_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL.
 *
 * @var string
 */
define( 'MHMCS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename.
 *
 * @var string
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
 * Declare HPOS compatibility.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
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

		// Default settings.
		if ( false === get_option( 'mhmcs_settings' ) ) {
			update_option(
				'mhmcs_settings',
				array(
					'auto_detect'  => true,
					// Read by ConversionContext decision 4; written by the
					// Advanced Settings tab. Same spelling in all three.
					'cache_compat' => true,
					'switcher'     => array(
						'show_flag'   => true,
						'show_name'   => false,
						'show_symbol' => true,
						'show_code'   => true,
						'size'        => 'medium',
					),
				)
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
 * Deactivation hook: clean up.
 */
register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( 'mhmcs_update_rates' );
		flush_rewrite_rules();
	}
);
