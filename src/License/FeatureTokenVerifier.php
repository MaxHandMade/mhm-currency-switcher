<?php
/**
 * Verifier for server-issued feature tokens used by Mode::can_use_*().
 *
 * @package MhmCurrencySwitcher\License
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\License;

use OpenSSLAsymmetricKey;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies feature tokens issued by mhm-license-server v1.10.0+.
 *
 * Wire format: `{base64url(canonical_payload)}.{base64url(rsa_signature)}`.
 * The signature is RSA-2048 + PKCS#1 v1.5 + SHA-256, produced by the
 * server's private key and verified here against the public key embedded
 * in {@see LicenseServerPublicKey}.
 *
 * v0.6.0 — Migrated from HMAC. The previous design required clients to
 * carry a copy of the server's signing secret (`FEATURE_TOKEN_KEY`) which
 * left source-edit attacks unmitigated whenever the customer wp-config
 * skipped the optional secret. Asymmetric crypto closes that hole without
 * giving up the zero-config UX: clients ship only the public key, which
 * can verify but cannot mint.
 *
 * Used by `Mode::can_use_*()` to gate Pro features. A `return true;` patch
 * on `LicenseManager::is_active()` no longer unlocks anything because the
 * gate also requires `features['<key>'] === true` from a token that passes
 * RSA verify, matches the local site hash, and is unexpired.
 *
 * @since 0.5.0
 */
final class FeatureTokenVerifier {

	/**
	 * Public key used to verify feature token signatures.
	 *
	 * @var OpenSSLAsymmetricKey
	 */
	private OpenSSLAsymmetricKey $public_key;

	/**
	 * Constructor.
	 *
	 * @param OpenSSLAsymmetricKey|null $public_key Optional override for tests.
	 *                                              Defaults to the embedded
	 *                                              production public key.
	 */
	public function __construct( ?OpenSSLAsymmetricKey $public_key = null ) {
		$this->public_key = $public_key ?? LicenseServerPublicKey::resource();
	}

	/**
	 * Verify a feature token's signature, site binding and freshness.
	 *
	 * Returns true only when ALL of the following hold:
	 *  - Token has the expected `{payload}.{signature}` two-segment shape.
	 *  - Both segments base64url-decode without error.
	 *  - The signature verifies against the embedded public key.
	 *  - The decoded payload's `site_hash` matches `$expected_site_hash`.
	 *  - The decoded payload's `expires_at` is in the future.
	 *
	 * @param string $token              Wire-format feature token from server.
	 * @param string $expected_site_hash Local site hash to bind the token to.
	 * @return bool True when the token verifies and binds to this site.
	 */
	public function verify( string $token, string $expected_site_hash ): bool {
		if ( '' === $token || substr_count( $token, '.' ) !== 1 ) {
			return false;
		}

		[ $payload_segment, $signature_segment ] = explode( '.', $token, 2 );
		if ( '' === $payload_segment || '' === $signature_segment ) {
			return false;
		}

		$canonical = self::base64url_decode( $payload_segment );
		$signature = self::base64url_decode( $signature_segment );
		if ( '' === $canonical || '' === $signature ) {
			return false;
		}

		$verified = openssl_verify( $canonical, $signature, $this->public_key, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $verified ) {
			return false;
		}

		$payload = json_decode( $canonical, true );
		if ( ! is_array( $payload ) ) {
			return false;
		}

		if ( ( $payload['site_hash'] ?? '' ) !== $expected_site_hash ) {
			return false;
		}

		$expires_at = isset( $payload['expires_at'] ) ? (int) $payload['expires_at'] : 0;
		if ( $expires_at <= time() ) {
			return false;
		}

		return true;
	}

	/**
	 * Read a feature flag from a token's payload.
	 *
	 * Caller is responsible for having verified the token first via
	 * {@see self::verify()} — this method does NOT re-verify the signature
	 * (that would double the openssl_verify cost on every gate call). It
	 * simply decodes the payload segment and looks up the feature key.
	 *
	 * @param string $token        Wire-format feature token.
	 * @param string $feature_name Feature key (e.g. `fixed_pricing`).
	 * @return bool True when the token's features map grants the named feature.
	 */
	public function has_feature( string $token, string $feature_name ): bool {
		if ( '' === $token || substr_count( $token, '.' ) !== 1 ) {
			return false;
		}

		[ $payload_segment ] = explode( '.', $token, 2 );
		$canonical           = self::base64url_decode( $payload_segment );
		if ( '' === $canonical ) {
			return false;
		}

		$payload = json_decode( $canonical, true );
		if ( ! is_array( $payload ) || ! isset( $payload['features'] ) || ! is_array( $payload['features'] ) ) {
			return false;
		}

		return ( $payload['features'][ $feature_name ] ?? false ) === true;
	}

	/**
	 * Decode a base64url-encoded string (RFC 4648 §5).
	 *
	 * @param string $input Base64url input.
	 * @return string Decoded binary; empty string on failure.
	 */
	private static function base64url_decode( string $input ): string {
		$remainder = strlen( $input ) % 4;
		if ( 0 !== $remainder ) {
			$input .= str_repeat( '=', 4 - $remainder );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary RSA signature byte transport.
		$decoded = base64_decode( strtr( $input, '-_', '+/' ), true );
		return false === $decoded ? '' : $decoded;
	}
}
