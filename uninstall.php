<?php
/**
 * Uninstall routine for MHM Currency Switcher.
 *
 * Runs when the plugin is deleted from the WordPress admin. Removes every
 * option, transient, scheduled event, and post/order meta key the plugin
 * creates — including the pre-1.0.0 names, so a site that upgraded from
 * 0.7.x and then deletes the plugin does not keep orphaned rows.
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
delete_option( 'mhmcs_legacy_license_cleanup' );

// Pre-1.0.0 option names. The licence option held the customer's licence
// key — it must not survive an uninstall.
delete_option( 'mhm_currency_switcher_currencies' );
delete_option( 'mhm_currency_switcher_settings' );
delete_option( 'mhm_currency_switcher_license' );

// Scheduled events (current name plus the pre-1.0.0 names, so upgraded
// sites do not leave an orphaned cron entry behind).
wp_clear_scheduled_hook( 'mhmcs_update_rates' );
wp_clear_scheduled_hook( 'mhm_cs_update_rates' );
wp_clear_scheduled_hook( 'mhm_cs_license_daily' );

// Rate-cache transients. RateProvider caches one transient per requested
// base currency ('mhmcs_rates_' . strtoupper( $base ), e.g.
// 'mhmcs_rates_USD'), so the exact set of keys cannot be enumerated ahead
// of time and delete_transient() cannot be used one call at a time. Delete
// by prefix instead, covering the current prefix and the pre-1.0.0
// 'mhm_cs_rates_' prefix.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall cleanup; transient keys are per-base-currency and cannot be enumerated or passed through delete_transient().
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_mhmcs_rates_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mhmcs_rates_' ) . '%',
		$wpdb->esc_like( '_transient_mhm_cs_rates_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mhm_cs_rates_' ) . '%'
	)
);

// Fixed-name transients.
delete_transient( 'mhm_cs_license_visit_throttle' );

// Post and order meta (current names plus the pre-1.0.0 names).
$mhmcs_meta_keys = array(
	'_mhmcs_currency_code',
	'_mhmcs_exchange_rate',
	'_mhmcs_base_currency',
	'_mhmcs_fixed_prices',
	'_mhm_cs_currency_code',
	'_mhm_cs_exchange_rate',
	'_mhm_cs_base_currency',
	'_mhm_cs_fixed_prices',
);

foreach ( $mhmcs_meta_keys as $mhmcs_meta_key ) {
	delete_post_meta_by_key( $mhmcs_meta_key );
}

// HPOS order meta lives in its own table when High-Performance Order
// Storage is active; delete_post_meta_by_key() does not reach it.
$mhmcs_hpos_table = $wpdb->prefix . 'wc_orders_meta';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall cleanup, no cache to invalidate.
$mhmcs_hpos_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mhmcs_hpos_table ) );

if ( $mhmcs_hpos_table_exists === $mhmcs_hpos_table ) {
	foreach ( $mhmcs_meta_keys as $mhmcs_meta_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off uninstall cleanup; table name cannot be prepared, and this is a single bounded run over 8 fixed keys, not a per-request query.
		$wpdb->delete( $mhmcs_hpos_table, array( 'meta_key' => $mhmcs_meta_key ), array( '%s' ) );
	}
}
