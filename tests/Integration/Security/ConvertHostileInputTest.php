<?php
/**
 * Hostile input shapes on the one public route.
 *
 * @package MhmCurrencySwitcher\Tests\Integration\Security
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration\Security;

use MhmCurrencySwitcher\Tests\Integration\MhmcsIntegrationTestCase;
use MhmCurrencySwitcher\Tests\Integration\Security\Support\StateFingerprint;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/mhmcs/v1/convert` answers anonymous callers, so it is the one place a
 * stranger chooses the input. ConvertEndpointTest locks the CONTRACT (the
 * 50-ID cap, dedupe, visibility, the base-currency fallback that keeps the
 * enabled currencies from being enumerated). This file locks what that
 * contract leaves open: shapes no client sends.
 *
 * Every case must end in a response — never a fatal, never a PHP notice or
 * warning (phpunit-integration.xml.dist converts both into errors, so a
 * notice fails the test on its own) — and write nothing but the rate-limit
 * counter.
 *
 * Deliberately NOT asserted: a malformed currency code → 400. It resolves to
 * the base currency on purpose; see ConvertController::validate_currency().
 */
class ConvertHostileInputTest extends MhmcsIntegrationTestCase {

	use StateFingerprint;

	private const ROUTE = '/mhmcs/v1/convert';

	/**
	 * Anonymous REST caller.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( 0 );
		$_GET['rest_route'] = self::ROUTE;
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_GET['rest_route'] );
		$this->reset_detection_service();

		parent::tear_down();
	}

	/**
	 * POST a raw JSON body.
	 *
	 * @param string $json Body.
	 * @return WP_REST_Response
	 */
	private function post( string $json ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $json );

		return rest_do_request( $request );
	}

	/**
	 * Shapes that must be refused with a 4xx.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function refused_shapes(): array {
		return array(
			'10k nested arrays'          => array( (string) wp_json_encode( array( 'product_ids' => array_fill( 0, 10000, array( 1, 2 ) ) ) ) ),
			'10k ints'                   => array( (string) wp_json_encode( array( 'product_ids' => range( 1, 10000 ) ) ) ),
			'product_ids is a string'    => array( '{"product_ids":"1,2,3"}' ),
			'product_ids is null'        => array( '{"product_ids":null}' ),
			'currency is an array'       => array( '{"product_ids":[1],"currency":["EUR"]}' ),
			'currency is an object'      => array( '{"product_ids":[1],"currency":{"code":"EUR"}}' ),
			'currency is an int'         => array( '{"product_ids":[1],"currency":978}' ),
			'currency is a bool'         => array( '{"product_ids":[1],"currency":true}' ),
			'no body at all'             => array( '' ),
			'body is not JSON'           => array( 'product_ids=1&currency=EUR' ),
			'body is a JSON scalar'      => array( '42' ),
		);
	}

	/**
	 * Refused shapes: 4xx, no prices, nothing written.
	 *
	 * @dataProvider refused_shapes
	 *
	 * @param string $json Body.
	 * @return void
	 */
	public function test_hostile_shape_is_refused( string $json ): void {
		$before   = $this->state_fingerprint();
		$response = $this->post( $json );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertLessThan( 500, $response->get_status(), 'A hostile shape is the caller\'s error, never a server error.' );
		$this->assertArrayNotHasKey( 'prices', (array) $response->get_data() );
		$this->assertSame( $before, $this->state_fingerprint(), 'A refused request must write nothing.' );
	}

	/**
	 * Shapes inside the 50-ID cap whose entries are junk. The contract is to
	 * DROP junk entries rather than reject the batch (see
	 * ConvertController::sanitize_product_ids()), so these are a 200 — but one
	 * that prices only the real product and nothing a junk entry could alias.
	 *
	 * @return void
	 */
	public function test_junk_entries_inside_the_cap_are_dropped_not_aliased(): void {
		$product = $this->create_simple_product( 100.0 );
		$junk    = array(
			array( $product->get_id() ),  // Nested array holding a real ID.
			array( 'id' => $product->get_id() ),
			array( 'a' => array( 'b' => array( 'c' => 1 ) ) ),
			'1e309',                     // is_numeric, casts to a non-finite float.
			// Beyond PHP_INT_MAX. NOT dropped by the sanitiser: (int) saturates
			// to PHP_INT_MAX and it is skipped later, by the visibility check in
			// render_prices(), because no such post exists. Loosening that check
			// is what this entry would catch.
			'99999999999999999999999',
			'-' . $product->get_id(),     // absint() would turn this into the real ID.
			'0x1A',
			'',
			null,
			true,
			1.5,
			0,
		);

		$before   = $this->state_fingerprint();
		$response = $this->post(
			(string) wp_json_encode(
				array(
					'currency'    => self::TARGET_CURRENCY,
					'product_ids' => $junk,
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['prices'], 'No junk entry may resolve to a real product.' );
		$this->assertSame( $before, $this->state_fingerprint() );

		// Positive control: the same request with the real ID added prices it,
		// so the empty map above is the sanitiser at work, not a dead route.
		$junk[]   = $product->get_id();
		$response = $this->post(
			(string) wp_json_encode(
				array(
					'currency'    => self::TARGET_CURRENCY,
					'product_ids' => $junk,
				)
			)
		);
		$this->assertSame( array( $product->get_id() ), array_keys( $response->get_data()['prices'] ) );
	}
}
