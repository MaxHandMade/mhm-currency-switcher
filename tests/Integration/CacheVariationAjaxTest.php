<?php
/**
 * Integration tests: in cache mode the variable add-to-cart form must NOT
 * embed WooCommerce's variations JSON, so the selected variation's price
 * arrives through `wc-ajax=get_variation` and is converted server-side
 * (design spec §5.5, implementation plan Task 9).
 *
 * The bug this closes is invisible in the HTML a reviewer looks at. On a
 * cacheable catalogue render the server deliberately leaves prices in the base
 * currency and marks each one for price-converter.js. WooCommerce's
 * `data-product_variations` attribute is not marked and cannot be: it is a JSON
 * blob inside an attribute, and WooCommerce's own variation.js writes
 * `price_html` from it straight into the DOM on every selection. So the page
 * would convert correctly on load, and then silently revert to an unconverted
 * price the moment the visitor picked a size — in a place the client-side
 * converter can never reach, because the amount it needs to replace never
 * carried a marker.
 *
 * 🔴 FILE NAME IS LOAD-BEARING, exactly as in CachePriceMarkerTest.
 * ConversionContextWiringTest ends by defining the WOOCOMMERCE_CART constant,
 * which no PHP process can undefine; from that point on every remaining test
 * sits in a money context and ConversionContext answers "convert" for the rest
 * of the run. This file asserts base-currency DISPLAY behaviour, so it can only
 * exist before that point, and "CacheVariationAjax" sorts ahead of
 * "ConversionContextWiring". The plan asked for these tests in
 * VariationPricesTest.php, which sorts AFTER it — there the cacheable-render
 * case is unreachable and the two tests below that assert it could never pass
 * for the right reason. Every test here opens with
 * assertMoneyConstantsUndefined() so a future reordering fails with a sentence
 * explaining itself rather than looking like a broken feature.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use WC_Product_Attribute;
use WC_Product_Variable;

/**
 * Class CacheVariationAjaxTest
 */
class CacheVariationAjaxTest extends MhmcsIntegrationTestCase {

	/**
	 * WooCommerce's own default for the AJAX variation threshold.
	 *
	 * Spelled out rather than read from WooCommerce: the assertions below are
	 * about this plugin handing the value back UNTOUCHED, and a constant
	 * derived from the code under test could not detect it being changed.
	 *
	 * @var int
	 */
	private const WC_DEFAULT_THRESHOLD = 30;

	/**
	 * Substring that must never appear in output the server already converted.
	 *
	 * @var string
	 */
	private const MARKER_NEEDLE = 'mhmcs-price';

	/**
	 * Plugin settings as found before the test, restored in tear_down().
	 *
	 * @var array<string, mixed>
	 */
	private $saved_settings = array();

	/**
	 * The `$product` global as found before the test.
	 *
	 * @var mixed
	 */
	private $saved_global_product;

	/**
	 * Whether this test replaced the `$product` global at all.
	 *
	 * @var bool
	 */
	private $had_global_product = false;

	/**
	 * Start every test as a logged-out visitor part-way through rendering a
	 * front-end product page: past the `wp` action, no WooCommerce AJAX action
	 * in flight, cache compatibility at its default (enabled).
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$settings             = get_option( 'mhmcs_settings', array() );
		$this->saved_settings = is_array( $settings ) ? $settings : array();

		$this->had_global_product   = array_key_exists( 'product', $GLOBALS );
		$this->saved_global_product = $this->had_global_product ? $GLOBALS['product'] : null;

		wp_set_current_user( 0 );
		$this->leave_money_context();
		$this->enter_render_phase();
	}

	/**
	 * Leave no request phase, query parameter, setting or global behind — in
	 * particular the faked `wp` action counter, which would otherwise make
	 * every later test file look like a page render.
	 *
	 * @return void
	 */
	public function tear_down() {
		update_option( 'mhmcs_settings', $this->saved_settings );

		if ( $this->had_global_product ) {
			$GLOBALS['product'] = $this->saved_global_product;
		} else {
			unset( $GLOBALS['product'] );
		}

		wp_set_current_user( 0 );
		$this->leave_money_context();
		$this->enter_pre_wp_phase();

		parent::tear_down();
	}

	// ─── Cache mode: the embedded JSON must not be printed ───────────────

