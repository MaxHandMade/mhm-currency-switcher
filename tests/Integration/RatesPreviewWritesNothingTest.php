<?php
/**
 * Integration test: POST /mhmcs/v1/rates/preview must never reach the rate
 * API.
 *
 * `RestAPITest::test_the_preview_endpoint_writes_nothing()` (unit suite)
 * covers the option-store half of this contract — no write to
 * `mhmcs_currencies` or `mhmcs_settings`. It cannot cover the transient half:
 * the unit bootstrap's `get_transient()`/`set_transient()` stubs are opt-in
 * and stay an unconditional "always a miss" unless a test first initialises
 * `$GLOBALS['__mhmcs_test_transients']` as an array, which RestAPITest never
 * does — so `assertFalse( get_transient( ... ) )` there would pass whether or
 * not the handler called `RateProvider::fetch_rates()`. Confirmed by
 * mutation: adding a `fetch_rates()` call to `preview_rates()` left that
 * unit assertion green. Only the real WordPress transient API, under the
 * integration suite, can actually catch this handler reaching the network.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use MhmCurrencySwitcher\Core\RateProvider;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class RatesPreviewWritesNothingTest
 */
class RatesPreviewWritesNothingTest extends MhmcsIntegrationTestCase {

	/**
	 * Base currency for this suite.
	 *
	 * @var string
	 */
	private const BASE = 'USD';

	/**
	 * How many times the stubbed HTTP layer was asked for rates. Stubbed
	 * (rather than left to hit the network) so this test stays hermetic and
	 * fast even if a future regression reintroduces the call this test
	 * exists to catch.
	 *
	 * @var int
	 */
	private $http_calls = 0;

	/**
	 * Spin up a REST server so mhmcs/v1 routes are dispatchable, and stub the
	 * outbound rate request.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->http_calls = 0;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				++$this->http_calls;

				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode(
						array( 'rates' => array( self::TARGET_CURRENCY => 1.5 ) )
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
	 * Drop the HTTP stub, any transient the test (or a regression) created,
	 * and the REST server.
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
	 * 🔴 The preview computes; it must never persist — and, specifically,
	 * must never reach the rate-fetching API. Debounced or not, a preview
	 * that wired in `fetch_rates()` would be one network call per keystroke,
	 * burning the shop's rate-provider quota.
	 *
	 * @return void
	 */
	public function test_the_preview_endpoint_never_reaches_the_rate_api(): void {
		delete_transient( RateProvider::TRANSIENT_KEY_PREFIX . self::BASE );

		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/mhmcs/v1/rates/preview' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'base_currency' => self::BASE,
					'currencies'    => array(
						array(
							'code' => self::TARGET_CURRENCY,
							'rate' => array(
								'type'  => 'manual',
								'value' => 0.9,
							),
						),
					),
				)
			)
		);

		$response = rest_do_request( $request );

		$this->assertFalse( $response->is_error(), 'The preview endpoint returned an error.' );

		$this->assertSame(
			0,
			$this->http_calls,
			'The preview reached the rate API. Debounced or not, that is one network call per keystroke '
				. "and it burns the shop's rate-provider quota."
		);

		$this->assertFalse(
			get_transient( RateProvider::TRANSIENT_KEY_PREFIX . self::BASE ),
			'The preview reached the rate API. Debounced or not, that is one network call per keystroke '
				. "and it burns the shop's rate-provider quota."
		);
	}
}
