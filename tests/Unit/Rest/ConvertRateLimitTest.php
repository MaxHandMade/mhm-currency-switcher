<?php
/**
 * Rate limiting on the public convert endpoint.
 *
 * The endpoint is unauthenticated by design — it serves cached pages, whose
 * readers are logged out and carry no nonce that could survive being cached —
 * and every other axis of it is bounded: 50 product IDs per request, a
 * validated currency, a visibility check per product. The number of requests
 * was the one thing nothing capped.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Rest
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Rest;

use MhmCurrencySwitcher\Rest\ConvertController;
use PHPUnit\Framework\TestCase;

/**
 * Class ConvertRateLimitTest
 *
 * @covers \MhmCurrencySwitcher\Rest\ConvertController
 */
class ConvertRateLimitTest extends TestCase {

	/**
	 * Start each test with an empty, working transient store and a known IP.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['__mhmcs_test_transients'] = array();
		$_SERVER['REMOTE_ADDR']             = '203.0.113.7';
	}

	/**
	 * Clean up globals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['__mhmcs_test_transients'],
			$GLOBALS['__mhmcs_test_filters'],
			$_SERVER['REMOTE_ADDR']
		);

		parent::tearDown();
	}

	/**
	 * Send n requests from the current IP and report how many were allowed.
	 *
	 * @param int $times Number of attempts.
	 * @return int Attempts that were not rate limited.
	 */
	private function attempts( int $times ): int {
		$allowed = 0;

		for ( $i = 0; $i < $times; $i++ ) {
			if ( ! ConvertController::is_rate_limited() ) {
				++$allowed;
			}
		}

		return $allowed;
	}

	/**
	 * Ordinary browsing is nowhere near the limit.
	 *
	 * A page makes one request, or a handful when blocks hydrate late. The
	 * default has to leave room for a visitor clicking through a catalogue, and
	 * for everyone behind one corporate or mobile-carrier address — the reason
	 * the default is generous rather than tight.
	 *
	 * @return void
	 */
	public function test_normal_traffic_is_not_limited(): void {
		$this->assertSame( 30, $this->attempts( 30 ) );
	}

	/**
	 * The limit stops a flood from one address.
	 *
	 * @return void
	 */
	public function test_a_flood_from_one_address_is_cut_off(): void {
		$allowed = $this->attempts( ConvertController::RATE_LIMIT_REQUESTS + 25 );

		$this->assertSame( ConvertController::RATE_LIMIT_REQUESTS, $allowed );
	}

	/**
	 * One address being cut off does not affect another.
	 *
	 * 🔴 The counter has to be per address. A shared one would let a single
	 * abusive client take the endpoint away from every real visitor — which is
	 * a worse outage than the one rate limiting exists to prevent.
	 *
	 * @return void
	 */
	public function test_the_counter_is_per_address(): void {
		$this->attempts( ConvertController::RATE_LIMIT_REQUESTS + 5 );

		$this->assertTrue( ConvertController::is_rate_limited() );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.4';

		$this->assertFalse( ConvertController::is_rate_limited() );
	}

	/**
	 * The window expires and the address is served again.
	 *
	 * Simulated by ageing the stored bucket rather than by sleeping: a test
	 * that waits a real minute is a test nobody runs.
	 *
	 * @return void
	 */
	public function test_the_window_expires(): void {
		$this->attempts( ConvertController::RATE_LIMIT_REQUESTS + 1 );
		$this->assertTrue( ConvertController::is_rate_limited() );

		foreach ( $GLOBALS['__mhmcs_test_transients'] as $key => $bucket ) {
			$bucket['expires']                          = time() - 1;
			$GLOBALS['__mhmcs_test_transients'][ $key ] = $bucket;
		}

		$this->assertFalse( ConvertController::is_rate_limited() );
	}

	/**
	 * A site can raise, lower or switch off the limit.
	 *
	 * A shop behind a reverse proxy sees every visitor as one address, so the
	 * default can be wrong for reasons the plugin cannot detect. It has to be
	 * the owner's to change.
	 *
	 * @return void
	 */
	public function test_the_limit_is_filterable(): void {
		$GLOBALS['__mhmcs_test_filters']['mhmcs_convert_rate_limit'] = static function ( $args ) {
			$args['limit'] = 3;

			return $args;
		};

		$this->assertSame( 3, $this->attempts( 10 ) );
	}

	/**
	 * Returning a non-positive limit switches rate limiting off entirely.
	 *
	 * @return void
	 */
	public function test_the_limit_can_be_disabled(): void {
		$GLOBALS['__mhmcs_test_filters']['mhmcs_convert_rate_limit'] = static function ( $args ) {
			$args['limit'] = 0;

			return $args;
		};

		$this->assertSame( 500, $this->attempts( 500 ) );
	}
}
