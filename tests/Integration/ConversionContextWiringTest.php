<?php
/**
 * Integration tests: every conversion surface is bound to the single
 * ConversionContext answer, and the timing rules around that answer hold
 * across a whole request (design spec §2.5, §3.3, §4, §9B).
 *
 * The unit suite cannot see this class of bug at all. It calls the filter
 * objects directly, one at a time, so it can never observe the two failures
 * these tests exist to prevent:
 *
 *   1. Surfaces disagreeing INSIDE one request — a converted symbol printed
 *      next to a base amount, or a cart charging a converted total for a
 *      page that displayed base prices. Only a real WooCommerce runtime runs
 *      PriceFilter, FormatFilter, CouponFilter, ShippingFilter and CartFilter
 *      together against the same product.
 *   2. WooCommerce's variation-price transient handing a later, converted
 *      request the base amounts a earlier display request cached. The
 *      transient, its hash and its static in-process cache only exist inside
 *      real WooCommerce.
 *
 * 🔴 METHOD ORDER IS LOAD-BEARING IN THIS FILE.
 * test_cart_shortcode_outside_assigned_page_flips_latch defines the
 * WOOCOMMERCE_CART constant, exactly as WC_Shortcode_Cart::output() does. A
 * PHP constant cannot be undefined, so from that point on every later test in
 * the whole PHPUnit process sits in a money context. It is therefore declared
 * LAST, and every test above it that asserts base-currency behaviour opens by
 * asserting that the constant is still undefined — so if the order is ever
 * disturbed the failure says why instead of looking like a wiring bug. The
 * test files that sort after this one (PriceHtmlShapes, RestConversion,
 * ShortcodeRender, VariationPrices) all assert converted or REST behaviour,
 * which the constant does not change.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use WC_Coupon;

/**
 * Class ConversionContextWiringTest
 */
class ConversionContextWiringTest extends MhmcsIntegrationTestCase {

	/**
	 * Fee callback registered per test and removed again in tear_down(), so
	 * it cannot leak into unrelated classes that also fire
	 * woocommerce_cart_calculate_fees.
	 *
	 * @var callable|null
	 */
	private $fee_callback;

	/**
	 * Start every test as a brand-new front-end request: before the `wp`
	 * action, with no WooCommerce AJAX action in flight and an empty cart.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->enter_pre_wp_phase();
		$this->leave_money_context();

		WC()->cart->empty_cart();
	}

	/**
	 * Leave no request phase, query parameter, fee callback or cart content
	 * behind.
	 *
	 * @return void
	 */
	public function tear_down() {
		if ( null !== $this->fee_callback ) {
			remove_action( 'woocommerce_cart_calculate_fees', $this->fee_callback );
			$this->fee_callback = null;
		}

		WC()->cart->empty_cart();

		$this->enter_pre_wp_phase();
		$this->leave_money_context();

		parent::tear_down();
	}

	// ─── Display context ─────────────────────────────────────────────

