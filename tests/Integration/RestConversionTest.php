<?php
/**
 * Integration tests: the WC REST API's ?currency= parameter (RestApiFilter)
 * converts product prices exactly once -- it must not stack with
 * PriceFilter's own visitor-currency conversion.
 *
 * Only a real dispatch through the WC REST controller can show this: the
 * unit suite has no WP_REST_Server, no WC_REST_Products_Controller, and
 * cannot observe what "the response already went through PriceFilter
 * before RestApiFilter touched it" would look like.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use WP_REST_Request;
use WP_REST_Server;

/**
 * Class RestConversionTest
 */
class RestConversionTest extends MhmcsIntegrationTestCase {

	/**
	 * Spin up a REST server for each test. `rest_api_init` only fires on a
	 * real HTTP request to WordPress in production; a plain WP_UnitTestCase
	 * run never triggers it, so routes (including wc/v3 and mhmcs/v1)
	 * would otherwise be unregistered and every dispatch would 404.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Tear the REST server back down and drop admin auth.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Without a ?currency= parameter, the product response must stay in
	 * the store's base currency and carry no currency_code field.
	 *
	 * @return void
	 */
	public function test_product_response_without_currency_param_stays_in_base_currency(): void {
		$product = $this->create_simple_product( 40.0 );

		wp_set_current_user( self::$admin_id );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/products/' . $product->get_id() );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'GET must succeed against a real, published product.' );
		$this->assertEqualsWithDelta(
			40.0,
			(float) $data['price'],
			0.01,
			'Without ?currency=, the price must stay in the base currency.'
		);
		$this->assertArrayNotHasKey(
			'currency_code',
			$data,
			'RestApiFilter must not add a currency_code field when no currency param was given.'
		);
	}

	/**
	 * With ?currency=EUR, the product response's price must be converted
	 * exactly once.
	 *
	 * @return void
	 */
	public function test_product_response_with_currency_param_converts_exactly_once(): void {
		$product = $this->create_simple_product( 40.0 );

		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'GET', '/wc/v3/products/' . $product->get_id() );
		$request->set_param( 'currency', self::TARGET_CURRENCY );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::TARGET_CURRENCY, $data['currency_code'] );
		$this->assertEqualsWithDelta(
			80.0,
			(float) $data['price'],
			0.01,
			'price must be converted exactly once (40 * 2.0) -- RestApiFilter must not stack with itself or with PriceFilter.'
		);
	}

	/**
	 * A wc/v3 product read that asks for no particular currency reports the
	 * BASE currency, even when the caller happens to carry a currency
	 * cookie (spec §8.4, owner's decision).
	 *
	 * The existing coverage only locked the cookie-LESS case, which passes
	 * for the trivial reason that there is nothing to convert. With a cookie
	 * the answer used to depend on who was asking — unpredictable for the
	 * server-to-server integrations wc/v3 exists for.
	 *
	 * @return void
	 */
	public function test_wc_v3_without_currency_param_returns_base_even_with_cookie(): void {
		$product = $this->create_simple_product( 40.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		wp_set_current_user( self::$admin_id );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/products/' . $product->get_id() );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertEqualsWithDelta(
			40.0,
			(float) $data['price'],
			0.01,
			'Owner decision (spec §8.4): with no ?currency= parameter the wc/v3 response is the BASE currency. It must not depend on the caller carrying a currency cookie -- for a server-to-server client that answer is unpredictable.'
		);
		$this->assertEqualsWithDelta(
			40.0,
			(float) $data['regular_price'],
			0.01,
			'regular_price is pinned to base for the same reason as price.'
		);
		$this->assertArrayNotHasKey(
			'currency_code',
			$data,
			'No currency was requested, so no currency_code is reported.'
		);
	}

	/**
	 * The same guarantee for a request shaped like a real HTTP call to
	 * /wp-json/wc/v3/... — the REQUEST_URI form ConversionContext's REST
	 * branch (decision 2) recognises.
	 *
	 * Kept alongside the test above on purpose: the two reach base currency
	 * through DIFFERENT mechanisms. This one never converts in the first
	 * place (PriceFilter is told not to); the other converts and is pinned
	 * back by RestApiFilter. Losing either mechanism must fail a test.
	 *
	 * @return void
	 */
	public function test_wc_v3_over_a_real_rest_request_uri_returns_base_even_with_cookie(): void {
		$product = $this->create_simple_product( 40.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		wp_set_current_user( self::$admin_id );

		$previous_uri            = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/products/' . $product->get_id();

		try {
			$request  = new WP_REST_Request( 'GET', '/wc/v3/products/' . $product->get_id() );
			$response = rest_do_request( $request );
			$data     = $response->get_data();

			$this->assertSame( 200, $response->get_status() );
			$this->assertEqualsWithDelta(
				40.0,
				(float) $data['price'],
				0.01,
				'A real wc/v3 request without ?currency= must report the base price.'
			);
		} finally {
			if ( null === $previous_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $previous_uri;
			}
		}
	}

	/**
	 * The strongest form of the no-stacking guarantee: the visitor ALSO
	 * already carries a currency cookie (so PriceFilter's own,
	 * cookie-driven conversion is live) at the same time ?currency= is
	 * passed. If RestApiFilter's request-param conversion stacked on top
	 * of a PriceFilter conversion already baked into the response data,
	 * the price would come back converted twice (40 * 2.0 * 2.0 = 160),
	 * not once (80).
	 *
	 * @return void
	 */
	public function test_currency_param_does_not_stack_with_a_visitor_currency_cookie(): void {
		$product = $this->create_simple_product( 40.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'GET', '/wc/v3/products/' . $product->get_id() );
		$request->set_param( 'currency', self::TARGET_CURRENCY );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEqualsWithDelta(
			80.0,
			(float) $data['price'],
			0.01,
			'A visitor currency cookie plus ?currency= must not double-convert the price (would be 160 if stacked).'
		);
	}
}
