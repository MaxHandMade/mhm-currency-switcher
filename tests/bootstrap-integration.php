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

		/*
		 * Order storage mode, chosen by the environment.
		 *
		 * The plugin DECLARES HPOS compatibility and writes the currency and
		 * the applied rate onto every order — the only record of what a
		 * multi-currency sale was charged in. Until this switch existed the
		 * whole suite ran on whatever the default was (the classic post
		 * table), so the declaration was measured by nothing.
		 *
		 * 🔴 Set BEFORE WooCommerce is required, and before WC_Install::install()
		 * runs at setup_theme. WooCommerce reads its feature flags as it boots
		 * and creates its tables during install; flipping either option after
		 * those points leaves the feature off and the tables missing, and the
		 * run then passes over the classic tables while claiming HPOS.
		 * OrderStorageModeTest fails the run if that happens, but the ordering
		 * here is what stops it happening.
		 */
		$mhmcs_hpos = in_array( strtolower( (string) getenv( 'MHMCS_HPOS' ) ), array( '1', 'yes', 'true', 'on' ), true );

		if ( $mhmcs_hpos ) {
			update_option( 'woocommerce_feature_custom_order_tables_enabled', 'yes' );
			update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
			// Sync writes every order to BOTH tables, which would let a defect
			// in the HPOS path hide behind a correct classic row.
			update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
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

		/*
		 * HPOS tables are NOT created by WC_Install::install(), and they must
		 * exist BEFORE it runs.
		 *
		 * Both halves were measured, not assumed. With the feature flag on and
		 * only install() run, WooCommerce routes orders to OrdersTableDataStore
		 * — the stack trace proves the flag took — and then every write dies
		 * with "Table 'wptests_wc_orders' doesn't exist"; the tables are owned
		 * by the DataSynchronizer, which creates them on demand. Creating them
		 * AFTER install() fixed the tests but left three database errors in the
		 * log, because install() itself counts orders by status and reads that
		 * table while it is still missing. A green run with database errors in
		 * its own output teaches whoever reads it next to skip errors.
		 *
		 * The guard is not decoration: if a future WooCommerce renames or moves
		 * this service, the tables silently stay missing and the run fails deep
		 * inside a data store instead of here, where the reason is legible.
		 */
		if ( in_array( strtolower( (string) getenv( 'MHMCS_HPOS' ) ), array( '1', 'yes', 'true', 'on' ), true ) ) {
			$mhmcs_sync = '\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer';

			if ( ! class_exists( $mhmcs_sync ) || ! function_exists( 'wc_get_container' ) ) {
				fwrite( STDERR, "MHMCS_HPOS was requested but WooCommerce's DataSynchronizer is not available.\n" );
				exit( 1 );
			}

			wc_get_container()->get( $mhmcs_sync )->create_database_tables();
		}

		\WC_Install::install();

		// Reload role definitions that WC_Install just registered.
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Documented WooCommerce test-suite pattern.
		wp_roles();
	}
);

require $mhmcs_tests_dir . '/includes/bootstrap.php';
