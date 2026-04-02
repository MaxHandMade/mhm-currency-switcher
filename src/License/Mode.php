<?php
/**
 * License mode — feature gate checks.
 *
 * Provides static methods to check whether Pro features are
 * available based on the current license status.
 *
 * @package MhmCurrencySwitcher\License
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\License;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mode — Lite/Pro feature gates.
 *
 * @since 0.4.0
 */
final class Mode {

	/**
	 * Check if the plugin is running in Pro mode.
	 *
	 * @return bool True when a valid Pro license is active.
	 */
	public static function is_pro(): bool {
		return LicenseManager::instance()->is_active();
	}

	/**
	 * Check if the plugin is running in Lite mode.
	 *
	 * @return bool True when no Pro license is active.
	 */
	public static function is_lite(): bool {
		return ! self::is_pro();
	}

	/**
	 * Check if geolocation-based currency detection is available.
	 *
	 * @return bool True when Pro.
	 */
	public static function can_use_geolocation(): bool {
		return self::is_pro();
	}

	/**
	 * Check if fixed/manual prices per currency are available.
	 *
	 * @return bool True when Pro.
	 */
	public static function can_use_fixed_prices(): bool {
		return self::is_pro();
	}

	/**
	 * Check if payment method restrictions per currency are available.
	 *
	 * @return bool True when Pro.
	 */
	public static function can_use_payment_restrictions(): bool {
		return self::is_pro();
	}

	/**
	 * Check if automatic rate updates via cron are available.
	 *
	 * @return bool True when Pro.
	 */
	public static function can_use_auto_rate_update(): bool {
		return self::is_pro();
	}

	/**
	 * Check if multilingual currency names are available.
	 *
	 * @return bool True when Pro.
	 */
	public static function can_use_multilingual(): bool {
		return self::is_pro();
	}

	/**
	 * Check if REST API currency filter is available.
	 *
	 * @return bool True when Pro.
	 */
	public static function can_use_rest_api_filter(): bool {
		return self::is_pro();
	}

	/**
	 * Get the maximum number of extra currencies allowed.
	 *
	 * Lite: 2 currencies. Pro: unlimited.
	 *
	 * @return int Currency limit.
	 */
	public static function get_currency_limit(): int {
		return self::is_pro() ? PHP_INT_MAX : 2;
	}
}
