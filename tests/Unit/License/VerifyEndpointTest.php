<?php
/**
 * Tests for License\VerifyEndpoint.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\License
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\License;

use MhmCurrencySwitcher\License\VerifyEndpoint;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * Phase C (v0.5.0+) — reverse-validation ping endpoint. Tests exercise the
 * handler directly with a stub WP_REST_Request since the stub environment
 * does not run the full REST dispatch pipeline.
 *
 * @covers \MhmCurrencySwitcher\License\VerifyEndpoint
 */
class VerifyEndpointTest extends TestCase {

	private const PING_SECRET = 'test-ping-secret';

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'MHM_CS_LICENSE_PING_SECRET' ) ) {
			putenv( 'MHM_CS_LICENSE_PING_SECRET=' . self::PING_SECRET );
		}
	}

	protected function tearDown(): void {
		if ( ! defined( 'MHM_CS_LICENSE_PING_SECRET' ) ) {
			putenv( 'MHM_CS_LICENSE_PING_SECRET=' );
		}
		parent::tearDown();
	}

	public function test_route_constants_match_expected_slug_namespace(): void {
		$this->assertSame( 'mhm-currency-switcher-verify/v1', VerifyEndpoint::REST_NAMESPACE );
		$this->assertSame( '/ping', VerifyEndpoint::ROUTE );
	}

	public function test_returns_hmac_of_challenge_with_ping_secret(): void {
		$challenge = 'test-challenge-uuid-12345';
		$request   = new WP_REST_Request();
		$request->set_header( 'X-MHM-Challenge', $challenge );

		$response = VerifyEndpoint::handle_ping( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'challenge_response', $data );

		$secret   = defined( 'MHM_CS_LICENSE_PING_SECRET' )
			? (string) constant( 'MHM_CS_LICENSE_PING_SECRET' )
			: self::PING_SECRET;
		$expected = hash_hmac( 'sha256', $challenge, $secret );

		$this->assertSame( $expected, $data['challenge_response'] );
	}

	public function test_response_includes_site_metadata(): void {
		$request = new WP_REST_Request();
		$request->set_header( 'X-MHM-Challenge', 'any-challenge' );

		$response = VerifyEndpoint::handle_ping( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'site_url', $data );
		$this->assertArrayHasKey( 'product_slug', $data );
		$this->assertArrayHasKey( 'version', $data );
		$this->assertSame( 'mhm-currency-switcher', $data['product_slug'] );
		$this->assertSame( home_url(), $data['site_url'] );
	}

	public function test_returns_error_when_challenge_header_missing(): void {
		$request = new WP_REST_Request();
		// No X-MHM-Challenge header.

		$response = VerifyEndpoint::handle_ping( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'challenge_missing', $data['code'] ?? '' );
	}

	/**
	 * v0.5.2+ — When PING_SECRET is unset, the endpoint MUST fall back to
	 * site_hash so customers can activate without editing wp-config.php.
	 * The HMAC key used here mirrors LicenseManager::site_hash() (home +
	 * site + WP version + PHP version, JSON-encoded, SHA-256). Server-side
	 * SiteVerifier uses the same algorithm.
	 */
	public function test_falls_back_to_site_hash_when_ping_secret_unset(): void {
		if ( defined( 'MHM_CS_LICENSE_PING_SECRET' ) ) {
			$this->markTestSkipped( 'Constant defined; site_hash fallback path cannot be asserted.' );
		}

		// Clear the env var that was set in setUp().
		putenv( 'MHM_CS_LICENSE_PING_SECRET=' );

		$challenge = 'fallback-test-uuid';
		$request   = new WP_REST_Request();
		$request->set_header( 'X-MHM-Challenge', $challenge );

		$response = VerifyEndpoint::handle_ping( $request );

		$this->assertSame( 200, $response->get_status() );

		$expected_site_hash = hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'home' => home_url(),
					'site' => site_url(),
					'wp'   => get_bloginfo( 'version' ),
					'php'  => PHP_VERSION,
				)
			)
		);
		$expected_hmac      = hash_hmac( 'sha256', $challenge, $expected_site_hash );

		$data = $response->get_data();
		$this->assertSame( $expected_hmac, $data['challenge_response'] ?? '' );
	}
}
