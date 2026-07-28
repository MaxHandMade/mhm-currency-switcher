<?php
/**
 * Unit tests for Converter.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use PHPUnit\Framework\TestCase;

/**
 * Class ConverterTest
 *
 * Pure unit tests — no WordPress dependency.
 * CurrencyStore is populated via set_data() with TRY as base currency.
 *
 * Currencies:
 *   USD: rate=0.03, fee=percentage 2%, rounding=disabled
 *   EUR: rate=0.025, fee=fixed 0.001, rounding=nearest value=1.0 subtract=0.01
 *
 * @covers \MhmCurrencySwitcher\Core\Converter
 */
class ConverterTest extends TestCase {

	/**
	 * Converter instance under test.
	 *
	 * @var Converter
	 */
	private Converter $converter;

	/**
	 * Set up the converter with a pre-configured CurrencyStore.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$store = new CurrencyStore();
		$store->set_data(
			'TRY',
			array(
				array(
					'code'            => 'USD',
					'enabled'         => true,
					'sort_order'      => 0,
					'rate'            => array(
						'type'  => 'manual',
						'value' => 0.03,
					),
					'fee'             => array(
						'type'  => 'percentage',
						'value' => 2,
					),
					'rounding'        => array(
						'type'     => 'disabled',
						'value'    => 0,
						'subtract' => 0,
					),
					'format'          => array(
						'symbol'       => '$',
						'position'     => 'left',
						'thousand_sep' => ',',
						'decimal_sep'  => '.',
						'decimals'     => 2,
					),
				),
				array(
					'code'            => 'EUR',
					'enabled'         => true,
					'sort_order'      => 1,
					'rate'            => array(
						'type'  => 'manual',
						'value' => 0.025,
					),
					'fee'             => array(
						'type'  => 'fixed',
						'value' => 0.001,
					),
					'rounding'        => array(
						'type'     => 'nearest',
						'value'    => 1.0,
						'subtract' => 0.01,
					),
					'format'          => array(
						'symbol'       => "\u{20AC}",
						'position'     => 'right',
						'thousand_sep' => '.',
						'decimal_sep'  => ',',
						'decimals'     => 2,
					),
				),
			)
		);

		$this->converter = new Converter( $store );
	}

	/**
	 * Test basic conversion: 1000 TRY -> USD with percentage fee.
	 *
	 * 1000 * 0.03 * 1.02 = 30.6
	 *
	 * @return void
	 */
	public function test_convert_basic(): void {
		$result = $this->converter->convert( 1000.0, 'USD' );

		$this->assertEqualsWithDelta( 30.6, $result, 0.001 );
	}

	/**
	 * Test conversion with fixed fee: 1000 TRY -> EUR.
	 *
	 * 1000 * (0.025 + 0.001) = 26.0
	 *
	 * @return void
	 */
	public function test_convert_with_fixed_fee(): void {
		$result = $this->converter->convert( 1000.0, 'EUR' );

		$this->assertEqualsWithDelta( 26.0, $result, 0.001 );
	}

	/**
	 * Test that converting to the base currency returns the original price.
	 *
	 * 1000 TRY -> TRY = 1000.0
	 *
	 * @return void
	 */
	public function test_convert_base_currency_returns_original(): void {
		$result = $this->converter->convert( 1000.0, 'TRY' );

		$this->assertEqualsWithDelta( 1000.0, $result, 0.001 );
	}

	/**
	 * Test that converting a zero price returns zero.
	 *
	 * @return void
	 */
	public function test_convert_zero_price_returns_zero(): void {
		$result = $this->converter->convert( 0.0, 'USD' );

		$this->assertEqualsWithDelta( 0.0, $result, 0.001 );
	}

	/**
	 * Test that converting a negative price returns the original.
	 *
	 * @return void
	 */
	public function test_convert_negative_price_returns_original(): void {
		$result = $this->converter->convert( -100.0, 'USD' );

		$this->assertEqualsWithDelta( -100.0, $result, 0.001 );
	}

	/**
	 * Test that converting to an unknown currency returns the original price.
	 *
	 * @return void
	 */
	public function test_convert_unknown_currency_returns_original(): void {
		$result = $this->converter->convert( 1000.0, 'XYZ' );

		$this->assertEqualsWithDelta( 1000.0, $result, 0.001 );
	}

	/**
	 * Test conversion with rounding: 1000 TRY -> EUR.
	 *
	 * Converted: 26.0, round nearest 1.0 = 26.0, subtract 0.01 = 25.99
	 *
	 * @return void
	 */
	public function test_convert_with_rounding_nearest(): void {
		$result = $this->converter->convert_with_rounding( 1000.0, 'EUR' );

		$this->assertEqualsWithDelta( 25.99, $result, 0.001 );
	}

	/**
	 * Test effective rate with percentage fee.
	 *
	 * USD: 0.03 * 1.02 = 0.0306
	 *
	 * @return void
	 */
	public function test_get_effective_rate_with_percentage(): void {
		$rate = $this->converter->get_rate( 'USD' );

		$this->assertEqualsWithDelta( 0.0306, $rate, 0.001 );
	}

