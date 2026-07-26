<?php
/**
 * Integration tests: the woocommerce_get_variation_prices_hash filter
 * (PriceFilter::add_currency_to_hash) correctly keys WooCommerce's
 * variation-prices transient by currency.
 *
 * This is the regression lock the cache-friendly switcher project (Plan C)
 * depends on: Plan C is going to add more context to this same hash, and
 * these tests are what prove that change did not silently break currency
 * separation or turn the cache unstable.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

/**
 * Class VariationPricesTest
 */
class VariationPricesTest extends MhmcsIntegrationTestCase {

	/**
	 * Changing the visitor's currency must change the min/max of the
	 * variation-prices range WooCommerce returns -- proof that the hash
	 * WooCommerce keys its transient by actually includes the currency, so
	 * a currency switch cannot read back another currency's cached range.
	 *
	 * @return void
	 */
	public function test_changing_currency_changes_the_variation_price_range(): void {
		$built      = $this->create_variable_product( array( 50.0, 100.0 ) );
		$product_id = $built['product']->get_id();

		$this->clear_visitor_currency();
		$base_prices = wc_get_product( $product_id )->get_variation_prices();
		$base_range  = array_map( 'floatval', array_values( $base_prices['price'] ) );

		$this->assertEqualsWithDelta( 50.0, min( $base_range ), 0.001, 'Base-currency range minimum must be unconverted.' );
		$this->assertEqualsWithDelta( 100.0, max( $base_range ), 0.001, 'Base-currency range maximum must be unconverted.' );

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		// A brand-new WC_Product_Variable instance: no per-object price
		// cache to fall back on, so this can only return the correct
		// converted range if the transient itself was correctly keyed by
		// currency and therefore missed (recomputed), not reused from the
		// base-currency request above.
		$eur_prices = wc_get_product( $product_id )->get_variation_prices();
		$eur_range  = array_map( 'floatval', array_values( $eur_prices['price'] ) );

		$this->assertEqualsWithDelta(
			100.0,
			min( $eur_range ),
			0.001,
			'Switching currency must change the range minimum (50 * 2.0), not reuse the base-currency cache.'
		);
		$this->assertEqualsWithDelta(
			200.0,
			max( $eur_range ),
			0.001,
			'Switching currency must change the range maximum (100 * 2.0), not reuse the base-currency cache.'
		);
	}

	/**
	 * Requesting the same currency twice must reuse WooCommerce's
	 * transient rather than recompute the range from scratch each time.
	 *
	 * Recomputation is detected via a spy on woocommerce_variation_prices_price:
	 * WooCommerce only invokes that filter while building a fresh range; a
	 * transient cache hit returns the stored array without touching it.
	 *
	 * The spy is registered ONCE, before either call. WooCommerce's own
	 * price-hash generator (WC_Product_Variable_Data_Store_CPT::get_price_hash())
	 * folds the *registered callbacks* on this exact filter into the hash
	 * it caches by -- so adding or removing a filter between the two calls
	 * would itself change the hash and force a cache miss, which would
	 * make this test detect its own instrumentation instead of the
	 * currency-caching behaviour it is meant to lock down.
	 *
	 * @return void
	 */
	public function test_same_currency_reuses_the_cached_variation_price_range(): void {
		$built      = $this->create_variable_product( array( 50.0, 100.0 ) );
		$product_id = $built['product']->get_id();

		$recompute_count = 0;
		$spy             = static function ( $price ) use ( &$recompute_count ) {
			++$recompute_count;
			return $price;
		};
		add_filter( 'woocommerce_variation_prices_price', $spy, 999 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		$first = wc_get_product( $product_id )->get_variation_prices();
		$this->assertGreaterThan( 0, $recompute_count, 'Sanity check: the very first call for a new hash must actually compute (spy must fire).' );

		$recompute_count = 0;

		// Fresh instance again, same currency, same registered filters: if
		// the hash is stable, this must be a transient hit -- the spy must
		// not fire again.
		$second = wc_get_product( $product_id )->get_variation_prices();

		remove_filter( 'woocommerce_variation_prices_price', $spy, 999 );

		$this->assertSame(
			0,
			$recompute_count,
			'Same-currency requests must reuse the cached transient, not recompute every variation price again.'
		);
		$this->assertEquals( $first, $second, 'A cache hit must return the same range that was originally computed.' );
	}
}
