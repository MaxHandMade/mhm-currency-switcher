<?php
/**
 * Integration tests: the cache-mode price marker (design spec §4 and §5.1,
 * implementation plan Task 5).
 *
 * The positive case is one line of markup, but the value of this file is in
 * the NEGATIVE cases. A marker is an instruction to the browser: "this price
 * is in the base currency, please replace it with the converted one". Emitting
 * it on output that the SERVER already converted makes the client convert a
 * second time — the visitor is shown rate², and the audit found a real bug of
 * exactly that shape. So every context that converts server-side must be
 * proven marker-free, not assumed to be.
 *
 * 🔴 FILE NAME IS LOAD-BEARING. PHPUnit's directory suffix scan sorts the
 * files it collects, and ConversionContextWiringTest ends by defining the
 * WOOCOMMERCE_CART constant, which no PHP process can undefine — from that
 * point on every remaining test sits in a money context and ConversionContext
 * answers "convert" for the rest of the run. This file asserts base-currency
 * DISPLAY behaviour, so it can only exist before that point; "CachePriceMarker"
 * sorts ahead of "ConversionContextWiring". The plan named this file
 * PriceMarkerTest.php, which would have sorted after it and made the positive
 * case impossible to write. Every base-currency test below opens with
 * assertMoneyConstantsUndefined() so that a future reordering fails with a
 * sentence explaining itself rather than looking like a broken marker.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

/**
 * Class CachePriceMarkerTest
 */
class CachePriceMarkerTest extends MhmcsIntegrationTestCase {

	/**
	 * Opening tag of the marker, with the product ID left to the caller.
	 *
	 * Spelled out as a literal rather than built from the production class's
	 * constants: this string is a contract with assets/js/price-converter.js
	 * (Task 7) and with any cache plugin that rewrites HTML, so a test that
	 * derived it from the code under test could never notice it changing.
	 *
	 * @var string
	 */
	private const MARKER_OPEN_FORMAT = '<span class="mhmcs-price" data-mhmcs-product="%d">';

	/**
	 * Substring that must not appear anywhere in server-converted output.
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
	 * REQUEST_URI as found before a Store API context was simulated, or false
	 * when this test never replaced it.
	 *
	 * @var string|null|false
	 */
	private $saved_request_uri = false;

	/**
	 * Start every test as a logged-out visitor part-way through rendering a
	 * front-end page: past the `wp` action, no WooCommerce AJAX action in
	 * flight, cache compatibility at its default (enabled).
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$settings             = get_option( 'mhmcs_settings', array() );
		$this->saved_settings = is_array( $settings ) ? $settings : array();

		wp_set_current_user( 0 );
		$this->leave_money_context();
		$this->enter_render_phase();
	}

	/**
	 * Leave no request phase, query parameter, user or setting behind — in
	 * particular the faked `wp` action counter, which would otherwise make
	 * every later test file look like a page render.
	 *
	 * @return void
	 */
	public function tear_down() {
		update_option( 'mhmcs_settings', $this->saved_settings );

		wp_set_current_user( 0 );
		$this->leave_money_context();
		$this->enter_pre_wp_phase();

		parent::tear_down();
	}

	// ─── The display context: the one place a marker belongs ─────────

	/**
	 * A catalogue render wraps WooCommerce's own price_html, unchanged, in a
	 * single marker carrying the product ID.
	 *
	 * The inner HTML is asserted byte-for-byte against wc_price() rather than
	 * with a "contains" check. The marker's whole purpose is that the client
	 * replaces the wrapper's innerHTML, so anything the wrapper silently
	 * mangles — a stripped tag, a double-escaped entity — is lost from the
	 * page before JavaScript ever runs.
	 *
	 * @return void
	 */
	public function test_display_context_wraps_price_html_in_a_product_marker(): void {
		$this->assertMoneyConstantsUndefined();

		$product = $this->create_simple_product( 100.0 );
		$id      = $product->get_id();

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		$html = wc_get_product( $id )->get_price_html();
		$open = sprintf( self::MARKER_OPEN_FORMAT, $id );

		$this->assertStringStartsWith(
			$open,
			$html,
			'A cacheable catalogue render must open with the marker: without it the client has nothing to find and prices stay in the base currency forever.'
		);
		$this->assertStringEndsWith( '</span>', $html, 'The marker must be closed.' );

		$this->assertSame(
			wc_price( 100.0 ),
			substr( $html, strlen( $open ), -strlen( '</span>' ) ),
			'The marker must wrap WooCommerce\'s price_html byte-for-byte. Re-escaping or filtering the inner HTML would corrupt the price the visitor sees before any JavaScript runs.'
		);

		$this->assertSame(
			1,
			substr_count( $html, self::MARKER_NEEDLE ),
			'Exactly one marker per price_html: nested markers make the client replace an outer wrapper and destroy the inner one.'
		);

		$this->assertStringNotContainsString(
			'200.00',
			$html,
			'The marked-up price must still be the BASE amount (100 * 2.0 would be the converted one) — that is the whole point of caching it.'
		);
		$this->assertStringNotContainsString(
			self::TARGET_SYMBOL,
			$html,
			'A converted symbol on a base amount misstates the price, marker or no marker.'
		);
	}