	/**
	 * Test raw rate without fee.
	 *
	 * USD: 0.03
	 *
	 * @return void
	 */
	public function test_get_raw_rate(): void {
		$rate = $this->converter->get_raw_rate( 'USD' );

		$this->assertEqualsWithDelta( 0.03, $rate, 0.001 );
	}

	/**
	 * Test reverting a converted price back to base currency.
	 *
	 * 30.6 USD / 0.0306 = 1000 TRY
	 *
	 * @return void
	 */
	public function test_revert_price(): void {
		$result = $this->converter->revert( 30.6, 'USD' );

		$this->assertEqualsWithDelta( 1000.0, $result, 0.001 );
	}

	/**
	 * Build a converter over a single currency with the given rate and fee.
	 *
	 * @param float  $rate     Raw rate value.
	 * @param string $fee_type Fee type.
	 * @param float  $fee      Fee value.
	 * @return Converter Converter over a TRY-based store offering GBP.
	 */
	private function converter_for_gbp( float $rate, string $fee_type = 'none', float $fee = 0.0 ): Converter {
		$store = new CurrencyStore();
		$store->set_data(
			'TRY',
			array(
				array(
					'code'    => 'GBP',
					'enabled' => true,
					'rate'    => array(
						'type'  => 'manual',
						'value' => $rate,
					),
					'fee'     => array(
						'type'  => $fee_type,
						'value' => $fee,
					),
				),
			)
		);

		return new Converter( $store );
	}

	/**
	 * A currency with no rate is not usable.
	 *
	 * @return void
	 */
	public function test_has_usable_rate_is_false_without_a_rate(): void {
		$this->assertFalse( $this->converter_for_gbp( 0.0 )->has_usable_rate( 'GBP' ) );
	}

	/**
	 * 🔴 A fee cannot manufacture a rate.
	 *
	 * The old guard asked whether the EFFECTIVE rate was zero, and a fixed fee
	 * is added to the raw rate — so a currency with no rate at all and a fee of
	 * 2 converted every price at "rate 2", a number that came from nowhere. The
	 * question has to be asked of the raw rate.
	 *
	 * @return void
	 */
	public function test_a_fee_does_not_make_a_rateless_currency_usable(): void {
		$converter = $this->converter_for_gbp( 0.0, 'fixed', 2.0 );

		$this->assertFalse( $converter->has_usable_rate( 'GBP' ) );
		$this->assertEqualsWithDelta( 100.0, $converter->convert( 100.0, 'GBP' ), 0.001 );
	}

	/**
	 * A negative rate is not usable either.
	 *
	 * @return void
	 */
	public function test_has_usable_rate_is_false_for_a_negative_rate(): void {
		$converter = $this->converter_for_gbp( -1.5 );

		$this->assertFalse( $converter->has_usable_rate( 'GBP' ) );
		$this->assertEqualsWithDelta( 100.0, $converter->convert( 100.0, 'GBP' ), 0.001 );
	}

	/**
	 * An unknown currency is not usable.
	 *
	 * @return void
	 */
	public function test_has_usable_rate_is_false_for_an_unknown_currency(): void {
		$this->assertFalse( $this->converter->has_usable_rate( 'XYZ' ) );
	}

	/**
	 * A configured currency is usable and converts.
	 *
	 * @return void
	 */
	public function test_has_usable_rate_is_true_for_a_configured_currency(): void {
		$converter = $this->converter_for_gbp( 0.02 );

		$this->assertTrue( $converter->has_usable_rate( 'GBP' ) );
		$this->assertEqualsWithDelta( 2.0, $converter->convert( 100.0, 'GBP' ), 0.001 );
	}

	/**
	 * The base currency is always usable — no rate is involved in showing it.
	 *
	 * @return void
	 */
	public function test_base_currency_is_usable(): void {
		$this->assertTrue( $this->converter->has_usable_rate( 'TRY' ) );
	}

	/**
	 * A fee with no stated type is no fee.
	 *
	 * Every writer of this field says `none` when nothing was chosen — the REST
	 * sanitiser's default, its fallback for an unrecognised value, and the admin
	 * UI's initial state. get_rate() was the only reader and it assumed `fixed`,
	 * so a stored currency missing the key had a fee silently added to its rate.
	 *
	 * @return void
	 */
	public function test_a_fee_without_a_type_is_not_applied(): void {
		$store = new CurrencyStore();
		$store->set_data(
			'TRY',
			array(
				array(
					'code'    => 'GBP',
					'enabled' => true,
					'rate'    => array(
						'type'  => 'manual',
						'value' => 0.02,
					),
					'fee'     => array( 'value' => 5.0 ),
				),
			)
		);

		$converter = new Converter( $store );

		$this->assertEqualsWithDelta( 0.02, $converter->get_rate( 'GBP' ), 0.0001 );
		$this->assertEqualsWithDelta( 2.0, $converter->convert( 100.0, 'GBP' ), 0.001 );
	}
}
