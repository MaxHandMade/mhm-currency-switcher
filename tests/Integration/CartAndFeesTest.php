<?php
/**
 * Integration tests: a real cart's line totals, fees, and coupon discounts
 * all end up expressed in the same converted currency after
 * WC()->cart->calculate_totals().
 *
 * The unit suite cannot exercise this at all -- WC_Cart, WC_Coupon and fee
 * objects only exist against a real WooCommerce runtime, so this class of
 * "the three amounts silently drift into different currencies" bug is
 * invisible without an integration harness.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use WC_Coupon;

/**
 * Class CartAndFeesTest
 */
class CartAndFeesTest extends MhmcsIntegrationTestCase {

	/**
	 * Fee callback registered per test, removed again in tear_down() so it
	 * cannot leak into unrelated tests/classes that also fire
	 * woocommerce_cart_calculate_fees.
	 *
	 * @var callable|null
	 */
	private $fee_callback;

	/**
	 * Start every test with an empty cart. WC()->cart exists once
	 * WooCommerce's own `init` handling has run, but carries whatever a
	 * previous test in this class left behind unless explicitly cleared.
	 *
	 * Deliberately does NOT call
	 * WC()->session->set_customer_session_cookie() -- WooCommerce's own
	 * cart/coupon calls activate the session as needed, and calling
	 * setcookie() directly here fails under PHPUnit's CLI SAPI ("headers
	 * already sent"), which convertWarningsToExceptions turns into a hard
	 * test error.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		WC()->cart->empty_cart();
	}

	/**
	 * Clean up cart state and any fee callback so nothing leaks between
	 * tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		if ( null !== $this->fee_callback ) {
			remove_action( 'woocommerce_cart_calculate_fees', $this->fee_callback );
			$this->fee_callback = null;
		}

		WC()->cart->empty_cart();

		parent::tear_down();
	}

	/**
	 * Add a product, a coupon, and a fee to a cart in the target currency,
	 * then assert the line total, the fee, and the coupon discount are all
	 * consistently converted -- not just one or two of the three.
	 *
	 * @return void
	 */
	public function test_cart_line_total_fee_and_coupon_are_all_in_the_same_converted_currency(): void {
		$product = $this->create_simple_product( 30.0 );

		$coupon_code = 'mhmcstest5off';
		$coupon      = new WC_Coupon();
		$coupon->set_code( $coupon_code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( 5.0 );
		$coupon->save();

		$this->fee_callback = static function ( $cart ): void {
			$cart->add_fee( 'MHMCS Test Handling', 10.0 );
		};
		add_action( 'woocommerce_cart_calculate_fees', $this->fee_callback );

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->apply_coupon( $coupon_code );
		WC()->cart->calculate_totals();

		// Line subtotal: PriceFilter converts the product's price, and
		// WC_Cart derives line_subtotal from it BEFORE any coupon discount
		// is apportioned. 30 base * 2.0 rate.
		//
		// line_total (as opposed to line_subtotal) is deliberately NOT
		// asserted against a plain "price * rate" figure here: WooCommerce
		// itself subtracts the fixed_cart coupon's (converted) discount
		// from line_total, so a correct, fully-converted cart legitimately
		// shows line_total = 60 - 10 = 50, not 60. Asserting line_subtotal
		// isolates "was the product price itself converted" from "was the
		// coupon discount applied on top of it", which is checked
		// separately below.
		$cart_items = WC()->cart->get_cart();
		$cart_item  = reset( $cart_items );
		$this->assertNotFalse( $cart_item, 'Product must actually be in the cart.' );
		$this->assertEqualsWithDelta(
			60.0,
			(float) $cart_item['line_subtotal'],
			0.01,
			'Cart line subtotal must be converted (30 * 2.0).'
		);

		// Fee: CartFilter::recalculate_fees converts every attached fee.
		// 10 base * 2.0 rate.
		$fees = WC()->cart->get_fees();
		$fee  = reset( $fees );
		$this->assertNotFalse( $fee, 'Fee must actually be attached to the cart.' );
		$this->assertEqualsWithDelta(
			20.0,
			(float) $fee->amount,
			0.01,
			'Cart fee must be converted by CartFilter::recalculate_fees (10 * 2.0).'
		);

		// Coupon: CouponFilter::convert_coupon_amount converts fixed_cart
		// discounts. 5 base * 2.0 rate.
		$applied_coupon = new WC_Coupon( $coupon_code );
		$this->assertEqualsWithDelta(
			10.0,
			(float) $applied_coupon->get_amount(),
			0.01,
			'Coupon amount must be converted by CouponFilter::convert_coupon_amount (5 * 2.0).'
		);

		// Coherence check: the cart total itself must add up using the
		// SAME converted figures as the three checks above (60 subtotal -
		// 10 coupon discount + 20 fee = 70), not a mix of converted and
		// unconverted amounts.
		$this->assertEqualsWithDelta(
			70.0,
			(float) WC()->cart->get_total( 'edit' ),
			0.01,
			'Cart total must reconcile using converted subtotal, fee, and coupon discount together (60 - 10 + 20).'
		);
	}
}