	/**
	 * On a cacheable catalogue render the form must carry
	 * `data-product_variations="false"`, i.e. no embedded price data at all.
	 *
	 * The threshold is asserted against the product's own child count rather
	 * than against a hard-coded number, because "forces AJAX" is a RELATION
	 * between the two — WooCommerce embeds the JSON when
	 * `count( $children ) <= $threshold`. A test pinned to a literal would keep
	 * passing if WooCommerce ever changed the comparison.
	 *
	 * @return void
	 */
	public function test_cacheable_render_suppresses_the_embedded_variations_json(): void {
		$this->assertMoneyConstantsUndefined();

		$built   = $this->create_variable_product( array( 50.0, 100.0 ) );
		$product = $built['product'];

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		$this->assertLessThan(
			count( $product->get_children() ),
			apply_filters( 'woocommerce_ajax_variation_threshold', self::WC_DEFAULT_THRESHOLD, $product ),
			'On a cacheable render the threshold must drop BELOW the number of variations, which is what makes WooCommerce take the AJAX path instead of embedding the JSON.'
		);

		$this->assertSame(
			'false',
			$this->embedded_variations_attribute( $this->render_variable_add_to_cart( $product ) ),
			'A cacheable page must print data-product_variations="false". Any JSON here is base-currency price data that variation.js writes into the DOM marker-less, where the client-side converter can never correct it.'
		);
	}

	/**
	 * The suppression must actually remove the AMOUNTS, not just the attribute
	 * shape.
	 *
	 * Asserted separately and against the decoded attribute, because the
	 * failure that matters is "a base price reached the cached HTML", not
	 * "the attribute had the wrong literal in it".
	 *
	 * @return void
	 */
	public function test_no_base_variation_amount_reaches_the_cached_html(): void {
		$this->assertMoneyConstantsUndefined();

		$built   = $this->create_variable_product( array( 50.0, 100.0 ) );
		$product = $built['product'];

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		$attribute = $this->embedded_variations_attribute( $this->render_variable_add_to_cart( $product ) );

		$this->assertStringNotContainsString( 'display_price', $attribute, 'The embedded JSON must be gone entirely; display_price is the field variation.js reads to price the selection.' );
		$this->assertStringNotContainsString( '50', $attribute, 'The base price of the cheapest variation must not survive anywhere in the attribute.' );
		$this->assertStringNotContainsString( '100', $attribute, 'The base price of the dearest variation must not survive anywhere in the attribute.' );
	}

	// ─── The other end of the chain: the AJAX response ───────────────────

	/**
	 * 🔴 Forcing the AJAX path is only safe if the AJAX response is correct.
	 *
	 * `wc-ajax=get_variation` must resolve to "convert" through decision 5 (a
	 * non-empty `wc-ajax` parameter is a money context), and the `price_html`
	 * it returns must carry NO marker: the server already converted it, so a
	 * marker would have price-converter.js multiply by the exchange rate a
	 * second time and show the visitor rate² — a price that exists in no
	 * currency at all.
	 *
	 * `WC_Product_Variable::get_available_variation()` is called directly
	 * because it is the exact payload producer `WC_AJAX::get_variation()` wraps
	 * in `wp_send_json()`; the handler itself calls `wp_die()`.
	 *
	 * Both ends are asserted here rather than reasoned about, because "the
	 * embedded JSON is gone" and "the replacement is right" are independent
	 * failures and only the pair of them makes the feature work.
	 *
	 * @return void
	 */
	public function test_get_variation_ajax_payload_is_converted_and_marker_free(): void {
		$this->assertMoneyConstantsUndefined();

		$built     = $this->create_variable_product( array( 50.0, 100.0 ) );
		$product   = $built['product'];
		$variation = $built['variations'][0];

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		$this->enter_get_variation_ajax_context();

		$payload = $product->get_available_variation( $variation->get_id() );

		$this->assertIsArray( $payload, 'WooCommerce must return a payload for a published variation; without one there is nothing for the forced AJAX path to display.' );

		$this->assertEqualsWithDelta(
			100.0,
			(float) $payload['display_price'],
			0.001,
			'wc-ajax=get_variation must convert (50 * 2.0). If it did not, forcing the AJAX path would have replaced an unconverted embedded price with an unconverted AJAX price.'
		);

		$this->assertStringContainsString( self::TARGET_SYMBOL, $payload['price_html'], 'The AJAX price_html must carry the visitor currency symbol, since this HTML is written into the page verbatim.' );
		$this->assertStringContainsString( '100.00', $payload['price_html'], 'The AJAX price_html must carry the converted amount.' );
		$this->assertStringNotContainsString( '50.00', $payload['price_html'], 'The base amount must not appear in a response the server converted.' );

		$this->assertStringNotContainsString(
			self::MARKER_NEEDLE,
			$payload['price_html'],
			'Server-converted price_html must be marker-free: a marker here makes price-converter.js convert an already-converted amount a second time.'
		);
	}

