<?php
/**
 * Integration test: the multi-currency price row follows the product's own
 * price visibility.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

/**
 * The row is a price display, but it used to be built straight from the
 * product's price and never asked `woocommerce_get_price_html`. A filter that
 * hides prices — a maintenance or catalogue mode, "log in to see prices", a
 * wholesale plugin — therefore hid WooCommerce's price and left the converted
 * prices printed right under it (reported from maxhandmade.com, where the
 * callback had to be unhooked by hand).
 *
 * Three entry points share ProductWidget::render_shortcode(): the
 * single-product hook, the [mhmcs_currency_prices] shortcode and the Elementor
 * price widget. The hook is exercised through the callback WordPress actually
 * holds, so a fix applied somewhere the hook does not go would fail here.
 */
class ProductWidgetPriceVisibilityTest extends MhmcsIntegrationTestCase {

	/**
	 * Product under test.
	 *
	 * @var \WC_Product_Simple
	 */
	private $product;

	/**
	 * Enable the widget for TARGET_CURRENCY and create a priced product.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$settings                   = get_option( 'mhmcs_settings', array() );
		$settings                   = is_array( $settings ) ? $settings : array();
		$settings['product_widget'] = array(
			'enabled'    => true,
			'currencies' => array( self::TARGET_CURRENCY ),
			'show_flags' => false,
		);
		update_option( 'mhmcs_settings', $settings );

		$this->product = $this->create_simple_product( 100.0 );
	}

	/**
	 * Remove the global product and any hiding filter.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $GLOBALS['product'] );
		remove_all_filters( 'woocommerce_get_price_html' );

		parent::tear_down();
	}

	/**
	 * Hide every product price, the way a catalogue/maintenance mode does.
	 *
	 * @return void
	 */
	private function hide_prices(): void {
		add_filter( 'woocommerce_get_price_html', '__return_empty_string', PHP_INT_MAX );
	}

	/**
	 * Output of the single-product hook's ProductWidget callback.
	 *
	 * @return string
	 */
	private function product_page_row(): string {
		global $wp_filter;

		$callback = null;
		foreach ( $wp_filter['woocommerce_single_product_summary']->callbacks ?? array() as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				if ( is_array( $cb['function'] ) && 'render_on_product_page' === $cb['function'][1] ) {
					$callback = $cb['function'];
				}
			}
		}
		$this->assertNotNull( $callback, 'Precondition: ProductWidget is hooked on the single-product summary.' );

		$GLOBALS['product'] = $this->product;

		ob_start();
		call_user_func( $callback );

		return (string) ob_get_clean();
	}

	/**
	 * Shortcode output for the product.
	 *
	 * @return string
	 */
	private function shortcode_row(): string {
		return do_shortcode( '[mhmcs_currency_prices product_id="' . $this->product->get_id() . '"]' );
	}

	/**
	 * Positive control: with prices visible both entry points print the row.
	 * Without it, every "prints nothing" below would also pass on a widget
	 * that is simply broken.
	 *
	 * @return void
	 */
	public function test_with_prices_visible_the_row_is_printed(): void {
		$this->assertStringContainsString( 'mhmcs-product-price', $this->product_page_row() );
		$this->assertStringContainsString( 'mhmcs-product-price', $this->shortcode_row() );
	}

	/**
	 * The defect: a price-hiding filter must hide the converted prices too.
	 *
	 * @return void
	 */
	public function test_a_price_hiding_filter_hides_the_row_on_the_product_page(): void {
		$this->hide_prices();

		$this->assertSame( '', trim( $this->product_page_row() ) );
	}

	/**
	 * The same through the shortcode (and therefore the Elementor widget,
	 * which renders through the same method).
	 *
	 * @return void
	 */
	public function test_a_price_hiding_filter_hides_the_row_in_the_shortcode(): void {
		$this->hide_prices();

		$this->assertSame( '', $this->shortcode_row() );
	}

	/**
	 * A filter that CHANGES the price HTML without emptying it (a suffix, a
	 * "from" prefix) must not hide the row: only an empty price means hidden.
	 *
	 * @return void
	 */
	public function test_a_filter_that_only_decorates_the_price_keeps_the_row(): void {
		add_filter(
			'woocommerce_get_price_html',
			static function ( $html ) {
				return $html . ' <small>incl. VAT</small>';
			}
		);

		$this->assertStringContainsString( 'mhmcs-product-price', $this->shortcode_row() );
	}

	/**
	 * An explicit `price` attribute is a value the site owner typed, not the
	 * product's displayed price; it is not subject to the product's
	 * visibility.
	 *
	 * @return void
	 */
	public function test_an_explicit_price_attribute_is_not_hidden(): void {
		$this->hide_prices();

		$this->assertStringContainsString( 'mhmcs-product-price', do_shortcode( '[mhmcs_currency_prices price="50"]' ) );
	}
}
