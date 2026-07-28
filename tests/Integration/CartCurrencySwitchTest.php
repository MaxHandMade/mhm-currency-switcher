<?php
/**
 * Integration tests: the cart's stored totals follow the visitor when they
 * change currency without reloading the page.
 *
 * 🔴 This is a v1.1 regression the unit suite cannot see and the browser round
 * found. Until v1.1 the switcher called location.reload(), so the next request
 * rebuilt the page — and with it the cart totals — in the newly chosen
 * currency. The cache-friendly switcher deliberately does not reload; the next
 * thing that renders money is WooCommerce's cart-fragment refresh, which loads
 * the cart from the session and renders the mini-cart from totals that were
 * calculated in the PREVIOUS currency. The line item is filtered on the way out
 * and comes back converted, but the subtotal is a stored number, so the
 * mini-cart showed the old currency's amount wearing the new currency's symbol
 * (measured: "Subtotal: 36,80 ₺", where 36.80 was the EUR total).
 *
 * Nothing is mischarged — the cart page and checkout recalculate — but a
 * visibly mixed currency on the cart surface is exactly the failure this
 * feature exists to remove.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use MhmCurrencySwitcher\Integration\WooCommerce\CartFilter;
use WP_REST_Request;

/**
 * Class CartCurrencySwitchTest
 */
class CartCurrencySwitchTest extends MhmcsIntegrationTestCase {

	/**
	 * Second currency, so the test switches between two converted currencies
	 * rather than in and out of the base — the shape a real switcher produces.
	 *
	 * @var string
	 */
	private const OTHER_CURRENCY = 'GBP';

	/**
	 * Rate of OTHER_CURRENCY. Deliberately unlike TARGET_RATE so a stale
	 * total cannot coincidentally equal the fresh one.
	 *
	 * @var float
	 */
	private const OTHER_RATE = 5.0;

