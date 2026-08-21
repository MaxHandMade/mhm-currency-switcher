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
use MhmCurrencySwitcher\Core\LegacyOptionMigrator;
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
			'code'       => $code,
			'enabled'    => $enabled,
			'sort_order' => 0,
			'rate'       => array(
				'type'  => 'auto',
				'value' => $rate,
			),
			'fee'        => array(
				'type'  => 'fixed',
				'value' => 0,
			),
			'rounding'   => array(
				'type'     => 'disabled',
				'value'    => 0,
				'subtract' => 0,
			),
			'format'     => array(
				'symbol'       => $code,
				'position'     => 'left',
				'thousand_sep' => ',',
				'decimal_sep'  => '.',
				'decimals'     => 2,
			),
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
	 * Start every test from an empty option store and a request that has
	 * not fired `wp` yet.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['__mhmcs_test_options']     = array();
		$GLOBALS['__mhmcs_test_did_actions'] = array();
	}

	/**
	 * Leave no options or fired actions behind for neighbouring tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['__mhmcs_test_options']     = array();
		$GLOBALS['__mhmcs_test_did_actions'] = array();

		unset( $GLOBALS['__mhmcs_test_logged_in'], $GLOBALS['__mhmcs_test_is_admin'] );

		parent::tearDown();
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
	 * Adding a currency must not inherit whatever symbol the display
	 * filters happen to be returning.
	 *
	 * 🔴 Found by running the plugin on a real shop, not by a gate. The
	 * panel sends `format: {}` for a newly added currency and the server
	 * seeds the symbol from `get_woocommerce_currency_symbol( $code )` —
	 * which is a DISPLAY helper that runs the `woocommerce_currency_symbol`
	 * filter. Every currency plugin hooks that filter to answer with the
	 * currency the visitor is looking at, this one included. Measured on a
	 * live TRY shop that also had YayCurrency installed: the helper
	 * returned the Lira sign for USD, EUR, GBP and JPY alike, so adding USD
	 * through the panel stored a USD currency that prints Lira signs.
	 *
	 * The damage is permanent: the format is seeded once when the currency
	 * is added and there is no symbol field anywhere in the panel, so a
	 * shop owner cannot correct it without editing the database.
	 *
	 * @return void
	 */
	public function test_a_new_currency_does_not_inherit_the_filtered_display_symbol(): void {
		// What a third-party currency plugin's filter does to every code.
		$GLOBALS['__mhmcs_test_symbol_filter'] = "\u{20BA}";

		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'TRY',
				'currencies'    => array(
					array_merge(
						$this->make_currency( 'USD', 0.025 ),
						array( 'format' => array() )
					),
				),
			)
		);

		$data = $api->save_currencies( $request )->get_data();

		unset( $GLOBALS['__mhmcs_test_symbol_filter'] );

		$this->assertSame(
			'$',
			$data['currencies'][0]['format']['symbol'],
			'A USD currency was stored with the symbol the display filters were returning, not the dollar sign.'
		);
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
	 * Rounding config saved from the UI must round-trip intact.
	 *
	 * @return void
	 */
	public function test_save_currencies_round_trips_rounding(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array_merge(
						$this->make_currency( 'EUR', 0.92 ),
						array(
							'rounding' => array(
								'type'     => 'nearest',
								'value'    => 1.0,
								'subtract' => 0.01,
							),
						)
					),
				),
			)
		);

		$saved = $api->save_currencies( $request )->get_data()['currencies'][0]['rounding'];

		$this->assertSame( 'nearest', $saved['type'] );
		$this->assertSame( 1.0, $saved['value'] );
		$this->assertSame( 0.01, $saved['subtract'] );
	}

	/**
	 * `rate.updated_at` is RateProvider::apply_rates()'s per-row sync stamp,
	 * and the panel now reads it to decide whether a SPECIFIC row's number
	 * came from a sync — the global `mhmcs_rates_last_sync` option can only
	 * answer that for the batch as a whole. A save must carry the stamp
	 * through unchanged, or every edit to an already-synced row would erase
	 * the one fact that made its "updated Xh ago" text honest.
	 *
	 * @return void
	 */
	public function test_save_currencies_round_trips_rate_updated_at(): void {
		$api = $this->create_api();

		$currency = $this->make_currency( 'EUR', 0.92 );
		$currency['rate']['updated_at'] = 1700000000;

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array( $currency ),
			)
		);

		$saved = $api->save_currencies( $request )->get_data()['currencies'][0]['rate'];

		$this->assertSame( 1700000000, $saved['updated_at'] );
	}

	/**
	 * A negative or non-numeric `updated_at` must not survive as submitted —
	 * `absint()` guards the TYPE of an already-present stamp (its real
	 * contract is "clamp to a non-negative integer", not "zero anything
	 * suspicious"), it does not invent one. Distinct from the "absent stays
	 * absent" case below: this currency arrives WITH the field, carrying a
	 * value nothing legitimate would ever produce.
	 *
	 * @return void
	 */
	public function test_save_currencies_sanitizes_invalid_rate_updated_at(): void {
		$api = $this->create_api();

		$currency                       = $this->make_currency( 'EUR', 0.92 );
		$currency['rate']['updated_at'] = '-42';

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array( $currency ),
			)
		);

		$saved = $api->save_currencies( $request )->get_data()['currencies'][0]['rate'];

		$this->assertSame( 42, $saved['updated_at'] );
	}

	/**
	 * A currency saved without `rate.updated_at` — a brand-new row, or one
	 * that has never been through a sync — must not have one invented for
	 * it. `save_currencies()` never syncs anything; only a real sync via
	 * RateProvider::apply_rates() may set this field for the first time.
	 *
	 * @return void
	 */
	public function test_save_currencies_does_not_invent_rate_updated_at(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array( $this->make_currency( 'EUR', 0.92 ) ),
			)
		);

		$saved = $api->save_currencies( $request )->get_data()['currencies'][0]['rate'];

		$this->assertArrayNotHasKey( 'updated_at', $saved );
	}

	/**
	 * Currency configs must no longer carry the dead payment_methods
	 * field (the per-currency gateway restriction feature never existed).
	 *
	 * @return void
	 */
	public function test_save_currencies_drops_payment_methods(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array_merge(
						$this->make_currency( 'EUR', 0.85 ),
						array( 'payment_methods' => array( 'stripe' ) )
					),
				),
			)
		);

		$data = $api->save_currencies( $request )->get_data();

		$this->assertArrayNotHasKey( 'payment_methods', $data['currencies'][0] );
	}

	/**
	 * The dead per-currency countries field must not be persisted.
	 *
	 * @return void
	 */
	public function test_save_currencies_drops_countries(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array_merge(
						$this->make_currency( 'EUR', 0.85 ),
						array( 'countries' => array( 'DE', 'FR' ) )
					),
				),
			)
		);

		$data = $api->save_currencies( $request )->get_data();

		$this->assertArrayNotHasKey( 'countries', $data['currencies'][0] );
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

	/**
	 * Switcher display settings must round-trip through the sanitiser.
	 *
	 * @return void
	 */
	public function test_save_settings_persists_switcher_display_options(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'switcher' => array(
					'show_flag'   => false,
					'show_name'   => true,
					'show_symbol' => false,
					'show_code'   => true,
					'size'        => 'large',
				),
			)
		);

		$saved = $api->save_settings( $request )->get_data()['settings']['switcher'];

		$this->assertFalse( $saved['show_flag'] );
		$this->assertTrue( $saved['show_name'] );
		$this->assertFalse( $saved['show_symbol'] );
		$this->assertTrue( $saved['show_code'] );
		$this->assertSame( 'large', $saved['size'] );
	}

	/**
	 * An invalid size must fall back to medium rather than reaching the
	 * CSS class name unchecked.
	 *
	 * @return void
	 */
	public function test_save_settings_rejects_invalid_switcher_size(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array( 'switcher' => array( 'size' => '"><script>' ) )
		);

		$saved = $api->save_settings( $request )->get_data()['settings']['switcher'];

		$this->assertSame( 'medium', $saved['size'] );
	}

	/**
	 * The "max 5" limit lived only in the browser (`val.slice( 0, 5 )`), so a
	 * sixth code posted by anything else was stored and rendered.
	 *
	 * @return void
	 */
	public function test_the_product_widget_currency_list_is_capped_on_the_server(): void {
		$api     = $this->create_api();
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'product_widget' => array(
					'currencies' => array( 'EUR', 'TRY', 'GBP', 'JPY', 'CHF', 'SEK' ),
				),
			)
		);

		$response = $api->save_settings( $request )->get_data();

		$this->assertCount( 5, $response['settings']['product_widget']['currencies'] );
		$this->assertSame( 'widget_currencies_too_many', $response['adjustments'][0]['reason'] );
	}

	// ─── cache_compat — the three write sites (design spec §9D) ──────

	/**
	 * Absolute path of a file inside the plugin root.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	private function plugin_file( string $relative ): string {
		return dirname( __DIR__, 3 ) . '/' . $relative;
	}

	/**
	 * Parse the controls declared by an admin-app tab component.
	 *
	 * Returns one entry per Toggle/Select control that writes a setting,
	 * carrying the control type, the key name it writes and every literal
	 * option value it can send. This is what makes the drift lock
	 * mechanical instead of a promise to be careful.
	 *
	 * @param string $jsx JSX source.
	 * @return array<int, array{type: string, key: string, values: array<int, string>}>
	 */
	private function parse_jsx_controls( string $jsx ): array {
		$blocks = preg_split(
			'/<(ToggleControl|SelectControl)\b/',
			$jsx,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		$controls = array();
		$total    = is_array( $blocks ) ? count( $blocks ) : 0;

		for ( $i = 1; $i < $total; $i += 2 ) {
			$type = $blocks[ $i ];
			$body = $blocks[ $i + 1 ] ?? '';

			if ( 1 !== preg_match( "/update\(\s*'([A-Za-z0-9_]+)'/", $body, $key_match ) ) {
				continue;
			}

			preg_match_all( "/value:\s*'([^']*)'/", $body, $value_match );

			$controls[] = array(
				'type'   => $type,
				'key'    => $key_match[1],
				'values' => $value_match[1],
			);
		}

		return $controls;
	}

	/**
	 * 🔴 Drift lock. Every key the Advanced Settings tab writes must be
	 * accepted by the sanitiser under the SAME spelling, and every literal
	 * value it can send must survive the sanitiser unchanged.
	 *
	 * Two real production bugs in this plugin were exactly this drift:
	 * the key name (`show_flag` written / `show_flags` expected — a toggle
	 * that silently did nothing) and the value enum (`percent` written /
	 * `percentage` expected — the effective rate became 0.92 + 2 = 2.92
	 * and customers were charged roughly three times the price).
	 *
	 * @return void
	 */
	public function test_advanced_settings_jsx_keys_and_values_match_the_sanitiser(): void {
		$jsx = file_get_contents( $this->plugin_file( 'admin-app/src/components/tabs/AdvancedSettings.jsx' ) );

		$this->assertIsString( $jsx, 'AdvancedSettings.jsx must be readable.' );

		$controls = $this->parse_jsx_controls( $jsx );

		$this->assertNotEmpty( $controls, 'No settings controls parsed out of AdvancedSettings.jsx.' );

		$keys = array_column( $controls, 'key' );

		$this->assertContains(
			'cache_compat',
			$keys,
			'AdvancedSettings.jsx must write the cache_compat key, spelled exactly as ConversionContext reads it.'
		);

		foreach ( $controls as $control ) {
			$key = $control['key'];

			if ( 'ToggleControl' === $control['type'] ) {
				foreach ( array( true, false ) as $sent ) {
					$api     = $this->create_api();
					$request = new \WP_REST_Request();
					$request->set_json_params( array( $key => $sent ) );

					$saved = $api->save_settings( $request )->get_data()['settings'];

					$this->assertArrayHasKey(
						$key,
						$saved,
						sprintf( 'The sanitiser drops "%s", which AdvancedSettings.jsx writes.', $key )
					);
					$this->assertSame(
						$sent,
						$saved[ $key ],
						sprintf( 'Toggle "%s" must round-trip as a real boolean.', $key )
					);
				}

				continue;
			}

			$this->assertNotEmpty(
				$control['values'],
				sprintf( 'SelectControl "%s" declares no literal option values to lock.', $key )
			);

			foreach ( $control['values'] as $sent ) {
				$api     = $this->create_api();
				$request = new \WP_REST_Request();
				$request->set_json_params( array( $key => $sent ) );

				$saved = $api->save_settings( $request )->get_data()['settings'];

				$this->assertSame(
					$sent,
					$saved[ $key ] ?? null,
					sprintf(
						'Value "%s" offered by the "%s" control does not survive the sanitiser verbatim.',
						$sent,
						$key
					)
				);
			}
		}
	}

	/**
	 * The sanitiser must persist cache_compat as a real boolean in both
	 * directions — `false` is a value, not an absent key.
	 *
	 * @return void
	 */
	public function test_save_settings_persists_cache_compat_as_boolean(): void {
		foreach ( array( true, false ) as $sent ) {
			$api     = $this->create_api();
			$request = new \WP_REST_Request();
			$request->set_json_params( array( 'cache_compat' => $sent ) );

			$saved = $api->save_settings( $request )->get_data()['settings'];

			$this->assertArrayHasKey( 'cache_compat', $saved );
			$this->assertSame( $sent, $saved['cache_compat'] );
			$this->assertSame( $sent, get_option( 'mhmcs_settings' )['cache_compat'] );
		}
	}

	/**
	 * Third write site: the activation defaults. A key that is missing
	 * here is reborn as "absent" on every clean install.
	 *
	 * The defaults used to be an inline array in the bootstrap file and
	 * this test matched it as source text. They now live in
	 * `LegacyOptionMigrator::default_settings()`, because the upgrade path
	 * has to seed exactly the same thing and a second hand-written copy is
	 * how the two would drift. So the assertion is in two halves: the value
	 * itself, read from the one definition, and the wiring that proves
	 * activation still goes through it. Asserting only the first would pass
	 * on a bootstrap that had quietly stopped calling it.
	 *
	 * @return void
	 */
	public function test_activation_defaults_seed_cache_compat_true(): void {
		$this->assertTrue(
			LegacyOptionMigrator::default_settings()['cache_compat'],
			'The seeded defaults must switch cache_compat on (design spec Task 4).'
		);

		$plugin = file_get_contents( $this->plugin_file( 'mhm-currency-switcher.php' ) );

		$this->assertIsString( $plugin, 'The plugin bootstrap file must be readable.' );

		$this->assertSame(
			1,
			preg_match(
				'/update_option\(\s*\'mhmcs_settings\',\s*\\\\?[\\\\\w]*LegacyOptionMigrator::default_settings\(\)/',
				$plugin
			),
			'Activation must seed mhmcs_settings from LegacyOptionMigrator::default_settings().'
		);
	}

	/**
	 * 🔴 Round trip. A setting that saves but is never read is the same
	 * dead control the audit spent a whole task removing: save it through
	 * the REST sanitiser, then prove ConversionContext decision 4 sees it.
	 *
	 * @return void
	 */
	public function test_saved_cache_compat_reaches_conversion_context_decision_4(): void {
		$GLOBALS['__mhmcs_test_did_actions']['wp'] = 1;

		// Never written: the default is ON, so a catalogue view stays base.
		$this->assertFalse(
			( new \MhmCurrencySwitcher\Core\ConversionContext() )->should_convert(),
			'With no stored setting, cache compatibility must default to ON.'
		);

		$api     = $this->create_api();
		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'cache_compat' => false ) );
		$api->save_settings( $request );

		$this->assertTrue(
			( new \MhmCurrencySwitcher\Core\ConversionContext() )->should_convert(),
			'Saving cache_compat = false must make decision 4 convert server-side.'
		);

		$api     = $this->create_api();
		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'cache_compat' => true ) );
		$api->save_settings( $request );

		$this->assertFalse(
			( new \MhmCurrencySwitcher\Core\ConversionContext() )->should_convert(),
			'Saving cache_compat = true must leave a catalogue view in the base currency.'
		);
	}

	/**
	 * 🔴 A space is a real thousand separator — `1 234,56` is the stock French
	 * and Russian grouping and WooCommerce accepts it — and
	 * `sanitize_text_field()` deletes it, because it collapses whitespace runs
	 * and then trims. That happens BEFORE any clamp could report it, so the
	 * shop owner would type a space, be told the save succeeded, and watch the
	 * storefront print ungrouped numbers with nothing to explain it.
	 *
	 * @return void
	 */
	public function test_a_space_survives_as_a_thousand_separator(): void {
		$api     = $this->create_api();
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array(
						'code'   => 'EUR',
						'format' => array( 'thousand_sep' => ' ', 'decimal_sep' => ',', 'decimals' => 2 ),
					),
				),
			)
		);

		$saved = $api->save_currencies( $request )->get_data()['currencies'];

		$this->assertSame( ' ', $saved[0]['format']['thousand_sep'] );
	}

	/**
	 * Separators that are equal render `1.234.56`, which nobody can read. The
	 * thousand separator is the one that goes, and the shop owner is told.
	 *
	 * @return void
	 */
	public function test_equal_separators_are_reported_not_silently_kept(): void {
		$api     = $this->create_api();
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array(
						'code'   => 'EUR',
						'format' => array( 'thousand_sep' => '.', 'decimal_sep' => '.', 'decimals' => 2 ),
					),
				),
			)
		);

		$response = $api->save_currencies( $request )->get_data();

		$this->assertSame( '', $response['currencies'][0]['format']['thousand_sep'] );
		$this->assertSame(
			array(
				array(
					'code'   => 'EUR',
					'field'  => 'thousand_sep',
					'reason' => 'separators_equal',
					'value'  => '',
				),
			),
			$response['adjustments'],
			'The clamp fired but the response did not say so — a silent clamp is the "control that '
				. 'lies" class this panel has spent three rounds removing.'
		);
	}

	/**
	 * `absint()` accepts any magnitude, and the value reaches number_format().
	 * ISO 4217 defines no minor unit larger than four.
	 *
	 * @return void
	 */
	public function test_decimals_are_clamped_to_four_and_reported(): void {
		$api     = $this->create_api();
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array( 'code' => 'EUR', 'format' => array( 'decimals' => 40 ) ),
				),
			)
		);

		$response = $api->save_currencies( $request )->get_data();

		$this->assertSame( 4, $response['currencies'][0]['format']['decimals'] );
		$this->assertSame( 'decimals_out_of_range', $response['adjustments'][0]['reason'] );
	}

	/**
	 * A multibyte separator must not be cut in half. U+00A0 and U+202F are two
	 * bytes and three bytes respectively; a byte-wise truncation yields invalid
	 * UTF-8, not a separator.
	 *
	 * @return void
	 */
	public function test_a_multibyte_separator_survives_intact(): void {
		$api     = $this->create_api();
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array(
						'code'   => 'EUR',
						'format' => array( 'thousand_sep' => "\u{202F}", 'decimal_sep' => ',' ),
					),
				),
			)
		);

		$saved = $api->save_currencies( $request )->get_data()['currencies'];

		$this->assertSame( "\u{202F}", $saved[0]['format']['thousand_sep'] );
	}

	/**
	 * An empty decimal separator with decimals to show would print `123456`
	 * for 1234.56. It falls back to WooCommerce's, and says so.
	 *
	 * @return void
	 */
	public function test_an_empty_decimal_separator_falls_back_when_decimals_are_shown(): void {
		$api     = $this->create_api();
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array( 'code' => 'EUR', 'format' => array( 'decimal_sep' => '', 'decimals' => 2 ) ),
				),
			)
		);

		$response = $api->save_currencies( $request )->get_data();

		$this->assertNotSame( '', $response['currencies'][0]['format']['decimal_sep'] );
		$this->assertSame( 'decimal_sep_empty', $response['adjustments'][0]['reason'] );
	}

	/**
	 * A submission longer than one character is truncated to the first
	 * character, and that truncation is reported — sanitize_separator()'s
	 * own business, independent of any of the decimal/thousand collision
	 * rules.
	 *
	 * @return void
	 */
	public function test_a_multi_character_separator_is_truncated_and_reported(): void {
		$api     = $this->create_api();
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array( 'code' => 'EUR', 'format' => array( 'thousand_sep' => ',;' ) ),
				),
			)
		);

		$response = $api->save_currencies( $request )->get_data();

		$this->assertSame( ',', $response['currencies'][0]['format']['thousand_sep'] );
		$this->assertSame(
			array(
				array(
					'code'   => 'EUR',
					'field'  => 'thousand_sep',
					'reason' => 'separator_truncated',
					'value'  => ',',
				),
			),
			$response['adjustments']
		);
	}

	/**
	 * A submission that is non-empty but sanitises down to nothing (here, a
	 * bare tab — a control character stripped before truncation ever runs)
	 * is invalid, not empty. Using thousand_sep, which carries no "must not
	 * be empty" rule of its own, isolates this from the decimal_sep_empty
	 * fallback so only sanitize_separator()'s own rule fires.
	 *
	 * @return void
	 */
	public function test_a_submission_that_sanitises_to_nothing_is_reported_invalid(): void {
		$api     = $this->create_api();
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array( 'code' => 'EUR', 'format' => array( 'thousand_sep' => "\t" ) ),
				),
			)
		);

		$response = $api->save_currencies( $request )->get_data();

		$this->assertSame( '', $response['currencies'][0]['format']['thousand_sep'] );
		$this->assertSame(
			array(
				array(
					'code'   => 'EUR',
					'field'  => 'thousand_sep',
					'reason' => 'separator_invalid',
					'value'  => '',
				),
			),
			$response['adjustments']
		);
	}

	/**
	 * `absint( 'abc' )` silently yields 0 decimals. A non-numeric submission
	 * must fall back to the standard default and say so, rather than
	 * pretending the shop owner asked for no decimals at all.
	 *
	 * @return void
	 */
	public function test_a_non_numeric_decimals_submission_falls_back_and_is_reported(): void {
		$api     = $this->create_api();
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array( 'code' => 'EUR', 'format' => array( 'decimals' => 'abc' ) ),
				),
			)
		);

		$response = $api->save_currencies( $request )->get_data();

		$this->assertSame( 2, $response['currencies'][0]['format']['decimals'] );
		$this->assertSame(
			array(
				array(
					'code'   => 'EUR',
					'field'  => 'decimals',
					'reason' => 'decimals_invalid',
					'value'  => 2,
				),
			),
			$response['adjustments']
		);
	}

	/**
	 * `absint( -3 )` would silently flip the sign to 3 — a number the shop
	 * owner never expressed. A negative submission clamps to 0 (the nearest
	 * valid bound) instead of inventing a magnitude, and shares the
	 * `decimals_out_of_range` reason with the > 4 case because both mean
	 * "the number you typed was not usable as submitted."
	 *
	 * @return void
	 */
	public function test_a_negative_decimals_submission_clamps_to_zero_and_is_reported(): void {
		$api     = $this->create_api();
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array( 'code' => 'EUR', 'format' => array( 'decimals' => -3 ) ),
				),
			)
		);

		$response = $api->save_currencies( $request )->get_data();

		$this->assertSame( 0, $response['currencies'][0]['format']['decimals'] );
		$this->assertSame(
			array(
				array(
					'code'   => 'EUR',
					'field'  => 'decimals',
					'reason' => 'decimals_out_of_range',
					'value'  => 0,
				),
			),
			$response['adjustments']
		);
	}

	/**
	 * 🔴 Regression for the exact bug the reviewer measured: a sign-flip
	 * design reported `decimals_out_of_range` twice for `-10` — once from
	 * clamping the sign (naming 10, a value never stored) and again from the
	 * `> 4` clamp (naming 4, the value actually stored). Resolving decimals
	 * to its final value before emitting anything makes exactly one
	 * adjustment fire, and it must name the value that was actually stored.
	 *
	 * @return void
	 */
	public function test_a_very_negative_decimals_submission_emits_one_adjustment_naming_the_stored_value(): void {
		$api     = $this->create_api();
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array( 'code' => 'EUR', 'format' => array( 'decimals' => -10 ) ),
				),
			)
		);

		$response = $api->save_currencies( $request )->get_data();

		$this->assertSame( 0, $response['currencies'][0]['format']['decimals'] );
		$this->assertSame(
			array(
				array(
					'code'   => 'EUR',
					'field'  => 'decimals',
					'reason' => 'decimals_out_of_range',
					'value'  => 0,
				),
			),
			$response['adjustments']
		);
	}

	/**
	 * 🔴 Regression for the exact bug the reviewer measured: submitting
	 * `decimal_sep=''` with `thousand_sep='.'` used to fill decimal_sep from
	 * the WooCommerce default ('.'), collide with the just-submitted
	 * thousand_sep, and blank thousand_sep — turning "1.234,56" into
	 * "1234.56" from a fallback nobody asked for. The fallback must pick the
	 * complementary separator instead of colliding with what was submitted.
	 *
	 * @return void
	 */
	public function test_the_empty_decimal_fallback_does_not_collide_with_a_submitted_thousand_sep(): void {
		$api     = $this->create_api();
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array(
						'code'   => 'EUR',
						'format' => array( 'decimal_sep' => '', 'thousand_sep' => '.', 'decimals' => 2 ),
					),
				),
			)
		);

		$response = $api->save_currencies( $request )->get_data();
		$saved    = $response['currencies'][0]['format'];

		$this->assertSame( ',', $saved['decimal_sep'] );
		$this->assertSame( '.', $saved['thousand_sep'] );
		$this->assertSame(
			array(
				array(
					'code'   => 'EUR',
					'field'  => 'decimal_sep',
					'reason' => 'decimal_sep_empty',
					'value'  => ',',
				),
			),
			$response['adjustments']
		);
	}

	/**
	 * 🔴 The most ordinary European submission there is: a shop owner sets
	 * only decimal_sep to ',' and leaves thousand_sep untouched. It defaults
	 * to WooCommerce's ',' too, collides with the submitted decimal_sep, and
	 * must yield — but silently. A shop owner who never touched
	 * thousand_sep must not be told it changed.
	 *
	 * @return void
	 */
	public function test_a_defaulted_thousand_sep_yields_to_a_submitted_decimal_sep_silently(): void {
		$api     = $this->create_api();
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array( 'code' => 'EUR', 'format' => array( 'decimal_sep' => ',' ) ),
				),
			)
		);

		$response = $api->save_currencies( $request )->get_data();
		$saved    = $response['currencies'][0]['format'];

		$this->assertSame( ',', $saved['decimal_sep'] );
		$this->assertSame( '', $saved['thousand_sep'] );
		$this->assertSame( array(), $response['adjustments'] );
	}

	/**
	 * 🔴 The mirror of the previous test, and the exact case the re-reviewer
	 * measured against a one-sided fix: only `thousand_sep => '.'` is
	 * submitted, `decimal_sep` is left unset entirely (not submitted empty —
	 * genuinely absent). Before this fix, decimal_sep defaulted straight to
	 * WooCommerce's '.' with no collision check, collided with the
	 * submitted thousand_sep, and the equal-separators rule blanked
	 * thousand_sep — the shop owner's own real submission — and blamed it in
	 * the report. decimal_sep must pick the complementary separator instead,
	 * and thousand_sep must survive untouched with nothing reported.
	 *
	 * @return void
	 */
	public function test_a_defaulted_decimal_sep_does_not_collide_with_a_submitted_thousand_sep(): void {
		$api     = $this->create_api();
		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array( 'code' => 'EUR', 'format' => array( 'thousand_sep' => '.' ) ),
				),
			)
		);

		$response = $api->save_currencies( $request )->get_data();
		$saved    = $response['currencies'][0]['format'];

		$this->assertSame( ',', $saved['decimal_sep'] );
		$this->assertSame( '.', $saved['thousand_sep'] );
		$this->assertSame( array(), $response['adjustments'] );
	}

	/**
	 * 🔴 The preview computes; it must never persist. A handler that saved
	 * would turn every keystroke in the panel into a write, and an admin
	 * experimenting with a rate would find the experiment stored.
	 *
	 * @return void
	 */
	public function test_the_preview_endpoint_writes_nothing(): void {
		$api = $this->create_api();

		$before_currencies = get_option( 'mhmcs_currencies', false );
		$before_settings   = get_option( 'mhmcs_settings', false );

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array(
						'code' => 'EUR',
						'rate' => array( 'type' => 'manual', 'value' => 0.9 ),
					),
				),
			)
		);

		$api->preview_rates( $request );

		$this->assertSame( $before_currencies, get_option( 'mhmcs_currencies', false ) );
		$this->assertSame( $before_settings, get_option( 'mhmcs_settings', false ) );
	}

	/**
	 * The submitted configuration, not the saved one, is what the strip shows.
	 *
	 * @return void
	 */
	public function test_the_preview_computes_from_the_submitted_rows(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array(
						'code' => 'EUR',
						'rate' => array( 'type' => 'manual', 'value' => 0.5 ),
						'fee'  => array( 'type' => 'none', 'value' => 0 ),
					),
				),
			)
		);

		$data = $api->preview_rates( $request )->get_data();

		$this->assertSame( 0.5, $data['rates'][0]['raw_rate'] );
		$this->assertTrue( $data['rates'][0]['usable'] );
		$this->assertNotSame( '', $data['rates'][0]['sample_to'] );
	}

	/**
	 * A base the store cannot honour is refused rather than quietly ignored.
	 *
	 * CurrencyStore::get_base_currency() returns the live woocommerce_currency
	 * option whenever it is set, so a differing base in the body would be
	 * accepted and then not used — the caller would get confidently wrong
	 * numbers.
	 *
	 * @return void
	 */
	public function test_a_body_whose_base_differs_from_the_shop_is_refused(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array( 'base_currency' => 'JPY', 'currencies' => array() )
		);

		$this->assertSame( 400, $api->preview_rates( $request )->get_status() );
	}

	/**
	 * A bounded amount of work per request.
	 *
	 * @return void
	 */
	public function test_an_oversized_currency_list_is_refused(): void {
		$api  = $this->create_api();
		$rows = array_fill( 0, 101, array( 'code' => 'EUR' ) );

		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'base_currency' => 'USD', 'currencies' => $rows ) );

		$this->assertSame( 400, $api->preview_rates( $request )->get_status() );
	}

	/**
	 * 🔴 Pins the arithmetic behind sample_to, not just its non-emptiness.
	 * Swapping `convert_with_rounding( PREVIEW_AMOUNT, $code )` for the bare
	 * base amount would leave this field non-empty -- the exact "base amount
	 * under a foreign symbol" defect FormatFilter exists to stop, silently
	 * reintroduced through the preview endpoint -- and
	 * `test_the_preview_computes_from_the_submitted_rows`'s
	 * `assertNotSame( '', ... )` cannot see it.
	 *
	 * 100 (PREVIEW_AMOUNT) * 0.5 (manual rate, no fee, rounding disabled) =
	 * 50. Under the unit stub, `wc_price()` cannot see PreviewRenderer's own
	 * filter overrides (see tests/bootstrap.php's `wc_price()` stub comment),
	 * so it renders as the plain WooCommerce symbol table's EUR entry plus
	 * `number_format()` at the global default of 2 decimals.
	 *
	 * @return void
	 */
	public function test_the_preview_sample_to_reflects_the_converted_amount(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array(
						'code'     => 'EUR',
						'rate'     => array( 'type' => 'manual', 'value' => 0.5 ),
						'fee'      => array( 'type' => 'none', 'value' => 0 ),
						'rounding' => array( 'type' => 'disabled', 'value' => 0, 'subtract' => 0 ),
					),
				),
			)
		);

		$data = $api->preview_rates( $request )->get_data();

		$this->assertSame( '€50.00', $data['rates'][0]['sample_to'] );
	}

	/**
	 * 🔴 The unusable branch, pinned on both halves. A row with no rate has
	 * no honest sample: the converter hands the base amount back unchanged,
	 * and dressing that number in a foreign symbol is the defect
	 * FormatFilter exists to stop. Asserting only `usable` would leave the
	 * `sample_to` half of that ternary free to always render.
	 *
	 * @return void
	 */
	public function test_the_preview_marks_a_rateless_row_unusable_with_no_sample(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array(
						'code' => 'EUR',
						'rate' => array( 'type' => 'manual', 'value' => 0 ),
					),
				),
			)
		);

		$data = $api->preview_rates( $request )->get_data();

		$this->assertFalse( $data['rates'][0]['usable'] );
		$this->assertSame( '', $data['rates'][0]['sample_to'] );
	}

	/**
	 * The brief's headline claim ("both methods return" the same shape) held
	 * only by construction -- GET and POST both delegate to build_preview().
	 * A future refactor that split the two builders could drift silently.
	 * Pinned at both levels: the envelope keys, and one row's keys, since a
	 * row-shape drift would not show up in the top-level comparison alone.
	 *
	 * @return void
	 */
	public function test_get_and_post_preview_share_one_response_shape(): void {
		$currency = $this->make_currency( 'EUR', 0.5 );
		$api      = $this->create_api( array( $currency ), 'USD' );

		$get_data = $api->get_rates_preview()->get_data();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array( $currency ),
			)
		);
		$post_data = $api->preview_rates( $request )->get_data();

		$this->assertSame( array_keys( $get_data ), array_keys( $post_data ) );
		$this->assertNotEmpty( $get_data['rates'] );
		$this->assertNotEmpty( $post_data['rates'] );
		$this->assertSame( array_keys( $get_data['rates'][0] ), array_keys( $post_data['rates'][0] ) );
	}

	/**
	 * The brief required the same row cap on both handlers that accept a
	 * currency list; `test_an_oversized_currency_list_is_refused` only
	 * exercised `preview_rates()`, leaving `save_currencies()` unguarded.
	 *
	 * @return void
	 */
	public function test_an_oversized_currency_list_is_refused_by_save_currencies(): void {
		$api  = $this->create_api();
		$rows = array_fill( 0, 101, array( 'code' => 'EUR' ) );

		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'base_currency' => 'USD', 'currencies' => $rows ) );

		$this->assertSame( 400, $api->save_currencies( $request )->get_status() );
	}
}