	/**
	 * A product on sale prints two amounts inside ONE marker.
	 *
	 * WooCommerce renders `<del>` old price + `<ins>` new price. If the marker
	 * were applied per amount instead of per price_html, the client would have
	 * to reason about which half it was replacing; if it were applied twice,
	 * the outer replacement would delete the inner one.
	 *
	 * @return void
	 */
	public function test_sale_price_html_is_wrapped_once_around_both_amounts(): void {
		$this->assertMoneyConstantsUndefined();

		$product = $this->create_simple_product( 120.0, 90.0 );
		$id      = $product->get_id();

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		$html = wc_get_product( $id )->get_price_html();
		$open = sprintf( self::MARKER_OPEN_FORMAT, $id );

		$this->assertStringStartsWith( $open, $html, 'A sale price is still a display price and still needs its marker.' );
		$this->assertStringEndsWith( '</span>', $html );

		$inner = substr( $html, strlen( $open ), -strlen( '</span>' ) );

		$this->assertStringContainsString( '<del', $inner, 'WooCommerce\'s struck-through regular price must survive the wrapper untouched.' );
		$this->assertStringContainsString( '<ins', $inner, 'WooCommerce\'s sale price markup must survive the wrapper untouched.' );
		$this->assertStringContainsString( '120.00', $inner, 'The base regular price must be inside the marker.' );
		$this->assertStringContainsString( '90.00', $inner, 'The base sale price must be inside the marker.' );

		$this->assertSame(
			1,
			substr_count( $html, self::MARKER_NEEDLE ),
			'One marker for the whole price_html, not one per amount.'
		);

		$this->assertStringNotContainsString( '240.00', $html, 'The converted regular price (120 * 2.0) must not reach a cacheable page.' );
		$this->assertStringNotContainsString( '180.00', $html, 'The converted sale price (90 * 2.0) must not reach a cacheable page.' );
	}

