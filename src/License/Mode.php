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
		return self::feature_granted( 'geolocation' );
	}

	/**
	 * Check if fixed/manual prices per currency are available.
	 *
	 * @return bool True when Pro.
	 */
	public static function can_use_fixed_prices(): bool {
		return self::feature_granted( 'fixed_pricing' );
	}

	/**
	 * Check if payment method restrictions per currency are available.
	 *
	 * @return bool True when Pro.
	 */
	public static function can_use_payment_restrictions(): bool {
		return self::feature_granted( 'payment_restrictions' );
	}

	/**
	 * Check if automatic rate updates via cron are available.
	 *
	 * @return bool True when Pro.
	 */
	public static function can_use_auto_rate_update(): bool {
		return self::feature_granted( 'auto_rate_update' );
	}

	/**
	 * Check if multilingual currency names are available.
	 *
	 * @return bool True when Pro.
	 */
	public static function can_use_multilingual(): bool {
		return self::feature_granted( 'multilingual' );
	}

	/**
	 * Check if REST API currency filter is available.
	 *
	 * @return bool True when Pro.
	 */
	public static function can_use_rest_api_filter(): bool {
		return self::feature_granted( 'rest_api_filter' );
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

	/**
	 * Pro feature gate (v0.6.0+) that requires a valid RSA-signed feature
	 * token from `mhm-license-server` v1.10.0+. Strict enforcement — there
	 * is no legacy `is_pro()`-only fallback any more.
	 *
	 * The v0.5.x design left a hole: when `FEATURE_TOKEN_KEY` was unset on
	 * the customer's wp-config (the zero-config deploy default), the gate
	 * fell through to `is_pro()`, which a cracked binary could trivially
	 * patch. v0.6.0 closes that by requiring a token whose RSA signature
	 * verifies against the embedded public key — public keys cannot mint,
	 * so source-edit attacks cannot forge a token even with a real license.
	 *
	 * Defense-in-depth boundary: this single private method is the only
	 * gate. A `Mode::feature_granted() { return true; }` patch defeats every
	 * `can_use_*()` call. Closing that requires inline RSA verify per gate
	 * (DRY trade-off, deferred to a future release).
	 *
	 * @param string $feature Feature flag name (e.g. `fixed_pricing`).
	 * @return bool True when the gate is open.
	 */
	private static function feature_granted( string $feature ): bool {
		// Hard gate: license must be locally active.
		if ( ! self::is_pro() ) {
			return false;
		}

		// Developer escape hatch: when MHM_CS_DEV_PRO is on, all gates are
		// open. This mirrors LicenseManager::is_active()'s dev override and
		// is no new attack surface — a cracked binary that can define
		// MHM_CS_DEV_PRO is the same attacker that can patch this method.
		if ( defined( 'MHM_CS_DEV_PRO' ) && MHM_CS_DEV_PRO ) {
			return true;
		}

		$license_manager = LicenseManager::instance();
		$token           = $license_manager->get_feature_token();
		if ( '' === $token ) {
			// No token in storage — either talking to a legacy server, or a
			// cracked binary patched is_active(). Fail closed.
			return false;
		}

		$verifier = new FeatureTokenVerifier();
		if ( ! $verifier->verify( $token, $license_manager->get_site_hash() ) ) {
			return false;
		}

		return $verifier->has_feature( $token, $feature );
	}
}
