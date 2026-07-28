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
}