	/**
	 * A catalogue render must leave BOTH halves of price_html in the base
	 * currency — the amount and the symbol.
	 *
	 * Both halves matter together. Audit round 1 found FormatFilter missing
	 * from the design entirely, which would have cached pages showing a base
	 * amount under a converted symbol ("CURX 100.00" for a €200 product):
	 * worse than either surface being wrong on its own, because the page then
	 * misstates the price rather than merely failing to convert it.
	 *
	 * @return void
	 */
	public function test_catalog_display_leaves_amount_and_symbol_in_base(): void {
		$this->assertMoneyConstantsUndefined();

		$product = $this->create_simple_product( 100.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		$this->enter_render_phase();

		$html = wc_get_product( $product->get_id() )->get_price_html();

		$this->assertStringContainsString(
			'100.00',
			$html,
			'A catalogue render must print the BASE amount so the page can be cached for every visitor.'
		);
		$this->assertStringNotContainsString(
			'200.00',
			$html,
			'PriceFilter must ask the context: the converted amount (100 * 2.0) must not reach a cacheable page.'
		);
		$this->assertStringNotContainsString(
			self::TARGET_SYMBOL,
			$html,
			'FormatFilter must ask the SAME context: a converted symbol on a base amount misstates the price.'
		);
	}

	/**
	 * WooCommerce's variation-price transient must be keyed by the CONTEXT,
	 * not merely by the visitor's currency code.
	 *
	 * The failure this locks down (audit round 1 / B2) is silent and total.
	 * A display render of a variable product computes BASE amounts while the
	 * visitor's detected currency is still EUR. If the hash only carried
	 * "EUR", those base amounts would be stored under the EUR key, and every
	 * later converted read — the convert endpoint, a wc-ajax call, the cart —
	 * would get a cache hit and read the base range straight back. Variable
	 * products would then be a permanent no-op in every currency, with no
	 * error anywhere.
	 *
	 * The two prices are deliberately unusual so that a cache collision with
	 * another test class's fixtures fails loudly instead of coincidentally
	 * matching.
	 *
	 * @return void
	 */
	public function test_variation_hash_encodes_context_not_only_currency(): void {
		$this->assertMoneyConstantsUndefined();

		$built      = $this->create_variable_product( array( 11.0, 22.0 ) );
		$product_id = $built['product']->get_id();

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		// Display render: base amounts, and they get cached under whatever
		// key the hash filter produced.
		$this->enter_render_phase();

		$display_range = $this->variation_price_range( $product_id );

		$this->assertEqualsWithDelta( 11.0, $display_range['min'], 0.001, 'Display context must compute the BASE range minimum.' );
		$this->assertEqualsWithDelta( 22.0, $display_range['max'], 0.001, 'Display context must compute the BASE range maximum.' );

		// Money context, same visitor, same currency, nothing saved in
		// between: this can only return converted amounts if the display
		// render above stored its base values under a DIFFERENT hash.
		$this->enter_money_context();

		$money_range = $this->variation_price_range( $product_id );

		$this->assertEqualsWithDelta(
			22.0,
			$money_range['min'],
			0.001,
			'A money-context read must convert the range minimum (11 * 2.0), not read back the base values the display render cached.'
		);
		$this->assertEqualsWithDelta(
			44.0,
			$money_range['max'],
			0.001,
			'A money-context read must convert the range maximum (22 * 2.0), not read back the base values the display render cached.'
		);
	}

	// ─── Timing: the one-way latch (spec §3.3, §9B) ──────────────────

	/**
	 * 🔴 Regression lock, audit round 2 / YB-1.
	 *
	 * WooCommerce validates the cart session on `wp_loaded`, long before the
	 * `wp` action. Conditional tags do not exist yet, so the context answers
	 * "convert" — correctly, because that read may feed real cart totals.
	 *
	 * The bug was memoizing that answer. The early read itself never reached
	 * the HTML, but the DECISION did: the template render later in the SAME
	 * request reused it, converted the whole page, and the cache plugin stored
	 * it. Every visitor with a cart resurrected the original bug.
	 *
	 * So: the early answer must be "convert", the later answer must be
	 * "base", and both must happen inside one request.
	 *
	 * @return void
	 */
	public function test_pre_wp_cart_read_does_not_leak_into_render(): void {
		$this->assertMoneyConstantsUndefined();

		$product = $this->create_simple_product( 100.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		// Phase 1 — `wp` has not fired. A cart-session read must convert.
		$this->assertSame( 0, did_action( 'wp' ), 'Guard: this phase only means anything before the `wp` action.' );

		$early_price = (float) wc_get_product( $product->get_id() )->get_price();

		$this->assertEqualsWithDelta(
			200.0,
			$early_price,
			0.01,
			'A pre-`wp` read must convert (100 * 2.0): it can be feeding a real cart total, and staying in base there would charge the wrong amount.'
		);

		// Phase 2 — same request, now rendering the template.
		$this->enter_render_phase();

		$html = wc_get_product( $product->get_id() )->get_price_html();

		$this->assertStringContainsString(
			'100.00',
			$html,
			'The render must recompute the decision and print the BASE amount.'
		);
		$this->assertStringNotContainsString(
			'200.00',
			$html,
			'The pre-`wp` "convert" answer must not be memoized into the render — that is the root bug returning for every visitor with a cart.'
		);
		$this->assertStringNotContainsString(
			self::TARGET_SYMBOL,
			$html,
			'The leaked decision would have converted the symbol too.'
		);
	}

	// ─── Money context ───────────────────────────────────────────────

	/**
	 * A money context converts both halves — amount and symbol — through the
	 * same decision that keeps the catalogue in base.
	 *
	 * This is the mirror of test_catalog_display_leaves_amount_and_symbol_in_base:
	 * together they prove one shared answer drives both surfaces in both
	 * directions, rather than each surface having its own opinion.
	 *
	 * @return void
	 */
	public function test_cart_context_converts_amount_and_symbol(): void {
		$product = $this->create_simple_product( 100.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		$this->enter_render_phase();
		$this->enter_money_context();

		$html = wc_get_product( $product->get_id() )->get_price_html();

		$this->assertStringContainsString( '200.00', $html, 'A money context must convert the amount (100 * 2.0).' );
		$this->assertStringContainsString( self::TARGET_SYMBOL, $html, 'A money context must convert the symbol as well as the amount.' );
	}

	/**
	 * Fees and coupons must land in the same currency as the line items they
	 * are added to or subtracted from.
	 *
	 * Audit round 3 / H-1: CartFilter::recalculate_fees was left outside the
	 * "one resolver" set. A fee converted on its own detection while the line
	 * items followed the context (or the reverse) produces a cart that adds up
	 * to a number that exists in no currency at all.
	 *
	 * @return void
	 */
	public function test_cart_fees_and_coupons_match_line_item_currency(): void {
		$product = $this->create_simple_product( 30.0 );

		$coupon_code = 'mhmcswiring5off';
		$coupon      = new WC_Coupon();
		$coupon->set_code( $coupon_code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( 5.0 );
		$coupon->save();

		$this->fee_callback = static function ( $cart ): void {
			$cart->add_fee( 'MHMCS Wiring Handling', 10.0 );
		};
		add_action( 'woocommerce_cart_calculate_fees', $this->fee_callback );

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		$this->enter_render_phase();
		$this->enter_money_context();

		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->apply_coupon( $coupon_code );
		WC()->cart->calculate_totals();

		$cart_items = WC()->cart->get_cart();
		$cart_item  = reset( $cart_items );
		$this->assertNotFalse( $cart_item, 'Product must actually be in the cart.' );
		$this->assertEqualsWithDelta(
			60.0,
			(float) $cart_item['line_subtotal'],
			0.01,
			'Line subtotal must follow the money context (30 * 2.0).'
		);

		$fees = WC()->cart->get_fees();
		$fee  = reset( $fees );
		$this->assertNotFalse( $fee, 'Fee must actually be attached to the cart.' );
		$this->assertEqualsWithDelta(
			20.0,
			(float) $fee->amount,
			0.01,
			'CartFilter::recalculate_fees must follow the SAME context as the line items (10 * 2.0).'
		);

		$this->assertEqualsWithDelta(
			10.0,
			(float) ( new WC_Coupon( $coupon_code ) )->get_amount(),
			0.01,
			'CouponFilter must follow the SAME context as the line items (5 * 2.0).'
		);

		// The whole cart has to reconcile out of those three converted
		// figures — 60 subtotal - 10 discount + 20 fee — not a mixture.
		$this->assertEqualsWithDelta(
			70.0,
			(float) WC()->cart->get_total( 'edit' ),
			0.01,
			'Cart total must reconcile from converted subtotal, fee and coupon together.'
		);
	}

	// ─── 🔴 MUST STAY LAST: defines WOOCOMMERCE_CART process-wide ────

	/**
	 * 🔴 Regression lock, audit round 3 / H-2.
	 *
	 * WooCommerce defines WOOCOMMERCE_CART inside the cart shortcode, i.e. in
	 * the MIDDLE of rendering. When that shortcode sits on a page other than
	 * the one WooCommerce has assigned, the page starts out looking like an
	 * ordinary catalogue page: the header mini-cart or a product grid above
	 * the shortcode asks first and correctly gets "base".
	 *
	 * If that "base" answer were memoized, the cart table below would print
	 * base line prices — with no marker on them, so client-side conversion
	 * could never fix it — while `wc-ajax=checkout` charged the converted
	 * total. Show base, charge converted. Hence: a "base" answer is never
	 * latched; only "convert" is.
	 *
	 * @return void
	 */
	public function test_cart_shortcode_outside_assigned_page_flips_latch(): void {
		$product = $this->create_simple_product( 100.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		$this->enter_render_phase();

		// Top of the page, before the shortcode: ordinary display context.
		$before = wc_get_product( $product->get_id() )->get_price_html();

		$this->assertStringContainsString(
			'100.00',
			$before,
			'Guard: the top of the page must genuinely be a base-currency display context, otherwise this test proves nothing about flipping.'
		);
		$this->assertStringNotContainsString( '200.00', $before );

		/*
		 * The shortcode runs. This is the exact call WC_Shortcode_Cart::output()
		 * makes; the rest of that method only renders cart templates, which
		 * would emit markup into the test run without changing the decision.
		 */
		wc_maybe_define_constant( 'WOOCOMMERCE_CART', true );

		$after = wc_get_product( $product->get_id() )->get_price_html();

		$this->assertStringContainsString(
			'200.00',
			$after,
			'Once the cart shortcode declares a money context, the decision must flip to convert (100 * 2.0) — a latched "base" here means "show base, charge converted".'
		);
		$this->assertStringContainsString(
			self::TARGET_SYMBOL,
			$after,
			'The flip must carry the symbol too, or the cart shows a converted amount under the base symbol.'
		);
	}

	// ─── Helpers ─────────────────────────────────────────────────────

	/**
	 * Mark the request as being past the `wp` action, i.e. conditional tags
	 * are available and template rendering has begun.
	 *
	 * did_action() reads $GLOBALS['wp_actions'] directly, and that is the only
	 * thing ConversionContext consults. Setting the counter rather than firing
	 * do_action( 'wp' ) keeps the phase change to exactly the signal under
	 * test: firing the real action would also run every theme, WooCommerce and
	 * core callback hooked there, any of which could define constants or run
	 * queries and quietly change what the assertions below are measuring.
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
	 * Put the request into a WooCommerce money context.
	 *
	 * `wc-ajax` with a non-empty value is the branch every payment gateway's
	 * own endpoint arrives through, and unlike the WOOCOMMERCE_CART constant
	 * it is reversible, so tests using it stay isolated from one another.
	 *
	 * @return void
	 */
	private function enter_money_context(): void {
		$_GET['wc-ajax'] = 'get_refreshed_fragments';
	}

	/**
	 * Leave the WooCommerce money context.
	 *
	 * @return void
	 */
	private function leave_money_context(): void {
		unset( $_GET['wc-ajax'] );
	}

	/**
	 * Assert that no test has yet defined WooCommerce's cart/checkout
	 * constants, which would put the whole process into a money context.
	 *
	 * @return void
	 */
	private function assertMoneyConstantsUndefined(): void {
		$this->assertFalse(
			defined( 'WOOCOMMERCE_CART' ) || defined( 'WOOCOMMERCE_CHECKOUT' ),
			'This test asserts base-currency behaviour, so it must run BEFORE test_cart_shortcode_outside_assigned_page_flips_latch, which defines WOOCOMMERCE_CART for the rest of the process. Check the method declaration order in this file.'
		);
	}

	/**
	 * Read a variable product's min/max price range from a freshly loaded
	 * product object, so nothing is served from a per-object cache.
	 *
	 * @param int $product_id Variable product ID.
	 * @return array{min: float, max: float}
	 */
	private function variation_price_range( int $product_id ): array {
		$prices = wc_get_product( $product_id )->get_variation_prices();
		$range  = array_map( 'floatval', array_values( $prices['price'] ) );

		return array(
			'min' => min( $range ),
			'max' => max( $range ),
		);
	}
}