	/**
	 * Each marker carries its own product's ID.
	 *
	 * A cross-wired ID is the worst failure this feature can produce and the
	 * least visible: every price on the page is present, formatted correctly,
	 * in the right currency — and belongs to a different product. Two products
	 * with different prices are rendered in one pass so a stale ID captured in
	 * a closure or a static cannot pass.
	 *
	 * @return void
	 */
	public function test_each_marker_carries_its_own_product_id(): void {
		$this->assertMoneyConstantsUndefined();

		$first  = $this->create_simple_product( 100.0 );
		$second = $this->create_simple_product( 55.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		$first_html  = wc_get_product( $first->get_id() )->get_price_html();
		$second_html = wc_get_product( $second->get_id() )->get_price_html();

		$this->assertStringContainsString( sprintf( self::MARKER_OPEN_FORMAT, $first->get_id() ), $first_html );
		$this->assertStringContainsString( '100.00', $first_html );

		$this->assertStringContainsString( sprintf( self::MARKER_OPEN_FORMAT, $second->get_id() ), $second_html );
		$this->assertStringContainsString( '55.00', $second_html );

		$this->assertStringNotContainsString(
			sprintf( self::MARKER_OPEN_FORMAT, $second->get_id() ),
			$first_html,
			'Rendering a second product must not retro-fit its ID onto the first marker.'
		);
	}

	/**
	 * A variation is marked with the VARIATION's own ID, not its parent's.
	 *
	 * The convert endpoint (Task 6) accepts `product_variation` IDs and
	 * validates them against their parent, so sending the parent ID here would
	 * quietly return the parent's price range in place of the selected
	 * variation's price.
	 *
	 * @return void
	 */
	public function test_variation_marker_carries_the_variation_id(): void {
		$this->assertMoneyConstantsUndefined();

		$built     = $this->create_variable_product( array( 15.0, 25.0 ) );
		$variation = $built['variations'][0];

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		$html = wc_get_product( $variation->get_id() )->get_price_html();

		$this->assertStringContainsString(
			sprintf( self::MARKER_OPEN_FORMAT, $variation->get_id() ),
			$html,
			'A variation must be addressed by its own ID.'
		);
		$this->assertStringNotContainsString(
			sprintf( self::MARKER_OPEN_FORMAT, $built['product']->get_id() ),
			$html,
			'The parent product ID would make the endpoint answer with the parent price range.'
		);
	}

	/**
	 * Nothing to mark, nothing marked.
	 *
	 * A product with no price renders an empty price_html. An empty marker
	 * would still be collected by the client and handed to the endpoint, and
	 * a price would then appear where the shop deliberately shows none.
	 *
	 * @return void
	 */
	public function test_empty_price_html_is_left_alone(): void {
		$this->assertMoneyConstantsUndefined();

		$product = $this->create_simple_product( 0.0 );
		$product->set_regular_price( '' );
		$product->set_price( '' );
		$product->save();

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		$html = wc_get_product( $product->get_id() )->get_price_html();

		$this->assertStringNotContainsString(
			self::MARKER_NEEDLE,
			$html,
			'An empty price must stay empty: a marker around nothing invites the client to invent a price.'
		);
	}

	// ─── Negative cases: every context that already converted ────────

	/**
	 * 🔴 A money context must emit NO marker.
	 *
	 * This is the double-conversion lock. In a money context the server has
	 * already converted the amount; a marker would tell the client "this is
	 * base, please convert it", and the visitor would be shown the price
	 * multiplied by the rate twice.
	 *
	 * @return void
	 */
	public function test_money_context_emits_no_marker(): void {
		$product = $this->create_simple_product( 100.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		$this->enter_money_context();

		$html = wc_get_product( $product->get_id() )->get_price_html();

		$this->assertStringContainsString(
			'200.00',
			$html,
			'Guard: the money context must genuinely have converted (100 * 2.0), otherwise this test proves nothing about markers.'
		);
		$this->assertStringNotContainsString(
			self::MARKER_NEEDLE,
			$html,
			'A marker on server-converted output makes the client convert it a SECOND time — the visitor sees the price multiplied by the rate squared.'
		);
	}

	/**
	 * 🔴 A logged-in visitor must get NO marker.
	 *
	 * Decision 6 sends logged-in visitors down the server-side path precisely
	 * because their pages are not shared cache entries. Their prices arrive
	 * already converted, so there is nothing for the client to fix and a
	 * marker would only be an invitation to convert twice.
	 *
	 * @return void
	 */
	public function test_logged_in_visitor_gets_no_marker(): void {
		$this->assertMoneyConstantsUndefined();

		$product = $this->create_simple_product( 100.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		wp_set_current_user( self::$admin_id );

		$html = wc_get_product( $product->get_id() )->get_price_html();

		$this->assertStringContainsString(
			'200.00',
			$html,
			'Guard: a logged-in visitor must genuinely be served the converted amount server-side (decision 6).'
		);
		$this->assertStringNotContainsString(
			self::MARKER_NEEDLE,
			$html,
			'A logged-in visitor already has converted prices; marking them would convert them again on the client.'
		);
	}

	/**
	 * 🔴 With cache compatibility OFF there must be NO marker.
	 *
	 * The toggle is the site owner's opt-out from the whole client-side path:
	 * the server converts as it did in v1.0.0, and no marker, no REST call and
	 * no JavaScript rewriting of prices may follow from it.
	 *
	 * @return void
	 */
	public function test_cache_compat_disabled_emits_no_marker(): void {
		$this->assertMoneyConstantsUndefined();

		$settings                 = $this->saved_settings;
		$settings['cache_compat'] = false;
		update_option( 'mhmcs_settings', $settings );

		$product = $this->create_simple_product( 100.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		$html = wc_get_product( $product->get_id() )->get_price_html();

		$this->assertStringContainsString(
			'200.00',
			$html,
			'Guard: with the mode off the server must convert (100 * 2.0) exactly as v1.0.0 did.'
		);
		$this->assertStringNotContainsString(
			self::MARKER_NEEDLE,
			$html,
			'Turning cache compatibility off must remove the client-side path entirely, starting with the marker.'
		);
	}

	/**
	 * 🔴 A Store API render carries no marker, so the JSON that WooCommerce
	 * Blocks preloads into the page carries none either.
	 *
	 * Flagged as untested territory when the marker landed. WooCommerce Blocks
	 * calls rest_preload_api_request() during a page render, which dispatches
	 * Store API routes through rest_do_request() and embeds the result in a
	 * `<script type="application/json">` blob; the Store API product schema
	 * includes `price_html`, so whatever that render produces is what ends up
	 * in the blob — and in the page cache.
	 *
	 * Two independent things have to hold, and this test covers the second:
	 *
	 * - The client's selector cannot reach into that blob whatever it contains.
	 *   A script element's contents are a single text node, and
	 *   querySelectorAll walks elements, so `.mhmcs-price[data-mhmcs-product]`
	 *   cannot match inside one. That is structural, not a policy this file can
	 *   assert.
	 * - The blob carries no marker in the first place, because the Store API is
	 *   a money context: decision 5 converts it server-side and the marker
	 *   stays away from converted output. That is what is asserted here — and
	 *   were it ever to change, Blocks would print an unconverted marker into a
	 *   JSON string, which React writes into the DOM through
	 *   dangerouslySetInnerHTML, at which point the selector WOULD match it and
	 *   convert an already-converted price a second time.
	 *
	 * @return void
	 */
	public function test_store_api_context_emits_no_marker(): void {
		$product = $this->create_simple_product( 100.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		$this->enter_store_api_context();

		$html = wc_get_product( $product->get_id() )->get_price_html();

		$this->assertStringContainsString(
			'200.00',
			$html,
			'Guard: the Store API is a money context and must genuinely convert (100 * 2.0), otherwise this proves nothing about markers.'
		);
		$this->assertStringNotContainsString(
			self::MARKER_NEEDLE,
			$html,
			'A marker in Store API output is embedded in the Blocks preload JSON and rendered into the DOM by React, where the converter would convert an already-converted price.'
		);
	}

	/**
	 * The multi-currency product widget stays outside the marker (spec §4).
	 *
	 * [mhmcs_currency_prices] prints a visitor-INDEPENDENT list of every enabled
	 * currency, built from the raw `_price` meta rather than from
	 * get_price_html(). It is already cache-safe. Marking it would hand the
	 * client a wrapper whose innerHTML replacement collapses the whole list
	 * into a single price.
	 *
	 * Asserted rather than assumed: the exclusion currently holds because the
	 * widget never calls get_price_html() at all, and this test fails the day
	 * that changes. The Elementor PriceDisplayWidget is the same output — its
	 * render() delegates straight to ProductWidget::render_shortcode().
	 *
	 * @return void
	 */
	public function test_product_widget_multi_currency_list_carries_no_marker(): void {
		$this->assertMoneyConstantsUndefined();

		$product = $this->create_simple_product( 100.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		$output = do_shortcode( '[mhmcs_currency_prices product_id="' . $product->get_id() . '" currencies="' . self::TARGET_CURRENCY . '"]' );

		$this->assertStringContainsString(
			'mhm-cs-product-prices',
			$output,
			'Guard: the widget must actually have rendered, otherwise "no marker" is vacuously true.'
		);
		$this->assertStringContainsString(
			'200.00',
			$output,
			'Guard: the widget converts for every listed currency regardless of the visitor — that is why it needs no marker.'
		);
		$this->assertStringNotContainsString(
			self::MARKER_NEEDLE,
			$output,
			'Spec §4 excludes the product widget: an innerHTML replacement would destroy its multi-currency list.'
		);
	}

	// ─── Helpers ─────────────────────────────────────────────────────

	/**
	 * Mark the request as being past the `wp` action, i.e. template rendering
	 * has begun. Same technique, and the same reasoning, as
	 * ConversionContextWiringTest: setting the counter changes exactly the one
	 * signal under test, where firing the real action would run every core and
	 * WooCommerce callback hooked there.
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
	 * Put the request into a Store API context, reversibly.
	 *
	 * The request URI is what ConversionContext reads — its REST primitive is
	 * pinned to a REQUEST_URI test rather than a dispatch-aware one, so this is
	 * the signal the production code actually consults.
	 *
	 * @return void
	 */
	private function enter_store_api_context(): void {
		if ( false === $this->saved_request_uri ) {
			$this->saved_request_uri = $_SERVER['REQUEST_URI'] ?? null;
		}

		$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/products';
	}

	/**
	 * Restore the request URI, and only if this test replaced it. The whole
	 * suite shares one PHP process, so blanking a URI nobody set would hand
	 * every later test a request shape no browser produces.
	 *
	 * @return void
	 */
	private function leave_store_api_context(): void {
		if ( false === $this->saved_request_uri ) {
			return;
		}

		if ( null === $this->saved_request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->saved_request_uri;
		}

		$this->saved_request_uri = false;
	}

	/**
	 * Leave the WooCommerce money context.
	 *
	 * @return void
	 */
	private function leave_money_context(): void {
		unset( $_GET['wc-ajax'] );

		$this->leave_store_api_context();
	}

	/**
	 * Assert that no earlier test has defined WooCommerce's cart/checkout
	 * constants, which cannot be undefined and would put the rest of the
	 * process into a money context.
	 *
	 * @return void
	 */
	private function assertMoneyConstantsUndefined(): void {
		$this->assertFalse(
			defined( 'WOOCOMMERCE_CART' ) || defined( 'WOOCOMMERCE_CHECKOUT' ),
			'This test asserts base-currency DISPLAY behaviour, so it must run before ConversionContextWiringTest::test_cart_shortcode_outside_assigned_page_flips_latch, which defines WOOCOMMERCE_CART for the rest of the process. PHPUnit sorts the files it collects, so check that this file name still sorts ahead of ConversionContextWiringTest.php.'
		);
	}
}
