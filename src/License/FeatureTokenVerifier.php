<?php
/**
 * Verifier for server-issued feature tokens used by Mode::can_use_*().
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
 * Verifies feature tokens issued by mhm-license-server v1.9.0+.
 *
 * Wire format: `{base64(json_payload)}.{hmac_hex}`. The payload is plaintext-
 * readable on the client (we only verify the HMAC, not decrypt). Tamper
 * resistance is the goal, not confidentiality.
 *
 * Used by `Mode::can_use_*()` to gate Pro features. A `return true;` patch on
 * `LicenseManager::is_active()` no longer unlocks anything because the gate
 * also requires `features['<key>'] === true` from a valid, non-expired,
 * server-signed token.
 *
 * @since 0.5.0
 */
final class FeatureTokenVerifier {

	/**
	 * Shared HMAC key (resolved via ClientSecrets).
	 *
	 * @var string
	 */
	private string $secret;

	/**
	 * Constructor.
	 *
	 * @param string $secret Shared HMAC key.
	 */
	public function __construct( string $secret ) {
		$this->secret = $secret;
	}

	/**
	 * Verify a feature token and return its payload when valid.
	 *
	 * @param string $token Token in `{base64_payload}.{hmac_hex}` format.
	 * @return array<string, mixed>|null Payload on success; null on tamper,
	 *                                   expiry, or malformed input.
	 */
	public function verify( string $token ): ?array {
		if ( '' === $token || substr_count( $token, '.' ) !== 1 ) {
			return null;
		}

		[ $payload_b64, $signature ] = explode( '.', $token, 2 );

		if ( '' === $payload_b64 || '' === $signature ) {
			return null;
		}

		$expected = hash_hmac( 'sha256', $payload_b64, $this->secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding HMAC-verified token payload; input already authenticated above.
		$json = base64_decode( $payload_b64, true );
		if ( false === $json ) {
			return null;
		}

		$payload = json_decode( $json, true );
		if ( ! is_array( $payload ) ) {
			return null;
		}

		$expires_at = isset( $payload['expires_at'] ) ? (int) $payload['expires_at'] : 0;
		if ( $expires_at <= time() ) {
			return null;
		}

		return $payload;
	}

	/**
	 * Check whether a verified payload grants the named feature.
	 *
	 * @param array<string, mixed>|null $payload Output of verify().
	 * @param string                    $feature Feature name (e.g. `fixed_pricing`).
	 * @return bool True when the feature flag is present and true.
	 */
	public function has_feature( ?array $payload, string $feature ): bool {
		if ( null === $payload || ! isset( $payload['features'] ) || ! is_array( $payload['features'] ) ) {
			return false;
		}

		return ( $payload['features'][ $feature ] ?? false ) === true;
	}
}
