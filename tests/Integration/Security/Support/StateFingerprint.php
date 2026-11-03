<?php
/**
 * "Nothing changed", as the security gates measure it.
 *
 * @package MhmCurrencySwitcher\Tests\Integration\Security
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration\Security\Support;

/**
 * Hash of every piece of state this plugin owns.
 *
 * Covered: every `mhmcs*` option (settings, currencies, rate cache, the
 * cache-compat verdicts) including `mhmcs*` transients stored as options,
 * every `_mhmcs*` / `mhmcs*` post meta (fixed prices, order currency) and
 * every `mhmcs*` user meta (notice snoozes).
 *
 * Deliberately NOT covered: the `/convert` rate-limit counters
 * (`mhmcs_rl_*`). Counting a refused request is bookkeeping, not state a
 * refused request may not touch.
 */
trait StateFingerprint {

	/**
	 * Fingerprint of the plugin's stored state.
	 *
	 * @return string
	 */
	private function state_fingerprint(): string {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- test-only read of the raw tables.
		$options = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options}
			 WHERE ( option_name LIKE 'mhmcs%'
			      OR option_name LIKE '\\_transient\\_mhmcs%'
			      OR option_name LIKE '\\_site\\_transient\\_mhmcs%' )
			   AND option_name NOT LIKE '\\_transient\\_mhmcs\\_rl\\_%'
			   AND option_name NOT LIKE '\\_transient\\_timeout\\_mhmcs\\_rl\\_%'
			 ORDER BY option_name",
			ARRAY_N
		);
		$post_meta = $wpdb->get_results(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key LIKE '\\_mhmcs%' OR meta_key LIKE 'mhmcs%'
			 ORDER BY meta_id",
			ARRAY_N
		);
		$user_meta = $wpdb->get_results(
			"SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta}
			 WHERE meta_key LIKE 'mhmcs%' OR meta_key LIKE '\\_mhmcs%'
			 ORDER BY umeta_id",
			ARRAY_N
		);
		// phpcs:enable

		return 'options:' . md5( serialize( $options ) )
			. "\npostmeta:" . md5( serialize( $post_meta ) )
			. "\nusermeta:" . md5( serialize( $user_meta ) );
	}
}
