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
