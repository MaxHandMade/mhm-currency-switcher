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
}
