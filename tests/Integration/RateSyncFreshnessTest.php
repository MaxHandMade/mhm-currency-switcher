<?php
/**
 * Integration tests: an explicit rate synchronisation must actually reach the
 * rate API, and an ordinary rate lookup must still not.
 *
 * `RateProvider::fetch_rates()` returns the cached transient whenever one is
 * present, and that transient lives for a fixed 86400 seconds. Every sync path
 * -- the admin panel's "Sync rates" button, the cron tick and `wp mhm-cs rates
 * sync` -- went through that same door, so for up to a day none of them
 * fetched anything: the button answered `success: true` with the rates it had
 * just been handed back by the cache, and an "hourly" schedule woke up
 * twenty-four times to re-apply one morning's numbers.
 *
 * Only an integration test can show this. The unit suite has no transient API
 * and no HTTP layer, so it cannot tell "fetched again" from "served from
 * cache" -- the two are indistinguishable by return value alone, which is
 * precisely why the defect shipped.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use MhmCurrencySwitcher\Core\RateProvider;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class RateSyncFreshnessTest
 */
class RateSyncFreshnessTest extends MhmcsIntegrationTestCase {

	/**
	 * Base currency for this suite.
	 *
	 * @var string
	 */
	private const BASE = 'USD';

	/**
	 * The number the warm transient is primed with. A sync that answers with
	 * this value did not reach the API.
	 *
	 * @var float
	 */
	private const STALE_RATE = 1.0;

	/**
	 * The number the stubbed API answers with. A sync that produces this value
	 * genuinely went out to the network.
	 *
	 * @var float
	 */
	private const FRESH_RATE = 2.5;

	/**
	 * How many times the stubbed HTTP layer was asked for rates.
	 *
	 * @var int
	 */
	private $http_calls = 0;

	/**
	 * Spin up a REST server so mhmcs/v1 routes are dispatchable.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->http_calls = 0;
	}

	/**
	 * Drop the HTTP stub, the primed transient and the REST server.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_transient( RateProvider::TRANSIENT_KEY_PREFIX . self::BASE );

		global $wp_rest_server;
		$wp_rest_server = null;

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Answer every outbound rate request with FRESH_RATE and count the calls.
	 *
	 * Counting is the whole point: both a cache hit and a real fetch return a
	 * populated array, so the return value cannot distinguish them. The call
	 * counter can.
	 *
	 * @return void
	 */
	private function stub_rate_api(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				++$this->http_calls;

				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode(
						array( 'rates' => array( self::TARGET_CURRENCY => self::FRESH_RATE ) )
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Put a populated, unexpired rate transient in place — the state a site is
	 * in for 24 hours after any successful fetch.
	 *
	 * @return void
	 */
	private function prime_warm_cache(): void {
		set_transient(
			RateProvider::TRANSIENT_KEY_PREFIX . self::BASE,
			array( self::TARGET_CURRENCY => self::STALE_RATE ),
			RateProvider::TRANSIENT_EXPIRY
		);
	}

	/**
	 * Configure one automatic-rate currency so the sync has something to apply.
	 *
	 * @return void
	 */
	private function configure_automatic_currency(): void {
		$this->configure_currency(
			array(
				'code' => self::TARGET_CURRENCY,
				'rate' => array(
					'type'  => 'auto',
					'value' => self::STALE_RATE,
				),
			),
			self::BASE
		);
	}

	/**
	 * Read the stored rate for TARGET_CURRENCY straight out of the option, so
	 * the assertion does not depend on what the endpoint chose to echo back.
	 *
	 * `CurrencyStore::save()` writes a JSON string while `load()` also accepts
	 * a plain array, so both shapes are decoded here for the same reason
	 * `load()` does: reading only one of them would make this helper quietly
	 * return null and every assertion below it meaningless.
	 *
	 * @return float|null
	 */
	private function stored_rate(): ?float {
		$data = get_option( 'mhmcs_currencies', '' );

		if ( is_string( $data ) && '' !== $data ) {
			$data = json_decode( $data, true );
		}

		if ( ! is_array( $data ) || ! isset( $data['currencies'] ) || ! is_array( $data['currencies'] ) ) {
			return null;
		}

		foreach ( $data['currencies'] as $currency ) {
			if ( ( $currency['code'] ?? '' ) === self::TARGET_CURRENCY ) {
				return isset( $currency['rate']['value'] ) ? (float) $currency['rate']['value'] : null;
			}
		}

		return null;
	}

	/**
	 * The panel's "Sync rates" button must reach the API even when the
	 * transient is warm. Pressing it is an explicit request for fresh numbers;
	 * answering it from a day-old cache and reporting success is the defect.
	 *
	 * @return void
	 */
	public function test_sync_endpoint_fetches_fresh_rates_when_the_transient_is_warm(): void {
		$this->configure_automatic_currency();
		$this->prime_warm_cache();
		$this->stub_rate_api();

		wp_set_current_user( self::$admin_id );

		$response = rest_do_request( new WP_REST_Request( 'POST', '/mhmcs/v1/rates/sync' ) );

		$this->assertFalse( $response->is_error(), 'The sync endpoint returned an error.' );

		$this->assertSame(
			1,
			$this->http_calls,
			'The sync button did not reach the rate API: it answered out of the 24-hour transient.'
		);

		$this->assertEqualsWithDelta(
			self::FRESH_RATE,
			$this->stored_rate(),
			0.0001,
			'The synced rate is the stale cached number, not the one the API just returned.'
		);
	}

	/**
	 * The scheduled update must fetch too. Choosing "hourly" in the panel is a
	 * statement about how often rates should be refreshed; a fixed day-long
	 * transient silently turns hourly and twice-daily into daily.
	 *
	 * @return void
	 */
	public function test_scheduled_update_fetches_fresh_rates_when_the_transient_is_warm(): void {
		$this->configure_automatic_currency();
		$this->prime_warm_cache();
		$this->stub_rate_api();

		do_action( 'mhmcs_update_rates' );

		$this->assertSame(
			1,
			$this->http_calls,
			'The cron tick did not reach the rate API: an hourly schedule would re-apply one cached day of rates.'
		);

		$this->assertEqualsWithDelta(
			self::FRESH_RATE,
			$this->stored_rate(),
			0.0001,
			'The cron tick stored the stale cached rate.'
		);
	}

	/**
	 * The negative control, and the reason the fix is a parameter rather than a
	 * shorter transient: an ordinary rate lookup must still be served from the
	 * cache. This transient is what keeps a cached-page shop from calling the
	 * rate API on every visitor's page view, so a fix that simply bypassed it
	 * everywhere would trade a stale number for a request storm.
	 *
	 * If this test ever fails, the fix went too far.
	 *
	 * @return void
	 */
	public function test_ordinary_rate_lookup_is_still_served_from_the_transient(): void {
		$this->prime_warm_cache();
		$this->stub_rate_api();

		$rates = ( new RateProvider() )->fetch_rates( self::BASE );

		$this->assertSame(
			0,
			$this->http_calls,
			'An ordinary lookup went out to the network; the rate cache is no longer doing its job.'
		);

		$this->assertEqualsWithDelta(
			self::STALE_RATE,
			$rates[ self::TARGET_CURRENCY ] ?? null,
			0.0001,
			'An ordinary lookup did not return the cached rate.'
		);
	}
}