	// ─── The cases that must keep WooCommerce's default behaviour ────────

	/**
	 * When the server converts, the embedded JSON is already in the visitor's
	 * currency and correct — so the threshold must be handed back untouched.
	 *
	 * Forcing AJAX there would buy nothing and cost one request per selection.
	 * This is the lock on the predicate being `should_convert()` and not
	 * "is cache mode on", which are different questions on a money page.
	 *
	 * @return void
	 */
	public function test_server_converted_render_keeps_the_embedded_variations_json(): void {
		$this->assertMoneyConstantsUndefined();

		$built   = $this->create_variable_product( array( 50.0, 100.0 ) );
		$product = $built['product'];

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		$this->enter_money_context();

		$this->assertSame(
			self::WC_DEFAULT_THRESHOLD,
			apply_filters( 'woocommerce_ajax_variation_threshold', self::WC_DEFAULT_THRESHOLD, $product ),
			'A converting request must get WooCommerce\'s own threshold back unchanged.'
		);

		$this->assertEqualsWithDelta(
			array( 100.0, 200.0 ),
			$this->embedded_display_prices( $product ),
			0.001,
			'When the server converts, the embedded JSON must still be printed AND already converted (50 and 100 at rate 2.0).'
		);
	}

	/**
	 * With cache compatibility switched off the plugin must behave exactly as
	 * v1.0.0 did: prices converted server-side, JSON embedded, no extra
	 * request per variation selection.
	 *
	 * This is the "defaults must not change existing sites' appearance"
	 * regression lock for this task.
	 *
	 * @return void
	 */
	public function test_cache_compatibility_off_keeps_the_embedded_variations_json(): void {
		$this->assertMoneyConstantsUndefined();

		$built   = $this->create_variable_product( array( 50.0, 100.0 ) );
		$product = $built['product'];

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		$this->disable_cache_compatibility();

		$this->assertSame(
			self::WC_DEFAULT_THRESHOLD,
			apply_filters( 'woocommerce_ajax_variation_threshold', self::WC_DEFAULT_THRESHOLD, $product ),
			'With the mode off nothing about the variation path may change; the extra AJAX request is a cost only cache mode has a reason to pay.'
		);

		$this->assertEqualsWithDelta(
			array( 100.0, 200.0 ),
			$this->embedded_display_prices( $product ),
			0.001,
			'With the mode off the embedded JSON stays, converted server-side, exactly as before this feature existed.'
		);
	}

	/**
	 * A variable product with NO variations keeps its (empty) embedded JSON.
	 *
	 * This is why the threshold returned is 0 rather than a negative number.
	 * WooCommerce compares `count( $children ) <= $threshold`, so 0 forces AJAX
	 * for every product that has at least one variation — every product that
	 * has a price to leak — while leaving the childless case embedding `[]`.
	 *
	 * That distinction is not cosmetic. WooCommerce's template picks its
	 * "This product is currently out of stock and unavailable." branch on
	 * `empty( $available_variations ) && false !== $available_variations`.
	 * Printing `false` here would make `false !== $available_variations` false,
	 * silently replacing that message with an attribute form for a product that
	 * can never be bought — a visible regression bought for no benefit, since a
	 * product with no variations has no price to expose in the first place.
	 *
	 * @return void
	 */
	public function test_variable_product_without_variations_keeps_its_empty_embedded_json(): void {
		$this->assertMoneyConstantsUndefined();

		$product = $this->create_variable_product_without_variations();

		$this->assertSame(
			array(),
			$product->get_children(),
			'Guard: this test is only meaningful for a product WooCommerce sees as childless.'
		);

		$html = $this->render_variable_add_to_cart( $product );

		$this->assertSame(
			'[]',
			$this->embedded_variations_attribute( $html ),
			'A childless variable product must still embed its empty array; there is no price to suppress and `false` would change WooCommerce\'s own rendering.'
		);

		$this->assertStringContainsString(
			'out-of-stock',
			$html,
			'WooCommerce\'s out-of-stock branch keys off `false !== $available_variations`; returning a negative threshold here would silently swap that message for an unusable attribute form.'
		);
	}

	// ─── Helpers ─────────────────────────────────────────────────────────

	/**
	 * Render WooCommerce's variable add-to-cart form for a product.
	 *
	 * The template reads the `$product` global, which is what a real single
	 * product page sets up; the buffer is closed in a finally so a template
	 * fatal cannot leave PHPUnit writing its own report into the buffer.
	 *
	 * @param WC_Product_Variable $product Product to render.
	 * @return string Rendered HTML.
	 */
	private function render_variable_add_to_cart( WC_Product_Variable $product ): string {
		$GLOBALS['product'] = $product;

		ob_start();

		try {
			woocommerce_variable_add_to_cart();

			return (string) ob_get_clean();
		} catch ( \Throwable $error ) {
			ob_end_clean();

			throw $error;
		}
	}

