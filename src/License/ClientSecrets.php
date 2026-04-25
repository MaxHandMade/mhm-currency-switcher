<?php
/**
 * Resolver for the v0.5.0+ shared secrets that talk to mhm-license-server v1.9.0+.
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
 * Resolves the v0.5.0+ shared secrets used to talk to mhm-license-server.
 *
 *   - RESPONSE_HMAC_SECRET — verifies the HMAC on every successful
 *     activate/validate response.
 *   - PING_SECRET          — answers the X-MHM-Challenge during reverse site
 *     validation (optional).
 *
 * v0.6.0 — `FEATURE_TOKEN_KEY` removed: feature_token signing migrated to
 * RSA, so the client no longer carries a shared secret for that path. The
 * embedded {@see LicenseServerPublicKey} is enough to verify tokens.
 *
 * Each value MUST match the corresponding wp-config constant on the license
 * server (`MHM_LICENSE_SERVER_RESPONSE_HMAC_SECRET`, etc.). Operators define
 * them in their own `wp-config.php`; in CI/dev we also accept environment
 * variables (`getenv()`).
 *
 * The plugin source is public, so values are never hardcoded here — they
 * would be the first thing an attacker greps for.
 *
 * @since 0.5.0
 */
final class ClientSecrets {

	public const CONST_RESPONSE_HMAC = 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET';
	public const CONST_PING          = 'MHM_CS_LICENSE_PING_SECRET';

	/**
	 * Get the HMAC secret used to verify signed license-server responses.
	 *
	 * @return string Shared secret, or empty string when not configured.
	 */
	public static function get_response_hmac_secret(): string {
		return self::resolve( self::CONST_RESPONSE_HMAC );
	}

	/**
	 * Get the secret used to answer the server's reverse-validation challenge.
	 *
	 * @return string Shared secret, or empty string when not configured.
	 */
	public static function get_ping_secret(): string {
		return self::resolve( self::CONST_PING );
	}

	/**
	 * Resolve a secret from wp-config constant or environment variable.
	 *
	 * @param string $constant Constant name to look up.
	 * @return string Trimmed value, or empty string when unset.
	 */
	private static function resolve( string $constant ): string {
		if ( defined( $constant ) ) {
			return trim( (string) constant( $constant ) );
		}

		$env = getenv( $constant );
		if ( false === $env ) {
			return '';
		}

		return trim( (string) $env );
	}
}
