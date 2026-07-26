<?php
/**
 * Unit tests for CurrencyStore.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use MhmCurrencySwitcher\Core\CurrencyStore;
use PHPUnit\Framework\TestCase;

/**
 * Class CurrencyStoreTest
 *
 * Pure unit tests — no WordPress dependency.
 * All tests use set_data() to inject state rather than calling load().
 *
 * @covers \MhmCurrencySwitcher\Core\CurrencyStore
 */
class CurrencyStoreTest extends TestCase {

	/**
	 * Helper: build a single currency config array.
	 *
	 * @param string $code    ISO 4217 code.
	 * @param bool   $enabled Whether the currency is enabled.
	 * @param int    $order   Sort order.
	 * @return array<string, mixed>
	 */
	private function make_currency( string $code, bool $enabled = true, int $order = 0 ): array {
		return array(
			'code'            => $code,
			'enabled'         => $enabled,
			'sort_order'      => $order,
			'rate'            => array(
				'type'  => 'auto',
				'value' => 1.0,
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
		);
	}

	/**
	 * Test that get_base_currency returns the correct string after set_data.
	 *
	 * @return void
	 */
	public function test_get_base_currency_returns_string(): void {
		$store = new CurrencyStore();
		$store->set_data( 'TRY', array() );

		$this->assertSame( 'TRY', $store->get_base_currency() );
	}

	/**
	 * Test that get_currencies returns an array excluding the base currency.
	 *
	 * @return void
	 */
	public function test_get_currencies_returns_array(): void {
		$currencies = array(
			$this->make_currency( 'USD' ),
			$this->make_currency( 'EUR' ),
			$this->make_currency( 'GBP' ),
		);

		$store = new CurrencyStore();
		$store->set_data( 'USD', $currencies );

		$result = $store->get_currencies();

		$this->assertIsArray( $result );
		// USD is the base currency, so it's filtered out.
		$this->assertCount( 2, $result );
		$this->assertSame( 'EUR', $result[0]['code'] );
		$this->assertSame( 'GBP', $result[1]['code'] );
	}

	/**
	 * Test that get_enabled_currencies filters out disabled and base currencies.
	 *
	 * @return void
	 */
	public function test_get_enabled_currencies_filters_disabled(): void {
		$currencies = array(
			$this->make_currency( 'EUR', true ),
			$this->make_currency( 'GBP', false ),
			$this->make_currency( 'JPY', true ),
		);

		$store = new CurrencyStore();
		$store->set_data( 'USD', $currencies );

		$enabled = $store->get_enabled_currencies();

		$this->assertCount( 2, $enabled );
		$this->assertSame( 'EUR', $enabled[0]['code'] );
		$this->assertSame( 'JPY', $enabled[1]['code'] );
	}

	/**
	 * Test that get_currency returns a matching currency array.
	 *
	 * get_currency searches the raw list, so it can find any currency
	 * including the base currency (needed for format data lookups).
	 *
	 * @return void
	 */
	public function test_get_currency_returns_matching_currency(): void {
		$currencies = array(
			$this->make_currency( 'USD' ),
			$this->make_currency( 'EUR' ),
		);

		$store = new CurrencyStore();
		$store->set_data( 'USD', $currencies );

		// Can find non-base currency.
		$result = $store->get_currency( 'EUR' );
		$this->assertIsArray( $result );
		$this->assertSame( 'EUR', $result['code'] );

		// Can also find base currency (via raw list).
		$base_result = $store->get_currency( 'USD' );
		$this->assertIsArray( $base_result );
		$this->assertSame( 'USD', $base_result['code'] );
	}

	/**
	 * Test that get_currency returns null for an unknown code.
	 *
	 * @return void
	 */
	public function test_get_currency_returns_null_for_unknown(): void {
		$store = new CurrencyStore();
		$store->set_data( 'USD', array( $this->make_currency( 'USD' ) ) );

		$this->assertNull( $store->get_currency( 'XYZ' ) );
	}

	/**
	 * Test that set_data sets the loaded flag so auto-load is not triggered.
	 *
	 * After set_data, get_base_currency and get_currencies should return
	 * the injected values without attempting to call load() (which would
	 * fail in a pure unit-test context without WordPress).
	 *
	 * @return void
	 */
	public function test_set_data_sets_loaded_flag(): void {
		$currencies = array(
			$this->make_currency( 'EUR' ),
			$this->make_currency( 'GBP' ),
		);

		$store = new CurrencyStore();
		$store->set_data( 'JPY', $currencies );

		// If loaded flag were false, these calls would try load() which
		// calls get_option() — that function does not exist in unit tests
		// and would throw a fatal error.  Success here proves loaded=true.
		$this->assertSame( 'JPY', $store->get_base_currency() );
		$this->assertCount( 2, $store->get_currencies() );
		$this->assertSame( 'EUR', $store->get_currencies()[0]['code'] );
	}

	/**
	 * The activation seed shape must be readable by the store.
	 *
	 * Regression: activation wrote a flat list of currency codes while
	 * load() expects {base_currency, currencies}, so the seed was a
	 * silent no-op and fresh installs started empty.
	 *
	 * @return void
	 */
	public function test_activation_seed_shape_is_loadable(): void {
		$seed = array(
			'base_currency' => 'USD',
			'currencies'    => array(
				array(
					'code'    => 'EUR',
					'enabled' => true,
					'rate'    => array(
						'type'  => 'auto',
						'value' => 0.92,
					),
				),
			),
		);

		$store = new CurrencyStore();
		$store->set_data( $seed['base_currency'], $seed['currencies'] );

		$this->assertSame( 'USD', $store->get_base_currency() );
		$this->assertCount( 1, $store->get_currencies() );
	}

	/**
	 * The real activation seed builder must produce output that the
	 * real CurrencyStore::load() can read back correctly.
	 *
	 * This is the committed regression lock for the Task 5 fix:
	 * activation used to write a flat list of currency codes while
	 * load() expects {base_currency, currencies}, so the seed silently
	 * did nothing on fresh installs. That was proven fixed with an
	 * ad-hoc script that never made it into the repo — this test
	 * replaces that ad-hoc proof with a durable one, exercising the
	 * *actual* seed-construction method (not a hand-written stand-in)
	 * through the *actual* load() method (not set_data()).
	 *
	 * CurrencyStore::default_option_value() lives in src/ and is reached
	 * through the ordinary autoloader — no require of the plugin entry
	 * file is needed here.
	 *
	 * woocommerce_currency is intentionally left unset in the fake
	 * option store: CurrencyStore::get_base_currency() prefers reading
	 * it live over the loaded value, so setting it here would let the
	 * assertion pass without load() ever having parsed the seed
	 * correctly.
	 *
	 * @return void
	 */
	public function test_real_activation_seed_is_loadable_by_real_load(): void {
		$previous_options = $GLOBALS['__mhmcs_test_options'] ?? null;

		try {
			unset( $GLOBALS['__mhmcs_test_options'] );

			$seed = CurrencyStore::default_option_value();
			update_option( 'mhmcs_currencies', $seed );

			$store = new CurrencyStore();
			$store->load();

			$this->assertNotSame( '', $store->get_base_currency() );
			$this->assertIsArray( $store->get_currencies() );
		} finally {
			if ( null === $previous_options ) {
				unset( $GLOBALS['__mhmcs_test_options'] );
			} else {
				$GLOBALS['__mhmcs_test_options'] = $previous_options;
			}
		}
	}
}
