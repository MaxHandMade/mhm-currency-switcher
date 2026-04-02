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
			'payment_methods' => array( 'all' ),
			'countries'       => array(),
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
	 * Test that get_currencies returns an array.
	 *
	 * @return void
	 */
	public function test_get_currencies_returns_array(): void {
		$currencies = array(
			$this->make_currency( 'USD' ),
			$this->make_currency( 'EUR' ),
		);

		$store = new CurrencyStore();
		$store->set_data( 'USD', $currencies );

		$result = $store->get_currencies();

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
	}

	/**
	 * Test that get_enabled_currencies filters out disabled currencies.
	 *
	 * @return void
	 */
	public function test_get_enabled_currencies_filters_disabled(): void {
		$currencies = array(
			$this->make_currency( 'USD', true ),
			$this->make_currency( 'EUR', false ),
		);

		$store = new CurrencyStore();
		$store->set_data( 'USD', $currencies );

		$enabled = $store->get_enabled_currencies();

		$this->assertCount( 1, $enabled );
		$this->assertSame( 'USD', $enabled[0]['code'] );
	}

	/**
	 * Test that get_currency returns a matching currency array.
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

		$result = $store->get_currency( 'USD' );

		$this->assertIsArray( $result );
		$this->assertSame( 'USD', $result['code'] );
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
	 * Test that enforce_limit slices currencies to the free-tier limit.
	 *
	 * @return void
	 */
	public function test_currency_count_enforced_in_free(): void {
		$currencies = array(
			$this->make_currency( 'EUR' ),
			$this->make_currency( 'GBP' ),
			$this->make_currency( 'TRY' ),
		);

		$store = new CurrencyStore();
		$store->set_free_limit( 2 );

		$limited = $store->enforce_limit( $currencies );

		$this->assertCount( 2, $limited );
		$this->assertSame( 'EUR', $limited[0]['code'] );
		$this->assertSame( 'GBP', $limited[1]['code'] );
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
			$this->make_currency( 'JPY' ),
		);

		$store = new CurrencyStore();
		$store->set_data( 'JPY', $currencies );

		// If loaded flag were false, these calls would try load() which
		// calls get_option() — that function does not exist in unit tests
		// and would throw a fatal error.  Success here proves loaded=true.
		$this->assertSame( 'JPY', $store->get_base_currency() );
		$this->assertCount( 1, $store->get_currencies() );
		$this->assertSame( 'JPY', $store->get_currencies()[0]['code'] );
	}
}
