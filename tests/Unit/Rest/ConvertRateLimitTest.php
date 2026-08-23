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

use MhmCurrencySwitcher\Core\ConversionContext;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;
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
			$GLOBALS['__mhmcs_test_wc_ip'],
			$_SERVER['REMOTE_ADDR']
		);

		parent::tearDown();
	}

	/**
	 * How many transient keys the limiter has created so far. Each distinct
	 * address gets one, so this counts the buckets an attacker can conjure.
	 *
	 * @return int
	 */
	private function bucket_count(): int {
		$keys = array_keys( $GLOBALS['__mhmcs_test_transients'] ?? array() );

		return count(
			array_filter(
				$keys,
				static function ( $key ): bool {
					return 0 === strpos( (string) $key, ConvertController::RATE_LIMIT_PREFIX );
				}
			)
		);
	}

	/**
	 * A forged address header that is not an IP at all must not become a
	 * counter key of its own.
	 *
	 * WooCommerce hands `X-Real-IP` back verbatim — its only IP validation is
	 * on the X-Forwarded-For branch — so before this was checked, anything a
	 * caller wrote in that header became part of a transient name. On a site
	 * with no external object cache that is a row in `wp_options` per distinct
	 * value, created by an unauthenticated request, and nothing bounds how many
	 * distinct values one client can send.
	 *
	 * Forging is not the point here; the docblock on the limiter already
	 * accepts that a determined attacker can rotate real addresses past it.
	 * What must not happen is arbitrary text becoming storage.
	 *
	 * @return void
	 */
	public function test_a_forged_non_ip_address_does_not_get_its_own_bucket(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		foreach ( array( 'not-an-ip', 'another-forgery', '<script>', 'a'.'b'.'c' ) as $forged ) {
			$GLOBALS['__mhmcs_test_wc_ip'] = $forged;
			ConvertController::is_rate_limited();
		}

		$this->assertSame(
			1,
			$this->bucket_count(),
			'Each forged header value created its own transient; an unauthenticated caller can grow wp_options without bound.'
		);
	}

	/**
	 * The control, and the reason the fix is "validate" rather than "always use
	 * REMOTE_ADDR".
	 *
	 * On any shop behind Cloudflare or a load balancer every visitor shares one
	 * REMOTE_ADDR. Keying on it alone would put the whole shop in a single
	 * counter and let one visitor take the conversion endpoint down for
	 * everyone — which is exactly the trade-off the limiter's docblock weighs
	 * and rejects. Two genuine addresses must still count separately.
	 *
	 * @return void
	 */
	public function test_two_real_forwarded_addresses_still_count_separately(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		$GLOBALS['__mhmcs_test_wc_ip'] = '198.51.100.4';
		$this->attempts( ConvertController::RATE_LIMIT_REQUESTS + 5 );

		$GLOBALS['__mhmcs_test_wc_ip'] = '198.51.100.9';

		$this->assertFalse(
			ConvertController::is_rate_limited(),
			'A second genuine address was already limited, so proxied visitors are sharing one counter.'
		);
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

	/**
	 * 🔴 The rate-limited answer must not be cacheable either.
	 *
	 * The success path sets `Cache-Control: no-store` for a stated reason: this
	 * endpoint's body depends on a cookie, so a shared cache keying it by URL
	 * alone would hand one visitor's currency to the next. The 429 branch was
	 * built with only `Retry-After` and nobody asked whether the same reasoning
	 * applied to it.
	 *
	 * It applies more sharply. A cached 429 is served to visitors who are not
	 * rate limited at all — the endpoint appears broken for everyone behind
	 * that cache until the entry expires, and the one client actually flooding
	 * it is the only one guaranteed to still get through, because it is the one
	 * whose requests keep arriving.
	 *
	 * @return void
	 */
	public function test_the_rate_limited_response_is_not_cacheable(): void {
		// Trip the limiter, then ask for the response the visitor receives.
		$this->attempts( ConvertController::RATE_LIMIT_REQUESTS + 1 );

		$controller = new ConvertController( new ConversionContext(), new DetectionService( new CurrencyStore(), new ConversionContext() ) );
		$response   = $controller->convert( new \WP_REST_Request() );

		$this->assertSame( 429, $response->get_status(), 'Guard: the limiter really tripped, so this is the branch under test.' );

		$headers = $response->get_headers();

		$this->assertArrayHasKey( 'Retry-After', $headers, 'Guard: the headers the branch already set are visible to this test.' );

		$this->assertArrayHasKey(
			'Cache-Control',
			$headers,
			'A 429 with no cache header can be stored and replayed to visitors who are not rate limited.'
		);

		$this->assertSame( 'no-store', $headers['Cache-Control'] );
	}
}
