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

	public function test_returns_error_when_ping_secret_not_configured(): void {
		if ( defined( 'MHM_CS_LICENSE_PING_SECRET' ) ) {
			$this->markTestSkipped( 'Constant defined; not-configured path cannot be asserted.' );
		}

		// Clear the env var that was set in setUp().
		putenv( 'MHM_CS_LICENSE_PING_SECRET=' );

		$request = new WP_REST_Request();
		$request->set_header( 'X-MHM-Challenge', 'foo' );

		$response = VerifyEndpoint::handle_ping( $request );

		$this->assertSame( 503, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'ping_secret_not_configured', $data['code'] ?? '' );
	}
}
