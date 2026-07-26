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

	/**
	 * Test that save_currencies() sanitizes a malicious format.symbol
	 * on input (defense-in-depth hardening).
	 *
	 * @return void
	 */
	public function test_save_currencies_sanitizes_malicious_format_symbol(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array_merge(
						$this->make_currency( 'EUR', 0.85 ),
						array(
							'format' => array(
								'symbol'       => '<script>USD',
								'position'     => 'left',
								'thousand_sep' => ',',
								'decimal_sep'  => '.',
								'decimals'     => 2,
							),
						)
					),
				),
			)
		);

		$response = $api->save_currencies( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertTrue( $data['success'] );
		$this->assertCount( 1, $data['currencies'] );

		$saved_symbol = $data['currencies'][0]['format']['symbol'];

		$this->assertSame( 'USD', $saved_symbol );
		$this->assertStringNotContainsString( '<script>', $saved_symbol );
	}

	/**
	 * Test that save_currencies() does NOT downgrade a legitimate
	 * 'left_space' format.position to 'left' (regression: the position
	 * allowlist previously only accepted 'left'/'right', silently
	 * corrupting stores whose WooCommerce currency position is
	 * 'left_space' or 'right_space').
	 *
	 * @return void
	 */
	public function test_save_currencies_preserves_left_space_position(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array_merge(
						$this->make_currency( 'EUR', 0.85 ),
						array(
							'format' => array(
								'symbol'       => '€',
								'position'     => 'left_space',
								'thousand_sep' => ',',
								'decimal_sep'  => '.',
								'decimals'     => 2,
							),
						)
					),
				),
			)
		);

		$response = $api->save_currencies( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertTrue( $data['success'] );
		$this->assertCount( 1, $data['currencies'] );

		$saved_position = $data['currencies'][0]['format']['position'];

		$this->assertSame( 'left_space', $saved_position );
	}

	/**
	 * Helper: save one currency with the given fee config and return the
	 * stored currency array as the sanitiser produced it.
	 *
	 * @param array<string, mixed> $fee  Fee config exactly as the admin UI sends it.
	 * @param float                $rate Exchange rate value.
	 * @return array<string, mixed> Saved currency config.
	 */
	private function save_currency_with_fee( array $fee, float $rate = 0.92 ): array {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array_merge(
						$this->make_currency( 'EUR', $rate ),
						array( 'fee' => $fee )
					),
				),
			)
		);

		$data = $api->save_currencies( $request )->get_data();

		return $data['currencies'][0];
	}

	/**
	 * The admin UI sends `percent` as the fee type. It must survive
	 * sanitisation as a percentage fee.
	 *
	 * Regression: the sanitiser only accepted `fixed`/`percentage` and
	 * silently coerced everything else to `fixed`, so the percentage fee
	 * option could never be stored.
	 *
	 * @return void
	 */
	public function test_save_currencies_keeps_ui_percent_fee_as_percentage(): void {
		$saved = $this->save_currency_with_fee(
			array(
				'type'  => 'percent',
				'value' => 2,
			)
		);

		$this->assertSame( 'percentage', $saved['fee']['type'] );
		$this->assertSame( 2.0, $saved['fee']['value'] );
	}

	/**
	 * A percentage fee must multiply the rate, never be added to it.
	 *
	 * Regression: `percent` was coerced to `fixed`, so a 2% fee on a 0.92
	 * rate produced an effective rate of 2.92 (~3x prices) and that rate
	 * fed the cart, i.e. customers were charged the wrong amount.
	 *
	 * @return void
	 */
	public function test_ui_percent_fee_multiplies_the_rate(): void {
		$saved = $this->save_currency_with_fee(
			array(
				'type'  => 'percent',
				'value' => 2,
			),
			0.92
		);

		$store = new CurrencyStore();
		$store->set_data( 'USD', array( $saved ) );

		$converter = new Converter( $store );

		$this->assertEqualsWithDelta( 0.9384, $converter->get_rate( 'EUR' ), 0.00001 );
	}

	/**
	 * "No fee" must round-trip as `none`, so the admin dropdown shows the
	 * option that was actually chosen when the settings are reloaded.
	 *
	 * @return void
	 */
	public function test_save_currencies_round_trips_none_fee_type(): void {
		$saved = $this->save_currency_with_fee(
			array(
				'type'  => 'none',
				'value' => 0,
			)
		);

		$this->assertSame( 'none', $saved['fee']['type'] );
	}

	/**
	 * Selecting "No fee" must neutralise the fee even when a value from a
	 * previous selection is still present in the payload.
	 *
	 * Regression: `none` was coerced to `fixed` while keeping the stale
	 * value, so turning the fee off silently kept charging it.
	 *
	 * @return void
	 */
	public function test_save_currencies_neutralises_none_fee_type(): void {
		$saved = $this->save_currency_with_fee(
			array(
				'type'  => 'none',
				'value' => 2,
			),
			0.92
		);

		$store = new CurrencyStore();
		$store->set_data( 'USD', array( $saved ) );

		$converter = new Converter( $store );

		$this->assertEqualsWithDelta( 0.92, $converter->get_rate( 'EUR' ), 0.00001 );
	}

	/**
	 * Removed settings must not be persisted when a stale client still
	 * sends them.
	 *
	 * @return void
	 */
	public function test_save_settings_drops_removed_keys(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'provider'             => 'openexchangerates',
				'provider_api_key'     => 'secret-key',
				'cache_duration'       => 3600,
				'round_prices'         => true,
				'multilingual_mapping' => array( 'tr_TR' => 'Türk Lirası' ),
				'auto_detect'          => true,
			)
		);

		$settings = $api->save_settings( $request )->get_data()['settings'];

		$this->assertArrayNotHasKey( 'provider', $settings );
		$this->assertArrayNotHasKey( 'provider_api_key', $settings );
		$this->assertArrayNotHasKey( 'cache_duration', $settings );
		$this->assertArrayNotHasKey( 'round_prices', $settings );
		$this->assertArrayNotHasKey( 'multilingual_mapping', $settings );
		$this->assertTrue( $settings['auto_detect'] );
	}

	/**
	 * Keys already stored from an earlier version must be purged, not
	 * carried forward by array_merge — provider_api_key is a secret the
	 * user typed and there is no longer anything that reads it.
	 *
	 * @return void
	 */
	public function test_save_settings_purges_previously_stored_dead_keys(): void {
		update_option(
			'mhmcs_settings',
			array(
				'provider'         => 'currencylayer',
				'provider_api_key' => 'left-over-secret',
				'cache_duration'   => 3600,
				'auto_detect'      => false,
			)
		);

		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'auto_detect' => true ) );

		$settings = $api->save_settings( $request )->get_data()['settings'];

		$this->assertArrayNotHasKey( 'provider', $settings );
		$this->assertArrayNotHasKey( 'provider_api_key', $settings );
		$this->assertArrayNotHasKey( 'cache_duration', $settings );
		$this->assertSame( array(), array_intersect( RestAPI::LEGACY_SETTING_KEYS, array_keys( get_option( 'mhmcs_settings' ) ) ) );
	}
}
