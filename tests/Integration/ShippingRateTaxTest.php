<?php
/**
 * Integration test: a real WC_Shipping_Rate gets both its cost AND its tax
 * lines converted.
 *
 * 🔴 This class exists because the unit suite proved the opposite of the truth.
 * WC_Shipping_Rate keeps its fields in a protected $data array behind
 * __get/__set, so `$rate->taxes[ $id ] = $x` is an indirect modification of an
 * overloaded property: PHP applies it to a temporary copy and the rate is
 * unchanged. The unit test used a stdClass, which has real properties and
 * accepted the write, so it went green against code that did nothing at all in
 * production — the shipping tax stayed a base-currency amount in a converted
 * cart, and a browser round found it.
 *
 * A stub can only be as strict as the thing it imitates. This one uses the real
 * class, so no imitation can drift from it.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use MhmCurrencySwitcher\Core\ConversionContext;
use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;
use MhmCurrencySwitcher\Integration\WooCommerce\ShippingFilter;
use WC_Shipping_Rate;

/**
 * Class ShippingRateTaxTest
 */
class ShippingRateTaxTest extends MhmcsIntegrationTestCase {

	/**
	 * Convert one rate through a filter wired to a EUR-at-0.5 shop.
	 *
	 * A rate of exactly one half keeps every expected number obvious: a cost of
	 * 100 becomes 50, a tax of 10 becomes 5.
	 *
	 * @param float                   $cost  Rate cost in the base currency.
	 * @param array<string|int,float> $taxes Tax lines in the base currency.
	 * @return WC_Shipping_Rate The rate after the filter has run.
	 */
	private function convert_rate( float $cost, array $taxes ): WC_Shipping_Rate {
		$this->configure_currency(
			array(
				'code'     => 'EUR',
				'enabled'  => true,
				'rate'     => array(
					'type'  => 'manual',
					'value' => 0.5,
				),
				'fee'      => array(
					'type'  => 'none',
					'value' => 0,
				),
				'rounding' => array(
					'type'     => 'disabled',
					'value'    => 0,
					'subtract' => 0,
				),
			)
		);

		$this->set_visitor_currency( 'EUR' );

		$store     = new CurrencyStore();
		$context   = new ConversionContext();
		$filter    = new ShippingFilter(
			new Converter( $store ),
			new DetectionService( $store, $context ),
			$context
		);

		$rate = new WC_Shipping_Rate( 'flat_rate:1', 'Flat rate', $cost, $taxes, 'flat_rate', 1 );

		$result = $filter->convert_shipping_rates( array( 'flat_rate:1' => $rate ), array() );

		return $result['flat_rate:1'];
	}

	/**
	 * The tax lines follow the cost into the visitor's currency.
	 *
	 * @return void
	 */
	public function test_a_real_shipping_rate_has_its_taxes_converted(): void {
		$rate = $this->convert_rate( 100.0, array( 1 => 10.0 ) );

		$this->assertEqualsWithDelta( 50.0, (float) $rate->get_cost(), 0.001 );

		$taxes = $rate->get_taxes();

		$this->assertEqualsWithDelta(
			5.0,
			(float) $taxes[1],
			0.001,
			'The tax line must be converted; leaving it behind charges base-currency tax on a converted shipping cost.'
		);
	}

	/**
	 * Several tax lines are all converted, and their keys are preserved.
	 *
	 * @return void
	 */
	public function test_every_tax_line_is_converted(): void {
		$rate = $this->convert_rate(
			100.0,
			array(
				1 => 10.0,
				4 => 6.0,
			)
		);

		$taxes = $rate->get_taxes();

		$this->assertSame( array( 1, 4 ), array_keys( $taxes ) );
		$this->assertEqualsWithDelta( 5.0, (float) $taxes[1], 0.001 );
		$this->assertEqualsWithDelta( 3.0, (float) $taxes[4], 0.001 );
	}

	/**
	 * A rate with no tax lines comes back with none.
	 *
	 * @return void
	 */
	public function test_a_rate_without_taxes_stays_without_taxes(): void {
		$rate = $this->convert_rate( 100.0, array() );

		$this->assertEqualsWithDelta( 50.0, (float) $rate->get_cost(), 0.001 );
		$this->assertSame( array(), $rate->get_taxes() );
	}

	/**
	 * 🔴 Free shipping must stay free.
	 *
	 * `convert_shipping_rates()` sends `$rate->cost` through
	 * `convert_with_rounding()` with nothing in between, and a free shipping
	 * method's cost really is `0.00`. Under a rounding rule that subtracts —
	 * "nearest 1, minus 0.01", ordinary psychological pricing — zero rounded to
	 * zero and then had a penny taken off it, so the shipping line came out at
	 * **-0.01** and WooCommerce added that straight into the cart total.
	 *
	 * Free shipping is one of the most widely used configurations in
	 * WooCommerce, which makes this the busiest member of the rounding-floor
	 * class and the one the class's first fix missed: the guard it shipped with
	 * only covered amounts strictly above zero.
	 *
	 * The suite's own convert_rate() helper uses rate 0.5 with no rounding, so
	 * this test configures the rounding rule it needs itself.
	 *
	 * @return void
	 */
	public function test_free_shipping_stays_free(): void {
		$this->configure_currency(
			array(
				'code'     => 'EUR',
				'enabled'  => true,
				'rate'     => array(
					'type'  => 'manual',
					'value' => 0.5,
				),
				'fee'      => array(
					'type'  => 'none',
					'value' => 0,
				),
				'rounding' => array(
					'type'     => 'nearest',
					'value'    => 1.0,
					'subtract' => 0.01,
				),
			)
		);

		$this->set_visitor_currency( 'EUR' );

		$store   = new CurrencyStore();
		$context = new ConversionContext();
		$filter  = new ShippingFilter(
			new Converter( $store ),
			new DetectionService( $store, $context ),
			$context
		);

		$rate   = new WC_Shipping_Rate( 'free_shipping:1', 'Free shipping', 0.0, array(), 'free_shipping', 1 );
		$result = $filter->convert_shipping_rates( array( 'free_shipping:1' => $rate ), array() );

		$this->assertSame(
			0.0,
			(float) $result['free_shipping:1']->get_cost(),
			'Free shipping came out at a negative cost, and WooCommerce adds that to the cart total as it finds it.'
		);
	}

	/**
	 * A negative tax line — a tax adjustment or reversal — converts by the
	 * same rate as a positive one.
	 *
	 * `Converter::convert()` returns any amount `<= 0` unchanged, which is the
	 * correct rule for a price and the wrong one for a tax line. This is the
	 * second member of the class found in the cart-fee sweep: the fee was the
	 * common case, this is the same early return one filter along. It is rare,
	 * which is exactly why it would have sat here unnoticed after the reported
	 * case was fixed.
	 *
	 * @return void
	 */
	public function test_a_negative_tax_line_is_converted_like_a_positive_one(): void {
		$rate = $this->convert_rate(
			100.0,
			array(
				1 => -10.0,
				4 => 6.0,
			)
		);

		$taxes = $rate->get_taxes();

		$this->assertEqualsWithDelta(
			-5.0,
			(float) $taxes[1],
			0.001,
			'A negative tax line kept its base-currency magnitude while the cost beside it was converted.'
		);
		$this->assertEqualsWithDelta(
			3.0,
			(float) $taxes[4],
			0.001,
			'The positive tax line alongside it must be unaffected by the fix.'
		);
	}
}
