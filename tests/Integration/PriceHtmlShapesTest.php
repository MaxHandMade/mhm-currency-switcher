<?php
/**
 * Integration tests: price_html shapes carry the converted amount AND the
 * target currency's symbol, for every product shape WooCommerce supports.
 *
 * The unit suite cannot see this class of bug: it calls Converter/Currency
 * classes directly, never through a real WC_Product's get_price_html(),
 * so it can never notice a case where the amount converts correctly but
 * the wrong symbol is shown (or vice versa) -- both halves only line up
 * when PriceFilter and FormatFilter run together against a real product.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use MhmCurrencySwitcher\Integration\WooCommerce\ProductPricing;

/**
 * Class PriceHtmlShapesTest
 */
class PriceHtmlShapesTest extends MhmcsIntegrationTestCase {

	/**
	 * Simple product, no sale: price_html must show the converted price
	 * with the target currency's symbol.
	 *
	 * @return void
	 */
	public function test_simple_product_price_html_shows_converted_amount_and_symbol(): void {
		$product = $this->create_simple_product( 100.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		$html = $product->get_price_html();

		$this->assertStringContainsString(
			self::TARGET_SYMBOL,
			$html,
			'price_html must carry the target currency symbol.'
		);
		$this->assertStringContainsString(
			'200.00',
			$html,
			'price_html must carry the converted amount (100 base * 2.0 rate).'
		);
	}

	/**
	 * On-sale simple product: both the struck-through regular price and
	 * the sale price must be converted, each with the target symbol.
	 *
	 * @return void
	 */
	public function test_on_sale_product_price_html_shows_both_converted_amounts(): void {
		$product = $this->create_simple_product( 100.0, 80.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		$html = $product->get_price_html();

		$this->assertTrue( $product->is_on_sale(), 'Fixture product must actually be on sale for this test to mean anything.' );
		$this->assertStringContainsString( '200.00', $html, 'Struck-through regular price must be converted (100 * 2.0).' );
		$this->assertStringContainsString( '160.00', $html, 'Sale price must be converted (80 * 2.0).' );
		$this->assertGreaterThanOrEqual(
			2,
			substr_count( $html, self::TARGET_SYMBOL ),
			'Both the regular and sale amounts must carry the target symbol.'
		);
	}

	/**
	 * Variable product with two variations: the displayed range's minimum
	 * and maximum must both be converted.
	 *
	 * @return void
	 */
	public function test_variable_product_price_html_shows_converted_range(): void {
		$built   = $this->create_variable_product( array( 50.0, 100.0 ) );
		$product = $built['product'];

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		// Reload so get_price_html() reads the freshly synced variation data.
		$product = wc_get_product( $product->get_id() );
		$html    = $product->get_price_html();

		$this->assertStringContainsString( '100.00', $html, 'Range minimum must be converted (50 * 2.0).' );
		$this->assertStringContainsString( '200.00', $html, 'Range maximum must be converted (100 * 2.0).' );
		$this->assertStringContainsString( self::TARGET_SYMBOL, $html );
	}

	/**
	 * A single variation's own price_html must be converted the same way a
	 * simple product's is.
	 *
	 * @return void
	 */
	public function test_variation_price_html_shows_converted_amount(): void {
		$built     = $this->create_variable_product( array( 75.0 ) );
		$variation = wc_get_product( $built['variations'][0]->get_id() );

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		$html = $variation->get_price_html();

		$this->assertStringContainsString( '150.00', $html, 'Variation price must be converted (75 * 2.0).' );
		$this->assertStringContainsString( self::TARGET_SYMBOL, $html );
	}

	/**
	 * A product with a per-currency fixed price must show that fixed price
	 * instead of the automatically converted one.
	 *
	 * @return void
	 */
	public function test_product_with_fixed_currency_price_ignores_automatic_conversion(): void {
		$product = $this->create_simple_product( 100.0 );

		update_post_meta(
			$product->get_id(),
			ProductPricing::META_KEY,
			(string) wp_json_encode( array( self::TARGET_CURRENCY => '149.99' ) )
		);

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		$product = wc_get_product( $product->get_id() );
		$html    = $product->get_price_html();

		$this->assertStringContainsString(
			'149.99',
			$html,
			'A fixed per-product price must override automatic conversion.'
		);
		$this->assertStringNotContainsString(
			'200.00',
			$html,
			'The automatically converted amount (100 * 2.0) must not appear once a fixed price is set.'
		);
	}
}
