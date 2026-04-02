<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package MhmCurrencySwitcher\Tests
 */

declare(strict_types=1);

// Load Composer autoloader.
$mhm_cs_autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $mhm_cs_autoloader ) ) {
	require_once $mhm_cs_autoloader;
}

/*
 * Define ABSPATH when running outside WordPress so that source files
 * with the `defined( 'ABSPATH' ) || exit;` guard do not terminate
 * the process during autoloading.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

/*
 * Determine if we should load the WordPress test environment.
 * For unit tests that don't need WP, we skip this entirely.
 */
$mhm_cs_wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( is_dir( $mhm_cs_wp_tests_dir ) ) {

	// Give access to tests_add_filter() function.
	require_once $mhm_cs_wp_tests_dir . '/includes/functions.php';

	/**
	 * Manually load WooCommerce and the plugin for integration tests.
	 */
	tests_add_filter(
		'muplugins_loaded',
		static function (): void {
			// Load WooCommerce if available.
			$wc_path = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
			if ( file_exists( $wc_path ) ) {
				require $wc_path;
			}

			// Load our plugin.
			require dirname( __DIR__ ) . '/mhm-currency-switcher.php';
		}
	);

	// Start up the WP testing environment.
	require $mhm_cs_wp_tests_dir . '/includes/bootstrap.php';
}
