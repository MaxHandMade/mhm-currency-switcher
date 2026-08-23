<?php
/**
 * The one answer to "did this option write actually land".
 *
 * @package MhmCurrencySwitcher\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OptionWriter — writes an option and reports whether the requested state is
 * the stored state.
 *
 * 🔴 This exists because `update_option()` answers `false` for two opposite
 * situations, and the plugin has more than one caller that needs to tell them
 * apart:
 *
 * - the write failed, and nothing was stored;
 * - the value handed in already equalled the stored one, so no row needed
 *   changing.
 *
 * Reading that flag as "did it persist" turns every idempotent save — a shop
 * owner pressing Save twice, or changing a display option while the currency
 * list stays as it was — into a reported failure. Reading it as "success"
 * hides a database that has stopped accepting writes. Neither reading is
 * available; the question has to be asked differently, and it is asked here
 * once rather than copied into each caller.
 *
 * @since 1.3.1
 */
final class OptionWriter {

	/**
	 * Write an option and report whether the requested state is now stored.
	 *
	 * WordPress updates its option cache only after a successful database
	 * write, so re-reading is a real check rather than an echo of what was
	 * just handed in: on a genuine failure the read returns the old value and
	 * the comparison fails, while an unchanged value short-circuits before the
	 * write and compares equal — which is the correct answer, because the
	 * requested state IS stored.
	 *
	 * @param string $key   Option name.
	 * @param mixed  $value Value to store.
	 * @return bool True when the option now holds $value.
	 */
	public static function write( string $key, $value ): bool {
		if ( update_option( $key, $value ) ) {
			return true;
		}

		return get_option( $key, null ) === $value;
	}
}
