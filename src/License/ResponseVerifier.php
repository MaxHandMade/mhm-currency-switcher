<?php
/**
 * Verifier for HMAC-SHA256 signed license-server responses.
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
 * Verifies HMAC-SHA256 signed responses from mhm-license-server v1.9.0+.
 *
 * MUST stay in lockstep with the server's `ResponseSigner` canonicalization:
 * recursive ksort + wp_json_encode(JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).
 * If the server changes its canonical form, this client cannot verify and
 * legitimate responses will look tampered.
 *
 * @since 0.5.0
 */
final class ResponseVerifier {

	public const SIGNATURE_FIELD = 'signature';

	/**
	 * Shared HMAC secret (resolved via ClientSecrets).
	 *
	 * @var string
	 */
	private string $secret;

	/**
	 * Constructor.
	 *
	 * @param string $secret Shared HMAC secret.
	 */
	public function __construct( string $secret ) {
		$this->secret = $secret;
	}

	/**
	 * Verify the HMAC signature on a signed server response.
	 *
	 * @param array<string, mixed> $signed Decoded response body including `signature` key.
	 * @return bool True when the signature matches; false otherwise.
	 */
	public function verify( array $signed ): bool {
		if ( ! isset( $signed[ self::SIGNATURE_FIELD ] ) || ! is_string( $signed[ self::SIGNATURE_FIELD ] ) ) {
			return false;
		}

		$signature = $signed[ self::SIGNATURE_FIELD ];
		unset( $signed[ self::SIGNATURE_FIELD ] );

		$expected = hash_hmac( 'sha256', $this->canonicalize( $signed ), $this->secret );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Canonicalize an array so both sides produce the same HMAC input.
	 *
	 * @param array<string, mixed> $data Data to canonicalize.
	 * @return string Canonical JSON string.
	 */
	private function canonicalize( array $data ): string {
		$this->ksort_recursive( $data );
		return (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Recursively ksort an array (mirror of server-side ResponseSigner).
	 *
	 * @param array<mixed, mixed> $data Array reference to sort in-place.
	 * @return void
	 */
	private function ksort_recursive( array &$data ): void {
		foreach ( $data as &$value ) {
			if ( is_array( $value ) ) {
				$this->ksort_recursive( $value );
			}
		}
		unset( $value );
		ksort( $data );
	}
}
