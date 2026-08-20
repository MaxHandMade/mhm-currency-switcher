<?php
/**
 * Integration tests: a negative cart fee is a discount, and it must be
 * converted like every other amount the customer is charged.
 *
 * `Converter::convert()` returns any amount `<= 0` untouched. For a PRICE that
 * is the right rule — a zero or negative price is not a conversion target.
 * `CartFilter::recalculate_fees()` sends fee amounts through the same door,
 * and `WC_Cart::add_fee( 'Discount', -50 )` is the standard way third-party
 * plugins apply a cart-level discount.
 *
 * The result is a discount that stays at its base-currency magnitude while
 * everything around it is converted. In a TRY shop viewed in USD, a 50 TRY
 * discount becomes a 50 USD discount — the shop gives away roughly forty times
 * what it meant to. In the other direction the customer silently loses a
 * discount they had earned.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

/**
 * Class NegativeFeeConversionTest
 */
class NegativeFeeConversionTest extends MhmcsIntegrationTestCase {

	/**
	 * Callback registered on the fee hook, kept so it can be removed again.
	 *
	 * @var callable|null
	 */
	private $fee_callback;

	/**
	 * Remove the fee callback and empty the cart between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		if ( $this->fee_callback ) {
			remove_action( 'woocommerce_cart_calculate_fees', $this->fee_callback );
			$this->fee_callback = null;
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		parent::tear_down();
	}

	/**
	 * Put one product and one fee of the given amount into a converted cart.
	 *
	 * @param float $fee_amount Fee amount in base currency; negative for a discount.
	 * @return float The fee amount the cart ended up with.
	 */
	private function fee_after_conversion( float $fee_amount ): float {
		$product = $this->create_simple_product( 30.0 );

		$this->fee_callback = static function ( $cart ) use ( $fee_amount ): void {
			$cart->add_fee( 'MHMCS Test Discount', $fee_amount );
		};
		add_action( 'woocommerce_cart_calculate_fees', $this->fee_callback );

		$this->set_visitor_currency( self::TARGET_CURRENCY );

		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->calculate_totals();

		$fees = WC()->cart->get_fees();
		$fee  = reset( $fees );

		$this->assertNotFalse( $fee, 'The fee must actually be on the cart.' );

		return (float) $fee->amount;
	}

	/**
	 * The control: a positive fee is already converted, and must stay that way.
	 *
	 * @return void
	 */
	public function test_a_positive_fee_is_converted(): void {
		$this->assertEqualsWithDelta(
			20.0,
			$this->fee_after_conversion( 10.0 ),
			0.01,
			'A positive fee is no longer converted (10 base * 2.0 rate).'
		);
	}

	/**
	 * The defect: a discount expressed as a negative fee must be converted by
	 * the same rate, keeping its sign.
	 *
	 * @return void
	 */
	public function test_a_negative_fee_is_converted_like_a_discount(): void {
		$this->assertEqualsWithDelta(
			-20.0,
			$this->fee_after_conversion( -10.0 ),
			0.01,
			'A negative fee (a discount) was left at its base-currency magnitude while the rest of the cart was converted, so the discount is worth 1/rate of what it should be.'
		);
	}
}
