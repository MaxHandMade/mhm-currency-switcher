<?php
/**
 * Tests for License\FeatureTokenVerifier.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\License
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\License;

use MhmCurrencySwitcher\License\FeatureTokenVerifier;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.0 — Mirror of mhm-license-server v1.10.0 FeatureTokenIssuer (verify
 * side). Tests inject the fixture public key via constructor DI so they
 * exercise real RSA verification rather than a mocked path.
 *
 * @covers \MhmCurrencySwitcher\License\FeatureTokenVerifier
 */
class FeatureTokenVerifierTest extends TestCase {

	private const SITE_HASH = 'site-hash-fixture';

	/** @var OpenSSLAsymmetricKey */
	private $public_key;

	/** @var OpenSSLAsymmetricKey */
	private $private_key;

	protected function setUp(): void {
		parent::setUp();

		$public_pem  = (string) file_get_contents( __DIR__ . '/../../fixtures/test-rsa-public.pem' );
		$private_pem = (string) file_get_contents( __DIR__ . '/../../fixtures/test-rsa-private.pem' );

		$public = openssl_pkey_get_public( $public_pem );
		$this->assertNotFalse( $public, 'Test fixture public key failed to parse' );
		$this->public_key = $public;

		$private = openssl_pkey_get_private( $private_pem );
		$this->assertNotFalse( $private, 'Test fixture private key failed to parse' );
		$this->private_key = $private;
	}

	public function test_verify_accepts_valid_rsa_token(): void {
		$token = $this->build_token(
			array(
				'features' => array( 'fixed_pricing' => true ),
			)
		);

		$verifier = new FeatureTokenVerifier( $this->public_key );
		$this->assertTrue( $verifier->verify( $token, self::SITE_HASH ) );
	}

	public function test_verify_rejects_token_signed_with_different_key_pair(): void {
		$foreign = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		$this->assertNotFalse( $foreign, 'Foreign key generation failed' );

		$canonical = $this->canonicalize(
			$this->default_payload( array( 'features' => array( 'fixed_pricing' => true ) ) )
		);

		$signature = '';
		openssl_sign( $canonical, $signature, $foreign, OPENSSL_ALGO_SHA256 );

		$forged_token = self::base64url_encode( $canonical ) . '.' . self::base64url_encode( $signature );

		$verifier = new FeatureTokenVerifier( $this->public_key );
		$this->assertFalse( $verifier->verify( $forged_token, self::SITE_HASH ) );
	}

	public function test_verify_rejects_tampered_signature_byte(): void {
		$token = $this->build_token();

		[ $payload_segment, $signature_segment ] = explode( '.', $token, 2 );
		$sig_bytes      = self::base64url_decode( $signature_segment );
		$sig_bytes[0]   = chr( ord( $sig_bytes[0] ) ^ 0x01 );
		$tampered       = $payload_segment . '.' . self::base64url_encode( $sig_bytes );

		$verifier = new FeatureTokenVerifier( $this->public_key );
		$this->assertFalse( $verifier->verify( $tampered, self::SITE_HASH ) );
	}

	public function test_verify_rejects_tampered_payload(): void {
		$token = $this->build_token();

		[ , $signature_segment ] = explode( '.', $token, 2 );

		$tampered_canonical = $this->canonicalize(
			$this->default_payload(
				array(
					'features'         => array( 'fixed_pricing' => true ),
					'site_hash'        => self::SITE_HASH,
					'license_key_hash' => 'evil-hash',
				)
			)
		);

		$tampered = self::base64url_encode( $tampered_canonical ) . '.' . $signature_segment;

		$verifier = new FeatureTokenVerifier( $this->public_key );
		$this->assertFalse( $verifier->verify( $tampered, self::SITE_HASH ) );
	}

	public function test_verify_rejects_expired_token(): void {
		$token = $this->build_token(
			array(
				'expires_at' => time() - 60,
				'issued_at'  => time() - 90000,
			)
		);

		$verifier = new FeatureTokenVerifier( $this->public_key );
		$this->assertFalse( $verifier->verify( $token, self::SITE_HASH ) );
	}