	/**
	 * Extract the raw `data-product_variations` attribute value from rendered
	 * form markup.
	 *
	 * @param string $html Rendered add-to-cart markup.
	 * @return string Attribute value exactly as printed.
	 */
	private function embedded_variations_attribute( string $html ): string {
		if ( 1 !== preg_match( '/data-product_variations="([^"]*)"/', $html, $matches ) ) {
			$this->fail( 'The variable add-to-cart form rendered no data-product_variations attribute at all, so this test cannot tell a suppressed JSON blob from a broken template.' );
		}

		return $matches[1];
	}

	/**
	 * Decode the embedded variations JSON and return its display prices, sorted
	 * ascending.
	 *
	 * @param WC_Product_Variable $product Product to render and decode.
	 * @return array<int, float> Display prices in ascending order.
	 */
	private function embedded_display_prices( WC_Product_Variable $product ): array {
		$attribute = $this->embedded_variations_attribute( $this->render_variable_add_to_cart( $product ) );
		$decoded   = json_decode( html_entity_decode( $attribute, ENT_QUOTES, 'UTF-8' ), true );

		if ( ! is_array( $decoded ) ) {
			$this->fail( 'The data-product_variations attribute did not decode to an array; the embedded JSON was expected to be present on this request. Raw value: ' . $attribute );
		}

		$prices = array();

		foreach ( $decoded as $variation ) {
			$prices[] = (float) $variation['display_price'];
		}

		sort( $prices );

		return $prices;
	}

	/**
	 * Create and persist a published variable product that has an attribute
	 * marked "used for variations" but no variations at all.
	 *
	 * @return WC_Product_Variable Saved, reloaded product.
	 */
	private function create_variable_product_without_variations(): WC_Product_Variable {
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( 'MhmcsOption0', 'MhmcsOption1' ) );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$product = new WC_Product_Variable();
		$product->set_name( 'MHMCS Test Variable Product Without Variations' );
		$product->set_attributes( array( $attribute ) );
		$product->set_status( 'publish' );
		$product->save();

		$reloaded = wc_get_product( $product->get_id() );

		if ( ! $reloaded instanceof WC_Product_Variable ) {
			$this->fail( 'Failed to reload the freshly created childless variable product.' );
		}

		return $reloaded;
	}

	/**
	 * Switch cache compatibility off for the current test.
	 *
	 * @return void
	 */
	private function disable_cache_compatibility(): void {
		$settings                 = $this->saved_settings;
		$settings['cache_compat'] = false;

		update_option( 'mhmcs_settings', $settings );
	}

	/**
	 * Move the request past the `wp` action, where conditional tags exist and
	 * a render can be cached.
	 *
	 * @return void
	 */
	private function enter_render_phase(): void {
		$GLOBALS['wp_actions']['wp'] = 1;
	}

	/**
	 * Return the request to the phase before the `wp` action.
	 *
	 * @return void
	 */
	private function enter_pre_wp_phase(): void {
		unset( $GLOBALS['wp_actions']['wp'] );
	}

	/**
	 * Put the request into a WooCommerce money context, reversibly.
	 *
	 * @return void
	 */
	private function enter_money_context(): void {
		$_GET['wc-ajax'] = 'get_refreshed_fragments';
	}

	/**
	 * Put the request into the exact money context this feature depends on:
	 * the variation lookup WooCommerce's variation.js performs once the
	 * embedded JSON is gone.
	 *
	 * @return void
	 */
	private function enter_get_variation_ajax_context(): void {
		$_GET['wc-ajax'] = 'get_variation';
	}

	/**
	 * Leave the money context.
	 *
	 * @return void
	 */
	private function leave_money_context(): void {
		unset( $_GET['wc-ajax'] );
	}

	/**
	 * Assert that no earlier test has defined WooCommerce's cart/checkout
	 * constants, which would put every remaining test in a money context.
	 *
	 * @return void
	 */
	private function assertMoneyConstantsUndefined(): void {
		$this->assertFalse(
			defined( 'WOOCOMMERCE_CART' ) || defined( 'WOOCOMMERCE_CHECKOUT' ),
			'This file asserts base-currency display behaviour, so it must run BEFORE ConversionContextWiringTest, which defines WOOCOMMERCE_CART for the rest of the process. PHPUnit sorts the files it collects; check that this file name still sorts ahead of ConversionContextWiringTest.php.'
		);
	}
}
