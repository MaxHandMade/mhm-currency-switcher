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

/*
 * Forward the PHPUnit Polyfills location to WordPress's bootstrap.
 *
 * The WP test library depends on yoast/phpunit-polyfills and looks for this
 * constant; without it, some environments fail during bootstrap with a bare
 * "please run composer install" rather than anything actionable. Pattern
 * taken from the sibling mhm-rentiva plugin, whose suite has run this way
 * for a long time.
 */
$mhmcs_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );

if ( false !== $mhmcs_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $mhmcs_polyfills_path );
}

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

/*
 * Create WooCommerce's own database tables.
 *
 * The WordPress test library installs WordPress, not the plugins under
 * test, so WooCommerce's schema never exists unless we ask for it. Without
 * this, every WC data-store call fails with "table doesn't exist" and the
 * suite drowns in database errors before a single assertion runs.
 */
tests_add_filter(
	'setup_theme',
	static function (): void {
		if ( ! class_exists( 'WC_Install' ) ) {
			fwrite( STDERR, "WooCommerce loaded but WC_Install is missing; cannot create its tables.\n" );
			exit( 1 );
		}

		\WC_Install::install();

		// Reload role definitions that WC_Install just registered.
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Documented WooCommerce test-suite pattern.
		wp_roles();
	}
);

require $mhmcs_tests_dir . '/includes/bootstrap.php';
