<?php
/**
 * Tests for License\ResponseVerifier.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\License
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\License;

use MhmCurrencySwitcher\License\ResponseVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Mirror of mhm-license-server v1.9.0 ResponseSigner — verify side only.
 *
 * Both sides MUST produce the same canonical JSON for the HMAC to match;
 * if these tests start failing it usually means the server's canonicalization
 * (recursive ksort + JSON_UNESCAPED_SLASHES|UNICODE) drifted.
 *
 * @covers \MhmCurrencySwitcher\License\ResponseVerifier
 */
class ResponseVerifierTest extends TestCase {

	private const SECRET = 'test-response-hmac';

	public function test_verifies_response_signed_by_server_style_canonicalization(): void {
		$payload              = array(
			'status'     => 'active',
			'plan'       => 'pro',
			'expires_at' => 1234567890,
		);
		$canonical            = $this->server_style_canonicalize( $payload );
		$payload['signature'] = hash_hmac( 'sha256', $canonical, self::SECRET );

		$verifier = new ResponseVerifier( self::SECRET );
		$this->assertTrue( $verifier->verify( $payload ) );
	}

	public function test_fails_when_payload_field_tampered(): void {
		$payload              = array(
			'status' => 'active',
			'plan'   => 'pro',
		);
		$canonical            = $this->server_style_canonicalize( $payload );
		$payload['signature'] = hash_hmac( 'sha256', $canonical, self::SECRET );

		$payload['plan'] = 'free';

		$verifier = new ResponseVerifier( self::SECRET );
		$this->assertFalse( $verifier->verify( $payload ) );
	}

	public function test_fails_when_signature_field_missing(): void {
		$verifier = new ResponseVerifier( self::SECRET );
		$this->assertFalse( $verifier->verify( array( 'status' => 'active' ) ) );
		$this->assertFalse( $verifier->verify( array() ) );
	}

	public function test_fails_with_different_secret(): void {
		$payload              = array( 'status' => 'active' );
		$canonical            = $this->server_style_canonicalize( $payload );
		$payload['signature'] = hash_hmac( 'sha256', $canonical, 'server-secret' );

		$verifier = new ResponseVerifier( 'client-secret-mismatch' );
		$this->assertFalse( $verifier->verify( $payload ) );
	}

	public function test_canonicalization_handles_nested_arrays_with_any_key_order(): void {
		$payload              = array(
			'status'   => 'active',
			'features' => array(
				'fixed_pricing' => true,
				'geolocation'   => true,
			),
		);
		$canonical            = $this->server_style_canonicalize( $payload );
		$payload['signature'] = hash_hmac( 'sha256', $canonical, self::SECRET );

		// Reorder nested keys — still valid (recursive ksort).
		$payload['features'] = array(
			'geolocation'   => true,
			'fixed_pricing' => true,
		);

		$verifier = new ResponseVerifier( self::SECRET );
		$this->assertTrue( $verifier->verify( $payload ) );
	}

	public function test_fails_when_extra_field_added_after_signing(): void {
		$payload              = array( 'status' => 'active' );
		$canonical            = $this->server_style_canonicalize( $payload );
		$payload['signature'] = hash_hmac( 'sha256', $canonical, self::SECRET );

		$payload['injected'] = 'malicious';

		$verifier = new ResponseVerifier( self::SECRET );
		$this->assertFalse( $verifier->verify( $payload ) );
	}

	/**
	 * Reproduces server-side ResponseSigner::canonicalize() exactly.
	 * If you change one, change the other.
	 *
	 * @param array<string, mixed> $data Data to canonicalize.
	 * @return string Canonical JSON.
	 */
	private function server_style_canonicalize( array $data ): string {
		$this->ksort_recursive( $data );
		return (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * @param array<mixed, mixed> $data
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
