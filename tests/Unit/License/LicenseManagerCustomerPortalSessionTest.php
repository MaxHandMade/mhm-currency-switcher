<?php
/**
 * Tests for LicenseManager::create_customer_portal_session() — v0.7.0.
 *
 * Snake_case parity to Rentiva v4.32.0 Phase 3A Task C.1. Mocks HTTP via
 * the stateful wp_remote_request stub declared in tests/bootstrap.php.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\License
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\License;

use MhmCurrencySwitcher\License\LicenseManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MhmCurrencySwitcher\License\LicenseManager::create_customer_portal_session
 */
class LicenseManagerCustomerPortalSessionTest extends TestCase {

	private const RESPONSE_SECRET = 'test-resp-secret';

	protected function setUp(): void {
		parent::setUp();
		LicenseManager::reset();

		$GLOBALS['__mhm_cs_test_options']       = array();
		$GLOBALS['__mhm_cs_test_http_last']     = null;
		$GLOBALS['__mhm_cs_test_http_response'] = null;

		if ( ! defined( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET' ) ) {
			putenv( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET=' . self::RESPONSE_SECRET );
		}
	}

	protected function tearDown(): void {
		LicenseManager::reset();
		$GLOBALS['__mhm_cs_test_options']       = array();
		$GLOBALS['__mhm_cs_test_http_last']     = null;
		$GLOBALS['__mhm_cs_test_http_response'] = null;

		if ( ! defined( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET' ) ) {
			putenv( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET=' );
		}
		parent::tearDown();
	}

	public function test_returns_error_when_license_not_active(): void {
		// No stored license — is_active() will return false.
		$result = LicenseManager::instance()->create_customer_portal_session( 'https://example.test/wp-admin/' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'license_not_active', $result['error_code'] );
	}

	public function test_returns_url_on_happy_path(): void {
		$this->seed_active_license();

		$this->queue_response(
			$this->signed_response(
				array(
					'success' => true,
					'data'    => array(
						'customer_portal_url' => 'https://polar.sh/portal/sess_abc123',
						'expires_at'          => '2026-12-31T23:59:59+00:00',
					),
				)
			),
			200
		);

		$result = LicenseManager::instance()->create_customer_portal_session( 'https://example.test/wp-admin/' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'https://polar.sh/portal/sess_abc123', $result['customer_portal_url'] );
		$this->assertSame( '2026-12-31T23:59:59+00:00', $result['expires_at'] );

		// Verify URL hit the right endpoint.
		$last = $GLOBALS['__mhm_cs_test_http_last'];
		$this->assertIsArray( $last );
		$this->assertStringEndsWith( '/licenses/customer-portal-session', (string) $last['url'] );

		// Verify body shape.
		$body = json_decode( (string) ( $last['args']['body'] ?? '{}' ), true );
		$this->assertIsArray( $body );
		$this->assertSame( 'TEST-PORTAL-001', $body['license_key'] );
		$this->assertNotEmpty( $body['site_hash'] );
		$this->assertSame( 'https://example.test/wp-admin/', $body['return_url'] );
	}

	public function test_returns_error_on_server_404(): void {
		$this->seed_active_license();

		// Server 404 = license not found. CS request() returns _error key on
		// non-2xx, with _code copied from JSON body when present.
		$this->queue_response(
			array(
				'success' => false,
				'code'    => 'license_not_found',
				'message' => 'License key does not exist on server.',
			),
			404
		);

		$result = LicenseManager::instance()->create_customer_portal_session( '' );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'license_not_found', $result['error_code'] );
	}

	public function test_returns_error_on_server_422(): void {
		$this->seed_active_license();

		// Server 422 = license is not subscription-backed (e.g. lifetime).
		$this->queue_response(
			array(
				'success' => false,
				'code'    => 'license_not_subscription',
				'message' => 'This license is not tied to a Polar subscription.',
			),
			422
		);

		$result = LicenseManager::instance()->create_customer_portal_session( '' );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'license_not_subscription', $result['error_code'] );
	}

	public function test_returns_error_on_signature_mismatch(): void {
		$this->seed_active_license();

		// Build a properly signed payload, then tamper a field after signing.
		$payload                                 = $this->signed_response(
			array(
				'success' => true,
				'data'    => array(
					'customer_portal_url' => 'https://polar.sh/portal/sess_legit',
					'expires_at'          => '2026-12-31T23:59:59+00:00',
				),
			)
		);
		$payload['data']['customer_portal_url'] = 'https://evil.example/portal/stolen';

		$this->queue_response( $payload, 200 );

		$result = LicenseManager::instance()->create_customer_portal_session( '' );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'tampered_response', $result['error_code'] );
	}

	/**
	 * Seed an active license in the option store so is_active() returns true.
	 */
	private function seed_active_license(): void {
		update_option(
			LicenseManager::OPTION_KEY,
			array(
				'license_key'   => 'TEST-PORTAL-001',
				'status'        => 'active',
				'plan'          => 'pro',
				'expires_at'    => '2026-12-31T23:59:59+00:00',
				'activation_id' => 'a-portal-1',
			)
		);
	}

	/**
	 * Queue a response for the next wp_remote_request() call.
	 *
	 * @param array<string, mixed> $body Response body.
	 * @param int                  $code HTTP status code.
	 */
	private function queue_response( array $body, int $code = 200 ): void {
		$GLOBALS['__mhm_cs_test_http_response'] = array(
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Error',
			),
			'headers'  => array(),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Sign a payload the same way the server does (HMAC-SHA256 over canonical JSON).
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
}