	/**
	 * Start each test with an empty cart and both currencies enabled.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->configure_both_currencies();

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
	}

	/**
	 * Leave no cart or cookie behind.
	 *
	 * @return void
	 */
	public function tear_down() {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'mhmcs_totals_currency', null );
		}

		$this->clear_visitor_currency();

		parent::tear_down();
	}

	/**
	 * Enable TARGET_CURRENCY and OTHER_CURRENCY together.
	 *
	 * The base helper configures exactly one currency, and this test needs to
	 * move between two. Goes through the same REST route for the same reason
	 * the base helper does: CurrencyStore caches its list on first read, so a
	 * direct update_option() would be invisible to the already-booted filters.
	 *
	 * @return void
	 */
	private function configure_both_currencies(): void {
		$make = static function ( string $code, float $rate, string $symbol ): array {
			return array(
				'code'     => $code,
				'enabled'  => true,
				'rate'     => array(
					'type'  => 'manual',
					'value' => $rate,
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
					'symbol'       => $symbol,
					'decimals'     => 2,
					'decimal_sep'  => '.',
					'thousand_sep' => ',',
					'position'     => 'left',
				),
			);
		};

		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/mhmcs/v1/currencies' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'base_currency' => 'USD',
					'currencies'    => array(
						$make( self::TARGET_CURRENCY, self::TARGET_RATE, self::TARGET_SYMBOL ),
						$make( self::OTHER_CURRENCY, self::OTHER_RATE, '£' ),
					),
				)
			)
		);

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$this->fail(
				'Failed to configure the two test currencies: '
				. (string) wp_json_encode( $response->get_data() )
			);
		}

		wp_set_current_user( 0 );
	}

	/**
	 * Reproduce a cart-fragment refresh: the cart is read back out of the
	 * session on a fresh request, which is the moment the mini-cart is
	 * rendered from stored totals.
	 *
	 * @return void
	 */
	private function reload_cart_from_session(): void {
		do_action( 'woocommerce_cart_loaded_from_session', WC()->cart );
	}

	/**
	 * Changing currency without reloading leaves the stored totals stale.
	 *
	 * @return void
	 */
	public function test_switching_currency_recalculates_the_stored_cart_totals(): void {
		$product = $this->create_simple_product( 40.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->calculate_totals();

		$this->assertSame(
			80.0,
			(float) WC()->cart->get_subtotal(),
			'Precondition: 40 at rate 2.0 is 80 in the first currency.'
		);

		// The visitor picks another currency. No page reload happens in cache
		// compatibility mode, so this is all the server sees before the
		// mini-cart is rendered again.
		$this->set_visitor_currency( self::OTHER_CURRENCY );
		$this->reload_cart_from_session();

		$this->assertSame(
			200.0,
			(float) WC()->cart->get_subtotal(),
			'After switching to a 5.0-rate currency the stored subtotal must be '
			. '200, not the previous currency\'s 80 wearing a new symbol.'
		);
	}

	/**
	 * Switching back to the base currency recalculates too.
	 *
	 * The base currency is the one case where "convert" does nothing, so a fix
	 * that only fires between two converted currencies would leave the visitor
	 * who returns to the base looking at the last converted total.
	 *
	 * @return void
	 */
	public function test_switching_back_to_the_base_currency_recalculates(): void {
		$product = $this->create_simple_product( 40.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->calculate_totals();

		$this->assertSame( 80.0, (float) WC()->cart->get_subtotal() );

		$this->clear_visitor_currency();
		$this->reload_cart_from_session();

		$this->assertSame(
			40.0,
			(float) WC()->cart->get_subtotal(),
			'Back on the base currency the subtotal is the unconverted price.'
		);
	}

	/**
	 * 🔴 A visitor who has not changed anything costs no extra recalculation.
	 *
	 * The hook this fix uses runs on every request that loads a cart from the
	 * session — which, on a shop whose whole point is being served from a page
	 * cache, is every add-to-cart, every fragment refresh and every cart view.
	 * Recalculating there unconditionally would put a full totals pass on all
	 * of them, so "the currency is unchanged" has to be a real early exit and
	 * not an accident of the arithmetic coming out the same.
	 *
	 * @return void
	 */
	public function test_an_unchanged_currency_does_not_recalculate(): void {
		$product = $this->create_simple_product( 40.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->calculate_totals();
		$this->reload_cart_from_session();

		$calculations = 0;
		$spy          = static function () use ( &$calculations ) {
			++$calculations;
		};

		add_action( 'woocommerce_cart_calculate_fees', $spy, 1 );
		$this->reload_cart_from_session();
		remove_action( 'woocommerce_cart_calculate_fees', $spy, 1 );

		$this->assertSame(
			0,
			$calculations,
			'Loading the cart again on the same currency must not recalculate.'
		);
	}

	/**
	 * An empty cart is not recalculated, however much the currency moves.
	 *
	 * 🔴 Added because mutation testing found the guard unlocked: deleting the
	 * `is_empty()` check left all 102 tests green. There is nothing to convert
	 * in an empty cart, and this hook runs on every request that loads one.
	 *
	 * @return void
	 */
	public function test_an_empty_cart_is_not_recalculated_on_a_currency_change(): void {
		// A session that once held a converted cart and was then emptied: the
		// recorded currency survives, the contents do not.
		WC()->session->set( CartFilter::TOTALS_CURRENCY_KEY, self::TARGET_CURRENCY );
		$this->set_visitor_currency( self::OTHER_CURRENCY );

		$resets = 0;
		$spy    = static function () use ( &$resets ) {
			++$resets;
		};

		/*
		 * Spies on `woocommerce_cart_reset`, not on the totals hooks. Measured
		 * against WooCommerce's source: WC_Cart::calculate_totals() calls
		 * reset_totals() and then returns for an empty cart, BEFORE firing
		 * woocommerce_after_calculate_totals (class-wc-cart.php:1533-1545), so
		 * a totals spy cannot tell the two paths apart and a test built on one
		 * passes whether or not the guard exists. reset_totals() does fire
		 * woocommerce_cart_reset, which is the one signal that separates them.
		 */
		add_action( 'woocommerce_cart_reset', $spy, 1 );
		$this->reload_cart_from_session();
		remove_action( 'woocommerce_cart_reset', $spy, 1 );

		$this->assertSame(
			0,
			$resets,
			'An empty cart has nothing to recalculate, so nothing should be reset.'
		);
	}

	/**
	 * A session with no recorded currency is left alone.
	 *
	 * 🔴 Also found unlocked by mutation testing. This is the first request of
	 * every visitor, and every session that predates this version: there is no
	 * total of ours to be stale, so recalculating on the strength of not
	 * knowing would put a full totals pass on all of them.
	 *
	 * @return void
	 */
	public function test_a_session_with_no_recorded_currency_is_not_recalculated(): void {
		$product = $this->create_simple_product( 40.0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->calculate_totals();

		// Adding to the cart records the currency; unset it to reproduce a
		// session that has never been through this version of the plugin.
		WC()->session->set( CartFilter::TOTALS_CURRENCY_KEY, null );

		$calculations = $this->count_recalculations();

		$this->assertSame(
			0,
			$calculations(),
			'With nothing recorded there is no stale total to correct.'
		);
	}

	/**
	 * Count the totals passes triggered by one cart load from the session.
	 *
	 * Counts `woocommerce_after_calculate_totals` rather than the fee hook:
	 * it fires at the end of every calculate_totals() call whether or not the
	 * cart has fees or contents, so an empty cart cannot make the assertion
	 * pass by accident.
	 *
	 * @return callable(): int Reader for the number of recalculations seen.
	 */
	private function count_recalculations(): callable {
		$calculations = 0;
		$spy          = static function () use ( &$calculations ) {
			++$calculations;
		};

		add_action( 'woocommerce_after_calculate_totals', $spy, 1 );
		$this->reload_cart_from_session();
		remove_action( 'woocommerce_after_calculate_totals', $spy, 1 );

		return static function () use ( &$calculations ): int {
			return $calculations;
		};
	}
}
