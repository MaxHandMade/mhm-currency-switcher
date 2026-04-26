<?php
/**
 * Plugin Name:       MHM Currency Switcher
 * Plugin URI:        https://maxhandmade.com/plugins/mhm-currency-switcher
 * Description:       Multi-currency support for WooCommerce with real-time exchange rates and seamless checkout integration.
 * Version:           0.6.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            MaxHandMade
 * Author URI:        https://maxhandmade.com
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
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
define( 'MHM_CS_VERSION', '0.6.3' );

/**
 * Plugin main file.
 *
 * @var string
 */
define( 'MHM_CS_FILE', __FILE__ );

/**
 * Plugin directory path.
 *
 * @var string
 */
define( 'MHM_CS_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL.
 *
 * @var string
 */
define( 'MHM_CS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename.
 *
 * @var string
 */
define( 'MHM_CS_BASENAME', plugin_basename( __FILE__ ) );

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
		// Default supported currencies.
		if ( false === get_option( 'mhm_currency_switcher_currencies' ) ) {
			update_option(
				'mhm_currency_switcher_currencies',
				array(
					'USD',
					'EUR',
					'GBP',
					'TRY',
				)
			);
		}

		// Default settings.
		if ( false === get_option( 'mhm_currency_switcher_settings' ) ) {
			update_option(
				'mhm_currency_switcher_settings',
				array(
					'provider'       => 'exchangerate',
					'cache_duration' => 3600,
					'auto_detect'    => true,
					'round_prices'   => true,
				)
			);
		}
	}
);

/**
 * Deactivation hook: clean up.
 */
register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( 'mhm_cs_update_rates' );
		flush_rewrite_rules();
	}
);
