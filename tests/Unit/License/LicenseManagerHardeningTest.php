<?php
/**
 * Tests for License\LicenseManager v0.5.0+ hardening additions.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\License
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\License;

use MhmCurrencySwitcher\License\LicenseManager;
use PHPUnit\Framework\TestCase;

/**
 * Phase C (v0.5.0+) — LicenseManager response signing + feature token
 * integration. Mocks HTTP via the stateful wp_remote_request stub declared
 * in tests/bootstrap.php.
 *
 * @covers \MhmCurrencySwitcher\License\LicenseManager
 */
class LicenseManagerHardeningTest extends TestCase {

	private const RESPONSE_SECRET = 'test-resp-secret';
	private const FEATURE_SECRET  = 'test-feature-secret';

	protected function setUp(): void {
		parent::setUp();
		LicenseManager::reset();

		$GLOBALS['__mhm_cs_test_options']      = array();
		$GLOBALS['__mhm_cs_test_http_last']    = null;
		$GLOBALS['__mhm_cs_test_http_response'] = null;

		if ( ! defined( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET' ) ) {
			putenv( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET=' . self::RESPONSE_SECRET );
		}
		if ( ! defined( 'MHM_CS_LICENSE_FEATURE_TOKEN_KEY' ) ) {
			putenv( 'MHM_CS_LICENSE_FEATURE_TOKEN_KEY=' . self::FEATURE_SECRET );
		}
	}

	protected function tearDown(): void {
		LicenseManager::reset();
		$GLOBALS['__mhm_cs_test_options']      = array();
		$GLOBALS['__mhm_cs_test_http_last']    = null;
		$GLOBALS['__mhm_cs_test_http_response'] = null;

		if ( ! defined( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET' ) ) {
			putenv( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET=' );
		}
		if ( ! defined( 'MHM_CS_LICENSE_FEATURE_TOKEN_KEY' ) ) {
			putenv( 'MHM_CS_LICENSE_FEATURE_TOKEN_KEY=' );
		}
		parent::tearDown();
	}

	public function test_activate_request_body_includes_client_version(): void {
		$this->queue_response(
			$this->signed_response(
				array(
					'status'        => 'active',
					'plan'          => 'pro',
					'activation_id' => 'a1',
					'expires_at'    => time() + 86400,
				)
			)
		);

		LicenseManager::instance()->activate( 'TEST-V050-001' );

		$last = $GLOBALS['__mhm_cs_test_http_last'];
		$this->assertIsArray( $last );
		$body = json_decode( (string) ( $last['args']['body'] ?? '{}' ), true );
		$this->assertIsArray( $body );
		$this->assertArrayHasKey( 'client_version', $body );

		$expected = defined( 'MHM_CS_VERSION' ) ? MHM_CS_VERSION : '';
		$this->assertSame( $expected, $body['client_version'] );
	}

	public function test_activate_succeeds_when_response_signature_is_valid(): void {
		$this->queue_response(
			$this->signed_response(
				array(
					'status'        => 'active',
					'plan'          => 'pro',
					'expires_at'    => time() + 86400,
					'activation_id' => 'a1',
					'feature_token' => $this->build_feature_token(),
				)
			)
		);

		$result = LicenseManager::instance()->activate( 'TEST-V050-002' );
		$this->assertTrue( $result['success'] );
	}

	public function test_activate_rejects_when_response_signature_invalid(): void {
		$body = $this->signed_response(
			array(
				'status'        => 'active',
				'activation_id' => 'a1',
				'expires_at'    => time() + 86400,
			)
		);
		// Tamper after signing.
		$body['plan'] = 'free';

		$this->queue_response( $body );

		$result = LicenseManager::instance()->activate( 'TEST-V050-003' );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'signature verification', $result['message'] );
	}

	public function test_activate_accepts_legacy_server_without_signature_field(): void {
		// No signature — simulating legacy server (v1.8.x).
		$this->queue_response(
			array(
				'status'        => 'active',
				'plan'          => 'pro',
				'expires_at'    => time() + 86400,
				'activation_id' => 'a1',
			)
		);

		$result = LicenseManager::instance()->activate( 'TEST-V050-004' );
		$this->assertTrue( $result['success'] );
	}

	public function test_activate_stores_feature_token_in_local_option(): void {
		$token = $this->build_feature_token();
		$this->queue_response(
			$this->signed_response(
				array(
					'status'        => 'active',
					'plan'          => 'pro',
					'expires_at'    => time() + 86400,
					'activation_id' => 'a1',
					'feature_token' => $token,
				)
			)
		);

		LicenseManager::instance()->activate( 'TEST-V050-005' );

		$stored = get_option( LicenseManager::OPTION_KEY, array() );
		$this->assertArrayHasKey( 'feature_token', $stored );
		$this->assertSame( $token, $stored['feature_token'] );
	}

	public function test_get_feature_token_returns_stored_value(): void {
		update_option(
			LicenseManager::OPTION_KEY,
			array(
				'license_key'   => 'k',
				'status'        => 'active',
				'feature_token' => 'stored-token-abc',
			)
		);

		$this->assertSame( 'stored-token-abc', LicenseManager::instance()->get_feature_token() );
	}

	public function test_get_feature_token_returns_empty_when_missing(): void {
		update_option(
			LicenseManager::OPTION_KEY,
			array(
				'license_key' => 'k',
				'status'      => 'active',
			)
		);

		$this->assertSame( '', LicenseManager::instance()->get_feature_token() );
	}

	public function test_daily_verification_refreshes_feature_token_from_server_response(): void {
		update_option(
			LicenseManager::OPTION_KEY,
			array(
				'license_key'   => 'EXISTING-KEY',
				'status'        => 'active',
				'activation_id' => 'a-existing',
				'feature_token' => 'old-token-xyz',
				'last_check'    => time() - 3600,
			)
		);

		$new_token = $this->build_feature_token();
		$this->queue_response(
			$this->signed_response(
				array(
					'status'        => 'active',
					'plan'          => 'pro',
					'expires_at'    => time() + 86400,
					'feature_token' => $new_token,
				)
			)
		);

		LicenseManager::instance()->daily_verification();

		$stored = get_option( LicenseManager::OPTION_KEY, array() );
		$this->assertSame( $new_token, $stored['feature_token'] );
	}

	/**
	 * Queue a response for the next wp_remote_request() call.
	 *
	 * @param array<string, mixed> $body Response body.
	 */
	private function queue_response( array $body ): void {
		$GLOBALS['__mhm_cs_test_http_response'] = array(
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'headers'  => array(),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Sign a payload the same way the server does.
	 *
	 * @param array<string, mixed> $payload Payload to sign.
	 * @return array<string, mixed> Payload with signature appended.
	 */
	private function signed_response( array $payload ): array {
		$this->ksort_recursive( $payload );
		$canonical            = (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$payload['signature'] = hash_hmac( 'sha256', $canonical, self::RESPONSE_SECRET );
		return $payload;
	}

	/**
	 * @param array<mixed, mixed> $data
	 */
	private function ksort_recursive( array &$data ): void {
		foreach ( $data as &$v ) {
			if ( is_array( $v ) ) {
				$this->ksort_recursive( $v );
			}
		}
		unset( $v );
		ksort( $data );
	}

	private function build_feature_token(): string {
		$payload = array(
			'license_key_hash' => 'h',
			'product_slug'     => 'mhm-currency-switcher',
			'plan'             => 'pro',
			'features'         => array(
				'fixed_pricing' => true,
				'geolocation'   => true,
			),
			'site_hash'        => 's',
			'issued_at'        => time(),
			'expires_at'       => time() + 86400,
		);
		$b64     = base64_encode( (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return $b64 . '.' . hash_hmac( 'sha256', $b64, self::FEATURE_SECRET );
	}
}
