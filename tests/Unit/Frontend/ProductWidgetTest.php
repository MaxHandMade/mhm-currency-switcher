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

	/**
	 * Regression: before WordPress 6.5, shortcode_parse_atts() returns an
	 * empty string — not array() — when a shortcode is used with no
	 * attributes at all (e.g. bare `[mhmcs_currency_prices]`). WordPress core
	 * then calls the registered callback with that string. A native
	 * `array $atts` type hint under strict_types=1 turns this into a fatal
	 * TypeError on every 6.0-6.4 site. The callback must tolerate a
	 * non-array argument.
	 *
	 * @return void
	 */
	public function test_render_shortcode_accepts_non_array_atts_pre_wp65(): void {
		$widget = $this->create_widget( array() );

		$html = $widget->render_shortcode( '' );

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'mhm-cs-product-prices', $html );
	}

	/**
	 * Shortcode attributes always arrive as strings. `[mhmcs_currency_prices
	 * show_flags="false"]` must actually turn flags off — `(bool) 'false'`
	 * is true in PHP, so a naive cast makes the natural spelling of "off"
	 * inert. "0" happens to work today only because `(bool) '0'` is false.
	 *
	 * @return void
	 */
	public function test_widget_show_flags_string_false_is_honoured(): void {
		$widget = $this->create_widget( array() );

		$html = $widget->render_shortcode(
			array(
				'price'      => '1000',
				'currencies' => 'USD',
				'show_flags' => 'false',
			)
		);

		$this->assertStringNotContainsString( 'mhm-cs-flag', $html );
	}

	/**
	 * Symmetric check: the string "true" must switch flags on even when
	 * the saved setting has them off.
	 *
	 * @return void
	 */
	public function test_widget_show_flags_string_true_is_honoured(): void {
		$widget = $this->create_widget( array( 'show_flags' => false ) );

		$html = $widget->render_shortcode(
			array(
				'price'      => '1000',
				'currencies' => 'USD',
				'show_flags' => 'true',
			)
		);

		$this->assertStringContainsString( 'mhm-cs-flag', $html );
	}

	/**
	 * A currency the shop never configured must not be priced at all.
	 *
	 * 🔴 It used to render the BASE amount wearing the foreign code. The
	 * pieces are each defensible on their own and lie when combined:
	 * `resolve_currencies()` accepts any three-letter code that is not the
	 * base without asking whether the shop configured it; `convert()`
	 * refuses to invent a rate and returns the price untouched; and
	 * `format_price()` falls back to "CODE 1,000.00" when it has no format
	 * data. Together they print a real-looking foreign price that is really
	 * the base amount.
	 *
	 * Measured in production shape before the fix: a TRY shop with no
	 * configured currencies rendered a 1000 TRY product as
	 * "USD 1,000.00 | EUR 1,000.00 | GBP 1,000.00" — and the Elementor
	 * widget ships exactly `USD,EUR,GBP` as its control default, so dropping
	 * it on a page with nothing configured was enough to produce it. No
	 * error, no notice, nothing for any gate to see.
	 *
	 * @return void
	 */
	public function test_a_currency_the_shop_never_configured_is_not_priced(): void {
		$html = $this->widget->render_shortcode(
			array(
				'price'      => '1000',
				'currencies' => 'GBP',
			)
		);

		$this->assertSame(
			'',
			$html,
			'GBP is not in the store. Printing anything for it means printing a number no rate produced.'
		);
	}

	/**
	 * A mixed list keeps the configured members and drops the rest, rather
	 * than failing whole or passing whole.
	 *
	 * @return void
	 */
	public function test_only_configured_currencies_survive_a_mixed_list(): void {
		$html = $this->widget->render_shortcode(
			array(
				'price'      => '1000',
				'currencies' => 'USD,GBP',
			)
		);

		$this->assertStringContainsString( '$', $html, 'USD is configured and must still render.' );
		$this->assertStringNotContainsString( 'GBP', $html, 'GBP is not configured and must not appear.' );
	}

	/**
	 * Give the store a currency that is configured but cannot produce a price,
	 * alongside the usable USD the fixture already has.
	 *
	 * @param array<string, mixed> $overrides Row fields to override on GBP.
	 * @return void
	 */
	private function add_gbp( array $overrides ): void {
		$row = array_merge(
			array(
				'code'     => 'GBP',
				'enabled'  => true,
				'rate'     => array(
					'type'  => 'manual',
					'value' => 0.02,
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
				'format'   => array(
					'symbol'       => "\u{00A3}",
					'position'     => 'left',
					'thousand_sep' => ',',
					'decimal_sep'  => '.',
					'decimals'     => 2,
				),
			),
			$overrides
		);

		$existing   = $this->store->get_currencies_raw();
		$existing[] = $row;

		$this->store->set_data( 'TRY', $existing );
	}

	/**
	 * 🔴 A currency whose rate cannot produce a price must not be priced.
	 *
	 * `drop_unconfigured()` only asked whether the shop KNOWS the code. A
	 * currency that is configured but has no usable rate — a rate of zero,
	 * which the panel saves silently when the field is cleared, or a fee that
	 * cancels the rate out — passed straight through. `Converter::convert()`
	 * then deliberately returns the BASE amount untouched rather than invent a
	 * rate, and `format_price()` wraps that base amount in the TARGET
	 * currency's symbol.
	 *
	 * The result is a Turkish lira figure wearing a pound sign: a number no
	 * rate produced, with nothing on the page to suggest anything is wrong.
	 * That exact class was fixed for the price display in v1.1.3; this surface
	 * was never swept.
	 *
	 * @return void
	 */
	public function test_a_currency_with_an_unusable_rate_is_not_priced(): void {
		$this->add_gbp(
			array(
				'rate' => array(
					'type'  => 'manual',
					'value' => 0,
				),
			)
		);

		$html = $this->widget->render_shortcode(
			array(
				'price'      => '1000',
				'currencies' => 'USD,GBP',
			)
		);

		$this->assertStringNotContainsString(
			"\u{00A3}",
			$html,
			'A currency with no usable rate was priced: the base amount is printed under its symbol.'
		);
		$this->assertStringNotContainsString( 'GBP', $html, 'The unusable currency still appears in the widget.' );

		$this->assertStringContainsString(
			'$',
			$html,
			'The usable currency alongside it must still render — dropping everything is not the fix.'
		);
	}

	/**
	 * A currency the shop switched OFF must not be priced either.
	 *
	 * The Switcher and DetectionService both honour `enabled`; this widget did
	 * not, so a shop owner who turned a currency off still saw it on every
	 * product page and had no way to tell why.
	 *
	 * @return void
	 */
	public function test_a_disabled_currency_is_not_priced(): void {
		$this->add_gbp( array( 'enabled' => false ) );

		$html = $this->widget->render_shortcode(
			array(
				'price'      => '1000',
				'currencies' => 'USD,GBP',
			)
		);

		// Asserted on the SYMBOL, not the code. The rendered markup carries
		// "£1,000.00" and never the letters "GBP" unless flags are on, so a
		// `assertStringNotContainsString( 'GBP', ... )` here passes whether or
		// not the currency was rendered — a test green for the wrong reason.
		// It was written that way first and caught by running it.
		$this->assertStringNotContainsString(
			"\u{00A3}",
			$html,
			'A disabled currency is still rendered by the product widget.'
		);
		$this->assertStringContainsString( '$', $html, 'The enabled currency must still render.' );
	}

	/**
	 * The control. A perfectly ordinary configured, enabled, usable currency
	 * must keep rendering — otherwise "drop the bad ones" quietly becomes
	 * "drop them all" and every test above still passes.
	 *
	 * @return void
	 */
	public function test_an_enabled_currency_with_a_usable_rate_still_renders(): void {
		$this->add_gbp( array() );

		$html = $this->widget->render_shortcode(
			array(
				'price'      => '1000',
				'currencies' => 'GBP',
			)
		);

		$this->assertStringContainsString( "\u{00A3}", $html, 'A healthy currency stopped rendering.' );
		$this->assertStringContainsString( '20.00', $html, '1000 * 0.02 = 20.00 must still be the printed amount.' );
	}
}
