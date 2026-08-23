<?php
/**
 * A variable product's advertised range must follow the rate, not the cache.
 *
 * WooCommerce stores the range in the `wc_var_prices_{id}` transient, keyed by
 * a hash this plugin contributes to, and the transient lives for up to 30 days.
 * Nothing here bumps WooCommerce's product transient version when a rate
 * changes — not the hourly sync, not the panel's Save — so if the key does not
 * contain what the amounts depend on, a bucket computed at an old rate keeps
 * matching and keeps being served.
 *
 * That is not hypothetical. It was measured on the dev stack while the key held
 * only the currency code: TRY at an effective 49.0008 priced a simple product
 * correctly while the variable product's range was still coming from a rate of
 * 35.2. A customer reads the range, picks a variation, and is charged 39% above
 * the ceiling they were shown.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

/**
 * Class VariablePriceCacheKeyTest
 */
class VariablePriceCacheKeyTest extends MhmcsIntegrationTestCase {

	private const TARGET = 'TRY';

	/**
	 * Configure one auto-rate currency at a given effective rate.
	 *
	 * Rounding is left disabled so the assertions read as plain multiplication
	 * and a failure points at the rate rather than at a rounding rule.
	 *
	 * @param float $rate Raw exchange rate.
	 * @return void
	 */
	private function set_rate( float $rate ): void {
		$this->configure_currency(
			array(
				'code'     => self::TARGET,
				'enabled'  => true,
				'rate'     => array( 'type' => 'manual', 'value' => $rate ),
				'fee'      => array( 'type' => 'none', 'value' => 0 ),
				'rounding' => array( 'type' => 'disabled', 'value' => 0, 'subtract' => 0 ),
			),
			'USD'
		);
	}

	/**
	 * 🔴 Change the rate and the advertised range must change with it.
	 *
	 * The first read populates the transient. The second read happens after the
	 * rate has moved, with the transient deliberately left in place — which is
	 * exactly the state a shop is in an hour after an automatic sync.
	 *
	 * @return void
	 */
	public function test_the_variable_range_follows_a_rate_change_rather_than_the_cache(): void {
		$product = $this->create_variable_product_with_prices( array( 20.0, 30.0 ) );

		$this->set_visitor_currency( self::TARGET );

		$this->set_rate( 10.0 );

		$first = $this->range_of( $product->get_id() );

		$this->assertSame(
			array( 200.0, 300.0 ),
			$first,
			'The fixture is wrong, not the code: at a rate of 10 a $20-$30 product must advertise 200-300.'
		);

		// No cache clearing here on purpose. If the key is complete, the changed
		// rate produces a different bucket and the old one simply goes unread.
		$this->set_rate( 20.0 );

		$second = $this->range_of( $product->get_id() );

		$this->assertSame(
			array( 400.0, 600.0 ),
			$second,
			'The advertised range was served from the bucket computed at the old rate. '
				. 'A shop that syncs rates hourly advertises one price and charges another, '
				. 'for up to the 30 days that transient lives.'
		);
	}

	/**
	 * The min and max of a variable product's current price range.
	 *
	 * @param int $product_id Product ID.
	 * @return array{0: float, 1: float}
	 */
	private function range_of( int $product_id ): array {
		$product = wc_get_product( $product_id );
		$prices  = $product->get_variation_prices( true );
		$values  = array_map( 'floatval', array_values( $prices['price'] ) );

		sort( $values );

		return array( (float) min( $values ), (float) max( $values ) );
	}

	/**
	 * A variable product with one variation per given price.
	 *
	 * @param array<int, float> $prices Variation prices in the base currency.
	 * @return \WC_Product_Variable
	 */
	private function create_variable_product_with_prices( array $prices ): \WC_Product_Variable {
		$product = new \WC_Product_Variable();
		$product->set_name( 'Range fixture' );

		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array_map( static fn( $i ) => 'v' . $i, array_keys( $prices ) ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$product->set_attributes( array( $attribute ) );
		$product->save();

		foreach ( $prices as $index => $price ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( 'size' => 'v' . $index ) );
			$variation->set_regular_price( (string) $price );
			$variation->save();
		}

		return new \WC_Product_Variable( $product->get_id() );
	}
}
