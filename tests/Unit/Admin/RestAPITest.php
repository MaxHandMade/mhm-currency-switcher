<?php
/**
 * Unit tests for Admin\RestAPI.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Admin;

use MhmCurrencySwitcher\Admin\RestAPI;
use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\RateProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class RestAPITest
 *
 * Directly calls RestAPI methods without the REST framework.
 *
 * @covers \MhmCurrencySwitcher\Admin\RestAPI
 */
class RestAPITest extends TestCase {

	/**
	 * Helper: build a single currency config array.
	 *
	 * @param string $code    ISO 4217 code.
	 * @param float  $rate    Exchange rate value.
	 * @param bool   $enabled Whether the currency is enabled.
	 * @return array<string, mixed>
	 */
	private function make_currency( string $code, float $rate = 1.0, bool $enabled = true ): array {
		return array(
			'code'            => $code,
			'enabled'         => $enabled,
			'sort_order'      => 0,
			'rate'            => array(
				'type'  => 'auto',
				'value' => $rate,
			),
			'fee'             => array(
				'type'  => 'fixed',
				'value' => 0,
			),
			'rounding'        => array(
				'type'     => 'disabled',
				'value'    => 0,
				'subtract' => 0,
			),
			'format'          => array(
				'symbol'       => $code,
				'position'     => 'left',
				'thousand_sep' => ',',
				'decimal_sep'  => '.',
				'decimals'     => 2,
			),
			'payment_methods' => array( 'all' ),
			'countries'       => array(),
		);
	}

	/**
	 * Helper: create a RestAPI instance with the given currencies.
	 *
	 * @param array<int, array<string, mixed>> $currencies Currency configs.
	 * @param string                           $base       Base currency code.
	 * @return RestAPI
	 */
	private function create_api( array $currencies = array(), string $base = 'USD' ): RestAPI {
		$store = new CurrencyStore();
		$store->set_data( $base, $currencies );

		$converter     = new Converter( $store );
		$rate_provider = new RateProvider();

		return new RestAPI( $store, $converter, $rate_provider );
	}

	/**
	 * Test that get_currencies returns a proper structure.
	 *
	 * @return void
	 */
	public function test_get_currencies_returns_array(): void {
		$api = $this->create_api(
			array(
				$this->make_currency( 'EUR', 0.85 ),
				$this->make_currency( 'GBP', 0.73 ),
			)
		);

		$response = $api->get_currencies();
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'base_currency', $data );
		$this->assertArrayHasKey( 'currencies', $data );
		$this->assertSame( 'USD', $data['base_currency'] );
		$this->assertCount( 2, $data['currencies'] );
	}

	/**
	 * Test that save_currencies enforces the free-tier limit.
	 *
	 * @return void
	 */
	public function test_save_currencies_enforces_limit(): void {
		$store = new CurrencyStore();
		$store->set_data( 'USD', array() );
		$store->set_free_limit( 2 );

		$converter     = new Converter( $store );
		$rate_provider = new RateProvider();
		$api           = new RestAPI( $store, $converter, $rate_provider );

		// Create a stub request with 5 currencies.
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'currencies' => array(
					$this->make_currency( 'EUR' ),
					$this->make_currency( 'GBP' ),
					$this->make_currency( 'JPY' ),
					$this->make_currency( 'CHF' ),
					$this->make_currency( 'CAD' ),
				),
			)
		);

		$response = $api->save_currencies( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertCount( 2, $data['currencies'] );
		$this->assertSame( 'EUR', $data['currencies'][0]['code'] );
		$this->assertSame( 'GBP', $data['currencies'][1]['code'] );
	}

	/**
	 * Test that get_public_rates returns a proper structure.
	 *
	 * @return void
	 */
	public function test_get_public_rates_returns_structure(): void {
		$api = $this->create_api(
			array(
				$this->make_currency( 'EUR', 0.85 ),
				$this->make_currency( 'GBP', 0.73, false ),
			)
		);

		$response = $api->get_public_rates();
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'base', $data );
		$this->assertArrayHasKey( 'rates', $data );
		$this->assertSame( 'USD', $data['base'] );
		// Only enabled currencies are returned.
		$this->assertCount( 1, $data['rates'] );
		$this->assertArrayHasKey( 'EUR', $data['rates'] );
	}

	/**
	 * Test that sync_rates returns a structured response.
	 *
	 * Since RateProvider is final and Doctrine Instantiator requires PHP 8.3+
	 * for createMock, we test the failure path (no HTTP available in unit tests).
	 * The rate provider returns empty when no API is reachable, triggering
	 * the error response — this verifies the method runs end-to-end.
	 *
	 * @return void
	 */
	public function test_sync_rates_calls_provider(): void {
		$store = new CurrencyStore();
		$store->set_data(
			'USD',
			array(
				$this->make_currency( 'EUR', 0.85 ),
			)
		);

		$converter     = new Converter( $store );
		$rate_provider = new RateProvider();

		$api      = new RestAPI( $store, $converter, $rate_provider );
		$response = $api->sync_rates();
		$data     = $response->get_data();

		// In unit test context (no HTTP), fetch_rates returns empty,
		// so sync_rates returns a 500 error response.
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'message', $data );
	}
}
