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
 * on, everything the plugin created is removed, including the pre-1.0.0
 * option and meta names, so a site that upgraded from 0.7.x and then deletes
 * the plugin does not keep orphaned rows either.
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
 * `! empty( $settings['auto_detect'] )`). false is the same default already
 * used for the legacy row below, and for exactly this reason.
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
 * The migration bookkeeping. Deleted in both branches because the migrator is
 * idempotent: every write it makes is guarded by `false === get_option( … )`
 * (LegacyOptionMigrator.php:264, :280), so a reinstall that re-runs it over
 * data this uninstall kept changes nothing.
 */
delete_option( 'mhmcs_legacy_license_cleanup' );
delete_option( 'mhmcs_legacy_option_migration' );

// The pre-1.0.0 licence option held the customer's licence key.
delete_option( 'mhm_currency_switcher_license' );
delete_transient( 'mhm_cs_license_visit_throttle' );

// Scheduled events (current name plus the pre-1.0.0 names).
wp_clear_scheduled_hook( 'mhmcs_update_rates' );
wp_clear_scheduled_hook( 'mhm_cs_update_rates' );
wp_clear_scheduled_hook( 'mhm_cs_license_daily' );

/*
 * Rate-cache and rate-limit transients. Keyed per base currency and per visitor
 * address, so the exact set cannot be enumerated and delete_transient() cannot
 * be called one key at a time. These are caches in both branches: keeping a
 * stale rate cache for a plugin that is gone helps nobody.
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall cleanup; transient keys are per-base-currency and per-address and cannot be enumerated or passed through delete_transient().
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_mhmcs_rates_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mhmcs_rates_' ) . '%',
		$wpdb->esc_like( '_transient_mhm_cs_rates_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mhm_cs_rates_' ) . '%',
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

	/*
	 * A legacy settings row only exists on a site that installed this plugin
	 * and never loaded it — the migrator deletes it on the first
	 * `plugins_loaded`, with or without WooCommerce. Filtered rather than kept
	 * verbatim for the same reason as above.
	 */
	$mhmcs_legacy_settings = get_option( 'mhm_currency_switcher_settings', false );

	if ( is_array( $mhmcs_legacy_settings ) ) {
		update_option(
			'mhm_currency_switcher_settings',
			array_diff_key( $mhmcs_legacy_settings, array_flip( $mhmcs_legacy_setting_keys ) )
		);
	}

	return;
}

// ─── PURGE BRANCH — the shop owner asked for everything ──────────────

delete_option( 'mhmcs_currencies' );
delete_option( 'mhmcs_settings' );
delete_option( 'mhmcs_rates_last_sync' );

// Pre-1.0.0 option names.
delete_option( 'mhm_currency_switcher_currencies' );
delete_option( 'mhm_currency_switcher_settings' );

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

// HPOS order meta lives in its own table; delete_post_meta_by_key() does not
// reach it.
$mhmcs_hpos_table = $wpdb->prefix . 'wc_orders_meta';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall cleanup, no cache to invalidate.
$mhmcs_hpos_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mhmcs_hpos_table ) );

if ( $mhmcs_hpos_table_exists === $mhmcs_hpos_table ) {
	foreach ( $mhmcs_meta_keys as $mhmcs_meta_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off uninstall cleanup; table name cannot be prepared, and this is a single bounded run over 8 fixed keys, not a per-request query.
		$wpdb->delete( $mhmcs_hpos_table, array( 'meta_key' => $mhmcs_meta_key ), array( '%s' ) );
	}
}
