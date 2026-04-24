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
	 * Pro feature gate (v0.5.0+) that consults the server-issued feature
	 * token (mhm-license-server v1.9.0+) instead of a single is_pro() flag.
	 *
	 * A `return true;` patch on `LicenseManager::is_active()` no longer
	 * unlocks anything because the gate also requires the feature flag to
	 * be present in a HMAC-verified, non-expired, server-signed token.
	 *
	 * Backward-compat: when `MHM_CS_LICENSE_FEATURE_TOKEN_KEY` is not
	 * configured (legacy deploy), we fall back to `is_pro()` so existing
	 * customers keep working until they roll out v0.5.0 with secrets.
	 *
	 * @param string $feature Feature flag name (e.g. `fixed_pricing`).
	 * @return bool True when the gate is open.
	 */
	private static function feature_granted( string $feature ): bool {
		// Hard gate: license must be locally active.
		if ( ! self::is_pro() ) {
			return false;
		}

		$secret = ClientSecrets::get_feature_token_key();

		// Legacy fallback: secret not configured → behave like pre-v0.5.0
		// (only basic license check, no token gating). Operators must define
		// MHM_CS_LICENSE_FEATURE_TOKEN_KEY in wp-config (matching their
		// license server's MHM_LICENSE_SERVER_FEATURE_TOKEN_KEY) to enable
		// the v0.5.0 hardening.
		if ( '' === $secret ) {
			return true;
		}

		$token = LicenseManager::instance()->get_feature_token();
		if ( '' === $token ) {
			// Phase C configured but no token in storage → either patched
			// is_active() or talking to a legacy server. Fail closed.
			return false;
		}

		$verifier = new FeatureTokenVerifier( $secret );
		$payload  = $verifier->verify( $token );

		return $verifier->has_feature( $payload, $feature );
	}
}
