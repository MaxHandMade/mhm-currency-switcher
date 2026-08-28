<?php
/**
 * Integration test: the public `GET /mhmcs/v1/rates` route must not be
 * registered, and its admin-gated siblings must survive its removal.
 *
 * `rest_get_server()->get_routes()` only reflects what actually fired on
 * `rest_api_init` for a real REST server instance -- a unit-suite test that
 * calls a controller method directly would never notice a route left
 * registered (or a sibling accidentally swept away with it), so this has to
 * run against a real WP_REST_Server.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use WP_REST_Server;

/**
 * Class RestRouteRegistrationTest
 */
class RestRouteRegistrationTest extends MhmcsIntegrationTestCase {

	/**
	 * Spin up a REST server for each test. `rest_api_init` only fires on a
	 * real HTTP request to WordPress in production; a plain WP_UnitTestCase
	 * run never triggers it, so routes (including mhmcs/v1) would otherwise
	 * be unregistered and `get_routes()` would come back empty.
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
	 * Tear the REST server back down.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * The public rate table is gone; its admin-gated siblings, and the
	 * unrelated /convert route, are not.
	 *
	 * @return void
	 */
	public function test_the_public_rates_route_is_gone_but_its_siblings_remain(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayNotHasKey(
			'/mhmcs/v1/rates',
			$routes,
			'The public /rates route republished ExchangeRate-API data; it was removed in 2.1.0.'
		);

		// Positive control: without these, the assertion above would pass in
		// a harness where no route is registered at all.
		$this->assertArrayHasKey( '/mhmcs/v1/convert', $routes );
		$this->assertArrayHasKey( '/mhmcs/v1/rates/sync', $routes );
		$this->assertArrayHasKey( '/mhmcs/v1/rates/preview', $routes );
	}
}
