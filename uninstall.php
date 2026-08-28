<?php
/**
 * Uninstall routine for MHM Currency Switcher.
 *
 * Runs when the plugin is deleted from the WordPress admin. What it removes
 * depends on the shop owner's choice, stored in `mhmcs_settings['delete_all_data']`
 * (default false / absent): caches, transients, scheduled events and secrets
 * from removed controls are always cleared, but the currency configuration,
 * the sync timestamp and every order's recorded currency and exchange rate
 * survive UNLESS that switch is on — because deleting them destroys the only
 * basis a shop has for its multi-currency sales history. When the switch is
 * on, everything the plugin created under its current option and meta names
 * is removed.
 *
 * @package MhmCurrencySwitcher
 */

declare(strict_types=1);

// Exit if not called by WordPress during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/*
 * Read FIRST. Every branch below deletes something, and one of the things the
 * purge branch deletes is the option this decision lives in.
 *
 * Default false, NOT array(). WordPress runs this file whenever the plugin
 * is deleted, whether or not it was ever activated -- so a site that
 * installed the plugin and never activated it (or activated it but never
 * saved settings) has no mhmcs_settings row at all. array() would make that
 * case indistinguishable from "a row exists and is empty" to the
 * is_array( $mhmcs_settings ) guard the keep branch uses below, so the keep
 * branch would call update_option() and manufacture an autoloaded row that
 * was never there. Worse: mhm-currency-switcher.php's activation hook only
 * seeds defaults when `false === get_option( 'mhmcs_settings' )`, and an
 * empty array is not false -- so a LATER genuine install would see the
 * manufactured row, skip its own default-seeding, and come up with
 * auto_detect silently off (src/Plugin.php reads it as
 * `! empty( $settings['auto_detect'] )`).
 */
$mhmcs_settings = get_option( 'mhmcs_settings', false );
$mhmcs_purge    = is_array( $mhmcs_settings ) && ! empty( $mhmcs_settings['delete_all_data'] );

/*
 * Mirrors RestAPI::LEGACY_SETTING_KEYS. uninstall.php runs without the
 * autoloader, so the list cannot be imported — it is duplicated here and
 * tests/Unit/Compliance/UninstallKeyListParityTest.php pins the two together.
 *
 * 🔴 `provider_api_key` is a user-supplied secret. Its purge loop lives inside
 * save_settings(), so a site that has not saved settings since that control was
 * removed still carries the key in this row. The keep branch below therefore
 * strips these keys instead of preserving the row verbatim: a credential does
 * not survive an uninstall, whatever the switch says.
 */
$mhmcs_legacy_setting_keys = array(
	'provider',
	'provider_api_key',
	'cache_duration',
	'round_prices',
	'multilingual_mapping',
	'payment_restrictions',
);

// ─── Always, in both branches ────────────────────────────────────────

// Runtime observations, not configuration.
delete_option( 'mhmcs_cache_compat_anomaly' );
delete_option( 'mhmcs_cache_compat_fragments' );

/*
 * The per-user snooze meta pinned to those two options -- mirrors
 * CacheCompatDiagnostic::SNOOZE_META / SNOOZE_META_FRAGMENTS. uninstall.php
 * runs without the autoloader and cannot import the class, so the literals
 * are duplicated here.
 *
 * Unconditional for the same reason the two options above are: this is a
 * runtime observation (which anomaly a user last dismissed), not shop
 * configuration a "keep my data" choice is meant to protect. Left in the
 * keep branch it would be an orphaned row nothing ever reads again, since
 * the option it is keyed against is already gone by the time either branch
 * below runs.
 */
delete_metadata( 'user', 0, 'mhmcs_snooze_cache_anomaly', '', true );
delete_metadata( 'user', 0, 'mhmcs_snooze_cache_fragments', '', true );

/*
 * The migration bookkeeping. Both flags were stamped 'done' by one-time
 * cleanup routines -- a pre-1.0.0 licence-data sweep and a pre-0.3.0
 * option-name carry -- that ran while the plugin had no installed base to
 * speak of and were removed in 2.1.0 for exactly that reason. The two flags
 * are the only trace either one left behind on the handful of sites that
 * ran them before removal, and deleting them here is now the only cleanup
 * they will ever get: nothing writes these option names any more, so no
 * other code path will ever read or clear them.
 */
