<?php
/**
 * Tests for License\FeatureTokenVerifier.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\License
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\License;

use MhmCurrencySwitcher\License\FeatureTokenVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Mirror of mhm-license-server v1.9.0 FeatureTokenIssuer — verify side only.
 *
 * Token wire format: `{base64(json_payload)}.{hmac_hex}`. Tests reproduce the
 * server-side issuance exactly so a drift here means the live server stopped
 * being parseable.
 *
 * @covers \MhmCurrencySwitcher\License\FeatureTokenVerifier
 */
class FeatureTokenVerifierTest extends TestCase {

	private const SECRET = 'test-feature-token-key';

	public function test_verifies_well_formed_server_issued_token(): void {
		$token = $this->server_style_issue(
			array(
				'license_key_hash' => 'h',
				'product_slug'     => 'mhm-currency-switcher',
				'plan'             => 'pro',
				'features'         => array(
					'fixed_pricing' => true,
					'geolocation'   => true,
				),
				'site_hash'        => 's',
				'issued_at'        => time(),
				'expires_at'       => time() + 3600,
			)
		);

		$verifier = new FeatureTokenVerifier( self::SECRET );
		$payload  = $verifier->verify( $token );

		$this->assertIsArray( $payload );
		$this->assertSame( 'mhm-currency-switcher', $payload['product_slug'] );
		$this->assertTrue( $payload['features']['fixed_pricing'] );
	}

	public function test_returns_null_for_token_signed_with_different_secret(): void {
		$token = $this->server_style_issue(
			array(
				'features'   => array( 'fixed_pricing' => true ),
				'expires_at' => time() + 3600,
			),
			'wrong-secret'
		);

		$verifier = new FeatureTokenVerifier( self::SECRET );
		$this->assertNull( $verifier->verify( $token ) );
	}

	public function test_returns_null_for_expired_token(): void {
		$token = $this->server_style_issue(
			array(
				'features'   => array( 'fixed_pricing' => true ),
				'expires_at' => time() - 10,
			)
		);

		$verifier = new FeatureTokenVerifier( self::SECRET );
		$this->assertNull( $verifier->verify( $token ) );
	}

	public function test_returns_null_for_malformed_tokens(): void {
		$verifier = new FeatureTokenVerifier( self::SECRET );
		$this->assertNull( $verifier->verify( '' ) );
		$this->assertNull( $verifier->verify( 'no-dot' ) );
		$this->assertNull( $verifier->verify( 'a.b.c.d' ) );
		$this->assertNull( $verifier->verify( '!!!.!!!' ) );
	}

	public function test_has_feature_returns_true_only_when_payload_grants_it(): void {
		$verifier = new FeatureTokenVerifier( self::SECRET );

		$payload = array(
			'features' => array(
				'fixed_pricing' => true,
				'geolocation'   => false,
			),
		);

		$this->assertTrue( $verifier->has_feature( $payload, 'fixed_pricing' ) );
		$this->assertFalse( $verifier->has_feature( $payload, 'geolocation' ) );
		$this->assertFalse( $verifier->has_feature( $payload, 'nonexistent_feature' ) );
		$this->assertFalse( $verifier->has_feature( null, 'fixed_pricing' ) );
		$this->assertFalse( $verifier->has_feature( array(), 'fixed_pricing' ) );
		$this->assertFalse( $verifier->has_feature( array( 'features' => 'not-an-array' ), 'fixed_pricing' ) );
	}

	/**
	 * Reproduces server-side FeatureTokenIssuer::issue() output format.
	 *
	 * @param array<string, mixed> $payload Payload to sign.
	 * @param string|null          $secret  Optional secret override.
	 * @return string Token in wire format.
	 */
	private function server_style_issue( array $payload, ?string $secret = null ): string {
		$secret      = $secret ?? self::SECRET;
		$payload_b64 = base64_encode(
			(string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
		$signature   = hash_hmac( 'sha256', $payload_b64, $secret );
		return $payload_b64 . '.' . $signature;
	}
}