	public function test_verify_rejects_mismatched_site_hash(): void {
		$token = $this->build_token();

		$verifier = new FeatureTokenVerifier( $this->public_key );
		$this->assertFalse( $verifier->verify( $token, 'totally-different-site-hash' ) );
	}

	public function test_verify_rejects_malformed_tokens(): void {
		$verifier = new FeatureTokenVerifier( $this->public_key );

		$this->assertFalse( $verifier->verify( '', self::SITE_HASH ) );
		$this->assertFalse( $verifier->verify( 'no-dot', self::SITE_HASH ) );
		$this->assertFalse( $verifier->verify( 'a.b.c.d', self::SITE_HASH ) );
		$this->assertFalse( $verifier->verify( '!!!.!!!', self::SITE_HASH ) );
		$this->assertFalse( $verifier->verify( '.signature-only', self::SITE_HASH ) );
		$this->assertFalse( $verifier->verify( 'payload-only.', self::SITE_HASH ) );
	}

	public function test_has_feature_reads_feature_flag(): void {
		$token = $this->build_token(
			array(
				'features' => array(
					'fixed_pricing'        => true,
					'geolocation'          => true,
					'payment_restrictions' => false,
				),
			)
		);

		$verifier = new FeatureTokenVerifier( $this->public_key );

		$this->assertTrue( $verifier->has_feature( $token, 'fixed_pricing' ) );
		$this->assertTrue( $verifier->has_feature( $token, 'geolocation' ) );
		$this->assertFalse( $verifier->has_feature( $token, 'payment_restrictions' ) );
		$this->assertFalse( $verifier->has_feature( $token, 'nonexistent_feature' ) );
	}

	public function test_has_feature_returns_false_for_malformed_token(): void {
		$verifier = new FeatureTokenVerifier( $this->public_key );

		$this->assertFalse( $verifier->has_feature( '', 'fixed_pricing' ) );
		$this->assertFalse( $verifier->has_feature( 'no-dot', 'fixed_pricing' ) );
		$this->assertFalse( $verifier->has_feature( '!!!.!!!', 'fixed_pricing' ) );
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function build_token( array $overrides = array() ): string {
		$payload   = $this->default_payload( $overrides );
		$canonical = $this->canonicalize( $payload );

		$signature = '';
		openssl_sign( $canonical, $signature, $this->private_key, OPENSSL_ALGO_SHA256 );

		return self::base64url_encode( $canonical ) . '.' . self::base64url_encode( $signature );
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function default_payload( array $overrides = array() ): array {
		return array_merge(
			array(
				'license_key_hash' => 'license-hash-fixture',
				'product_slug'     => 'mhm-currency-switcher',
				'plan'             => 'pro',
				'features'         => array( 'fixed_pricing' => true ),
				'site_hash'        => self::SITE_HASH,
				'issued_at'        => time(),
				'expires_at'       => time() + 86400,
			),
			$overrides
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function canonicalize( array $payload ): string {
		$sorted = $this->recursive_ksort( $payload );
		return (string) wp_json_encode( $sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * @param array<int|string,mixed> $array
	 * @return array<int|string,mixed>
	 */
	private function recursive_ksort( array $array ): array {
		ksort( $array );
		foreach ( $array as $key => $value ) {
			if ( is_array( $value ) ) {
				$array[ $key ] = $this->recursive_ksort( $value );
			}
		}
		return $array;
	}

	private static function base64url_encode( string $binary ): string {
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' );
	}

	private static function base64url_decode( string $input ): string {
		$remainder = strlen( $input ) % 4;
		if ( 0 !== $remainder ) {
			$input .= str_repeat( '=', 4 - $remainder );
		}
		$decoded = base64_decode( strtr( $input, '-_', '+/' ), true );
		return false === $decoded ? '' : $decoded;
	}
}