delete_option( 'mhmcs_legacy_license_cleanup' );
delete_option( 'mhmcs_legacy_option_migration' );

// Scheduled events.
wp_clear_scheduled_hook( 'mhmcs_update_rates' );

/*
 * Rate-cache and rate-limit transients.
 *
 * 🔴 The SQL below is NOT sufficient on its own, and the comment that used to
 * stand here said it was. Core stores transients in the object cache, not in
 * wp_options, whenever a persistent one is installed:
 *
 *     if ( wp_using_ext_object_cache() || wp_installing() ) {
 *         $result = wp_cache_delete( $transient, 'transient' );
 *     } else { ... delete_option( '_transient_' . $transient ) ... }
 *                                        -- core, delete_transient()
 *
 * So on a Redis or Memcached shop these rows do not exist and the DELETE
 * removes nothing. The rate cache IS enumerable — one key per base currency —
 * so it goes through delete_transient(), which picks the right backend by
 * itself. Rate-limit buckets are keyed per visitor address, cannot be
 * enumerated in either backend, and expire in seconds; the SQL is what
 * catches those, plus any historical base a currency list no longer names.
 */
$mhmcs_rate_bases = function_exists( 'get_woocommerce_currencies' )
	? array_keys( get_woocommerce_currencies() )
	: array();

foreach ( $mhmcs_rate_bases as $mhmcs_rate_base ) {
	delete_transient( 'mhmcs_rates_' . strtoupper( $mhmcs_rate_base ) );
}

/*
 * Kept for the non-object-cache case, and for rows the loop above cannot name.
 * DirectQuery/NoCaching stand because there is no options API that deletes by
 * prefix, and after this runs the plugin is gone: there is no read path left
 * whose cache could serve a stale row.
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no options API deletes by prefix; see the block comment above for why delete_transient() handles what CAN be named.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_mhmcs_rates_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mhmcs_rates_' ) . '%',
		$wpdb->esc_like( '_transient_mhmcs_rl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mhmcs_rl_' ) . '%'
	)
);

if ( ! $mhmcs_purge ) {
	/*
	 * KEEP BRANCH — the default.
	 *
	 * Settings, currency configuration, the sync timestamp and every order's
	 * recorded currency stay. The only thing removed is what must never
	 * survive: the secrets of controls that no longer exist.
	 */
	if ( is_array( $mhmcs_settings ) ) {
		update_option(
			'mhmcs_settings',
			array_diff_key( $mhmcs_settings, array_flip( $mhmcs_legacy_setting_keys ) )
		);
	}

	return;
}

// ─── PURGE BRANCH — the shop owner asked for everything ──────────────

delete_option( 'mhmcs_currencies' );
delete_option( 'mhmcs_settings' );
delete_option( 'mhmcs_rates_last_sync' );

// Post and order meta.
$mhmcs_meta_keys = array(
	'_mhmcs_currency_code',
	'_mhmcs_exchange_rate',
	'_mhmcs_base_currency',
	'_mhmcs_fixed_prices',
);

foreach ( $mhmcs_meta_keys as $mhmcs_meta_key ) {
	delete_post_meta_by_key( $mhmcs_meta_key );
}

// HPOS order meta lives in its own table; delete_post_meta_by_key() does not
// reach it.
$mhmcs_hpos_table = $wpdb->prefix . 'wc_orders_meta';

// esc_like: the prefix contains `_`, which LIKE reads as a single-character
// wildcard. The `===` below saves it today, but the risk runs the wrong way —
// a different table matching the pattern would be returned first, the guard
// would not match, and HPOS order meta would be silently left behind in the
// one branch whose entire promise is that everything is gone.
//
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES has no options API equivalent and reads no cacheable row.
$mhmcs_hpos_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $mhmcs_hpos_table ) ) );

if ( $mhmcs_hpos_table_exists === $mhmcs_hpos_table ) {
	foreach ( $mhmcs_meta_keys as $mhmcs_meta_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off uninstall cleanup; table name cannot be prepared, and this is a single bounded run over 4 fixed keys, not a per-request query.
		$wpdb->delete( $mhmcs_hpos_table, array( 'meta_key' => $mhmcs_meta_key ), array( '%s' ) );
	}
}
