<?php
/**
 * Unit tests for the per-product fixed price sanitiser.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Integration\WooCommerce
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Integration\WooCommerce;

use MhmCurrencySwitcher\Integration\WooCommerce\ProductPricing;
use PHPUnit\Framework\TestCase;

/**
 * Class ProductPricingTest
 *
 * The panel's numeric fields were hardened in 1.3.1. This is the same class of
 * value arriving through the other door — the product editor — where the two
 * save paths held their own copy of the rule and asked only `is_numeric()`.
 *
 * @covers \MhmCurrencySwitcher\Integration\WooCommerce\ProductPricing
 */
class ProductPricingTest extends TestCase {

	/**
	 * 🔴 A price too large to represent must not be stored, because storing it
	 * makes the product FREE.
	 *
	 * Measured, not reasoned: `is_numeric('1e309')` is true, `floatval()` of it
	 * is `INF`, `(string) INF` is the literal `"INF"`, and reading that back
	 * with `(float) "INF"` gives `0.0` — `"INF"` is not a numeric string. So the
	 * stored fixed price becomes zero and `PriceFilter::convert_price()` returns
	 * it ahead of any exchange rate. The shop sells the product for nothing in
	 * that currency, and every gate stays green because a zero is a perfectly
	 * valid float.
	 *
	 * @return void
	 */
	public function test_a_price_too_large_to_store_is_rejected_rather_than_stored_as_zero(): void {
		$this->assertSame(
			array(),
			ProductPricing::sanitize_fixed_price_map( array( 'EUR' => '1e309' ) ),
			'A value that cannot survive the round trip must not be stored at all.'
		);
	}

	/**
	 * A negative fixed price is refused.
	 *
	 * Nothing downstream neutralises it: `get_fixed_price()` returns it and
	 * `PriceFilter` hands it to `woocommerce_product_get_price`, so it reaches
	 * the cart as a negative line total.
	 *
	 * @return void
	 */
	public function test_a_negative_price_is_rejected(): void {
		$this->assertSame(
			array(),
			ProductPricing::sanitize_fixed_price_map( array( 'EUR' => '-5' ) )
		);
	}

	/**
	 * Negative control — the behaviour that must SURVIVE the fix.
	 *
	 * A comma decimal separator is what a shop owner in most of Europe types,
	 * and the existing code converts it before storing. A hardening pass that
	 * quietly dropped this would be a worse regression than the defect it
	 * closed, and would look identical from the two assertions above.
	 *
	 * @return void
	 */
	public function test_a_comma_decimal_separator_is_still_accepted(): void {
		$this->assertSame(
			array( 'EUR' => '10.5' ),
			ProductPricing::sanitize_fixed_price_map( array( 'EUR' => '10,5' ) )
		);
	}

	/**
	 * Negative control: ordinary values, an ignored bad currency code, and an
	 * empty field that means "no fixed price".
	 *
	 * @return void
	 */
	public function test_ordinary_values_are_kept_and_non_prices_are_ignored(): void {
		$this->assertSame(
			array(
				'EUR' => '25',
				'USD' => '0',
			),
			ProductPricing::sanitize_fixed_price_map(
				array(
					'EUR'   => '25',
					'USD'   => '0',
					'eur'   => '9',
					'EURO'  => '9',
					'GBP'   => '',
					'CHF'   => 'abc',
				)
			)
		);
	}
}
