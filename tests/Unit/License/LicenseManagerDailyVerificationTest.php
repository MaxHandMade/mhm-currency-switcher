<?php
/**
 * Tests for LicenseManager::daily_verification() fail-closed behaviour (v0.7.1).
 *
 * Bug: daily_verification() returned void and silently ignored _error responses,
 * leaving a stale 'active' status in the database forever. These tests verify the
 * fix: on any server error (404, transport timeout, …) the method now fails closed
 * by writing status='inactive' + clearing activation_id + feature_token, and
 * returns a structured {ok, status, message} array.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\License
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\License;

use MhmCurrencySwitcher\License\LicenseManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MhmCurrencySwitcher\License\LicenseManager::daily_verification
 */
class LicenseManagerDailyVerificationTest extends TestCase {

	/** @var array<string, mixed> */
	private array $active_seed = array(
		'license_key'   => 'TEST-KEY-ABC123',
		'status'        => 'active',
		'plan'          => 'pro',
		'activation_id' => 'act-abc-123',
		'feature_token' => 'legacy-feature-token',
		'activated'     => 0,
		'last_check'    => 0,
	);

	protected function setUp(): void {
		parent::setUp();
		LicenseManager::reset();
		$GLOBALS['__mhm_cs_test_options']       = array();
		$GLOBALS['__mhm_cs_test_http_last']     = null;
		$GLOBALS['__mhm_cs_test_http_response'] = null;
	}

	protected function tearDown(): void {
		LicenseManager::reset();
		$GLOBALS['__mhm_cs_test_options']       = array();
		$GLOBALS['__mhm_cs_test_http_last']     = null;
		$GLOBALS['__mhm_cs_test_http_response'] = null;
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Test 1 — Server returns 404 rest_no_route → fail closed.
	// -------------------------------------------------------------------------

	/**
	 * Real-world scenario from maxhandmade.com: plugin had an active license
	 * against the old server; old server was decommissioned; daily cron now
	 * hits wpalemi.com where the key is unknown → 404 rest_no_route.
	 */
	public function test_daily_verification_fails_closed_on_server_404(): void {
		update_option( LicenseManager::OPTION_KEY, $this->active_seed );

		// Queue a 404 response — the licence server returns JSON even for 4xx.
		$this->queue_response(
			array(
				'code'    => 'rest_no_route',
				'message' => 'No route was found matching the URL and request method.',
			),
			404
		);

		$result = LicenseManager::instance()->daily_verification();

		// Return shape.
		$this->assertIsArray( $result );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'inactive', $result['status'] );
		$this->assertNotEmpty( $result['message'] );

		// Option written back with fail-closed values.
		$stored = get_option( LicenseManager::OPTION_KEY, array() );
		$this->assertSame( 'inactive', $stored['status'] );
		$this->assertSame( '', $stored['feature_token'] );
		$this->assertSame( '', $stored['activation_id'] );
		$this->assertGreaterThan( 0, $stored['last_check'] );
	}

	// -------------------------------------------------------------------------
	// Test 2 — Transport error (cURL timeout) → fail closed.
	// -------------------------------------------------------------------------

	/**
	 * When wp_remote_request() returns WP_Error (network unreachable, SSL
	 * failure, cURL timeout, …) the result array contains _error, and the
	 * method must still write status='inactive' and clear credentials.
	 *
	 * The bootstrap stub returns WP_Error automatically when no response is
	 * queued — intentionally skipping queue_response() here.
	 */
	public function test_daily_verification_fails_closed_on_transport_error(): void {
		update_option( LicenseManager::OPTION_KEY, $this->active_seed );

		// Do NOT queue a response → bootstrap stub returns WP_Error('http_request_failed').

		$result = LicenseManager::instance()->daily_verification();

		$this->assertIsArray( $result );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'inactive', $result['status'] );
		$this->assertNotEmpty( $result['message'] );

		$stored = get_option( LicenseManager::OPTION_KEY, array() );
		$this->assertSame( 'inactive', $stored['status'] );
		$this->assertSame( '', $stored['feature_token'] );
		$this->assertSame( '', $stored['activation_id'] );
		$this->assertGreaterThan( 0, $stored['last_check'] );
	}

	// -------------------------------------------------------------------------
	// Test 3 — Regression: server says active → stays active.
	// -------------------------------------------------------------------------

	/**
	 * Happy path regression: server responds with status='active' — the option
	 * must keep status='active' and the method must return ok=true.
	 */
	public function test_daily_verification_keeps_active_when_server_returns_active(): void {
		update_option( LicenseManager::OPTION_KEY, $this->active_seed );

		$new_token = 'new-feature-token-xyz';
		$this->queue_response(
			array(
				'status'        => 'active',
				'plan'          => 'pro',
				'expires_at'    => time() + 86400,
				'feature_token' => $new_token,
			),
			200
		);

		$result = LicenseManager::instance()->daily_verification();

		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'active', $result['status'] );

		$stored = get_option( LicenseManager::OPTION_KEY, array() );
		$this->assertSame( 'active', $stored['status'] );
		$this->assertSame( $new_token, $stored['feature_token'] );
		$this->assertGreaterThan( 0, $stored['last_check'] );
	}

	// -------------------------------------------------------------------------
	// Test 4 — Regression: server says inactive → drops to inactive.
	// -------------------------------------------------------------------------

	/**
	 * Server returns status='inactive' (e.g. license refunded). The option must
	 * drop to inactive, feature_token must be cleared, and ok must be false.
	 */
	public function test_daily_verification_drops_to_inactive_when_server_says_inactive(): void {
		update_option( LicenseManager::OPTION_KEY, $this->active_seed );

		$this->queue_response(
			array(
				'status'  => 'inactive',
				'message' => 'License has been refunded.',
			),
			200
		);

		$result = LicenseManager::instance()->daily_verification();

		$this->assertIsArray( $result );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'inactive', $result['status'] );

		$stored = get_option( LicenseManager::OPTION_KEY, array() );
		$this->assertSame( 'inactive', $stored['status'] );
		$this->assertSame( '', $stored['feature_token'] );
		$this->assertGreaterThan( 0, $stored['last_check'] );
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	/**
	 * Queue a canned HTTP response for the next wp_remote_request() call.
	 *
	 * @param array<string, mixed> $body        Response body (will be JSON-encoded).
	 * @param int                  $status_code HTTP status code.
	 */
	private function queue_response( array $body, int $status_code = 200 ): void {
		$GLOBALS['__mhm_cs_test_http_response'] = array(
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $status_code,
				'message' => 200 === $status_code ? 'OK' : 'Error',
			),
			'headers'  => array(),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
