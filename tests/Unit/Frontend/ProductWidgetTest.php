<?php
/**
 * Unit tests for the ProductWidget shortcode renderer.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Frontend;

use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Frontend\ProductWidget;
use PHPUnit\Framework\TestCase;

/**
 * Class ProductWidgetTest
 *
 * Pure unit tests — no WordPress or WooCommerce dependency.
 * Tests the shortcode rendering with explicit price and currencies attributes.
 *
 * Setup:
 *   Base: TRY
 *   USD: rate=0.03, fee=percentage 2%, symbol=$, position=left
 *   EUR: rate=0.025, fee=fixed 0.001, symbol=€, position=right
 *
 * @covers \MhmCurrencySwitcher\Frontend\ProductWidget
 */
class ProductWidgetTest extends TestCase {

	/**
	 * Currency data store.
	 *
	 * @var CurrencyStore
	 */
	private CurrencyStore $store;

	/**
	 * Price converter.
	 *
	 * @var Converter
	 */
	private Converter $converter;

	/**
	 * Product widget instance under test.
	 *
	 * @var ProductWidget
	 */
	private ProductWidget $widget;

	/**
	 * Set up store, converter, and widget instances.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->store = new CurrencyStore();
		$this->store->set_data(
			'TRY',
			array(
				array(
					'code'     => 'USD',
					'enabled'  => true,
					'rate'     => array(
						'type'  => 'manual',
						'value' => 0.03,
					),
					'fee'      => array(
						'type'  => 'percentage',
						'value' => 2,
					),
					'rounding' => array(
						'type'     => 'disabled',
						'value'    => 0,
						'subtract' => 0,
					),
					'format'   => array(
						'symbol'       => '$',
						'position'     => 'left',
						'thousand_sep' => ',',
						'decimal_sep'  => '.',
						'decimals'     => 2,
					),
				),
				array(
					'code'     => 'EUR',
					'enabled'  => true,
					'rate'     => array(
						'type'  => 'manual',
						'value' => 0.025,
					),
					'fee'      => array(
						'type'  => 'fixed',
						'value' => 0.001,
					),
					'rounding' => array(
						'type'     => 'disabled',
						'value'    => 0,
						'subtract' => 0,
					),
					'format'   => array(
						'symbol'       => "\u{20AC}",
						'position'     => 'right',
						'thousand_sep' => '.',
						'decimal_sep'  => ',',
						'decimals'     => 2,
					),
				),
			)
		);

		$this->converter = new Converter( $this->store );
		$this->widget    = new ProductWidget( $this->store, $this->converter );
	}

	/**
	 * Clean up superglobals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $product;

		$product = null;
		unset( $GLOBALS['__mhmcs_test_post_meta'] );

		parent::tearDown();
	}

	/**
	 * Build a ProductWidget with the given product_widget settings saved
	 * to mhmcs_settings, plus a seeded active currency (USD) and a fake
	 * global $product with a post-meta-backed price — the same fallback
	 * path production's render_on_product_page() relies on — so that
	 * render_shortcode() has both a currency and a price to resolve even
	 * when called with no explicit atts.
	 *
	 * @param array<string, mixed> $widget_settings Product widget settings.
	 * @return ProductWidget
	 */
	private function create_widget( array $widget_settings ): ProductWidget {
		global $product;

		$product = new class() {
			public function get_id() {
				return 42;
			}
		};

		$GLOBALS['__mhmcs_test_post_meta'][42]['_price'] = '1000';

		update_option(
			'mhmcs_settings',
			array(
				'product_widget' => array_merge(
					array( 'currencies' => array( 'USD' ) ),
					$widget_settings
				),
			)
		);

		return $this->widget;
	}

	// ---------------------------------------------------------------
	// Tests
	// ---------------------------------------------------------------

	/**
	 * Test that the shortcode renders converted amounts.
	 *
	 * Uses the explicit price and currencies attributes to avoid
	 * needing a real WC_Product.
	 *
	 * 1000 TRY → USD: rate=0.03, fee=2% → effective 0.0306 → 30.60
	 * 1000 TRY → EUR: rate=0.025, fee=+0.001 → effective 0.026 → 26.00
	 *
	 * @return void
	 */
	public function test_shortcode_renders_prices(): void {
		$html = $this->widget->render_shortcode(
			array(
				'price'      => '1000',
				'currencies' => 'USD,EUR',
			)
		);

		$this->assertStringContainsString( 'mhm-cs-product-prices', $html );
		$this->assertStringContainsString( 'mhm-cs-amount', $html );

		// Check USD converted amount: $30.60.
		$this->assertStringContainsString( '$30.60', $html );

		// Check EUR converted amount: 26,00€.
		$this->assertStringContainsString( "26,00\u{20AC}", $html );
	}

	/**
	 * Test that the shortcode returns empty when no currencies are configured.
	 *
	 * @return void
	 */
	public function test_shortcode_empty_when_no_currencies(): void {
		$html = $this->widget->render_shortcode(
			array(
				'price'      => '1000',
				'currencies' => '',
			)
		);

		$this->assertSame( '', $html );
	}

	/**
	 * Test that the output contains img tags with flag references.
	 *
	 * @return void
	 */
	public function test_shortcode_renders_flag_images(): void {
		$html = $this->widget->render_shortcode(
			array(
				'price'      => '1000',
				'currencies' => 'USD,EUR',
			)
		);

		$this->assertStringContainsString( '<img', $html );
		$this->assertStringContainsString( 'mhm-cs-flag', $html );
		$this->assertStringContainsString( 'flags/us.svg', $html );
		$this->assertStringContainsString( 'flags/eu.svg', $html );
	}

	/**
	 * The widget must honour the show_flags setting instead of always
	 * printing flags.
	 *
	 * @return void
	 */
	public function test_widget_omits_flags_when_disabled(): void {
		$widget = $this->create_widget( array( 'show_flags' => false ) );

		$this->assertStringNotContainsString( 'mhm-cs-flag', $widget->render_shortcode( array() ) );
	}

	/**
	 * Flags stay on by default (current behaviour).
	 *
	 * @return void
	 */
	public function test_widget_shows_flags_by_default(): void {
		$widget = $this->create_widget( array() );

		$this->assertStringContainsString( 'mhm-cs-flag', $widget->render_shortcode( array() ) );
	}
}
