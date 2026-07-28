<?php
/**
 * WooCommerce cart filter — converts cart fees and stores order currency meta.
 *
 * Hooks into WooCommerce cart and checkout actions to ensure fees
 * are converted to the active currency and order metadata captures
 * the currency state at time of purchase.
 *
 * @package MhmCurrencySwitcher\Integration\WooCommerce
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Integration\WooCommerce;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MhmCurrencySwitcher\Core\ConversionContext;
use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;
use WC_Cart;

/**
 * CartFilter — cart fee conversion, order meta storage, and cart recalculation.
 *
 * Registers actions on cart fee calculation, checkout order creation,
 * and add-to-cart events to keep the cart consistent when the visitor
 * is browsing in a non-base currency.
 *
 * @since 0.2.0
 */
final class CartFilter {

	/**
	 * Session key holding the currency the stored cart totals belong to.
	 *
	 * @var string
	 */
	const TOTALS_CURRENCY_KEY = 'mhmcs_totals_currency';

	/**
	 * Price conversion engine.
	 *
	 * @var Converter
	 */
	private Converter $converter;

	/**
	 * Currency data store.
	 *
	 * @var CurrencyStore
	 */
	private CurrencyStore $store;

	/**
	 * Currency detection service.
	 *
	 * @var DetectionService
	 */
	private DetectionService $detection;

	/**
	 * Shared conversion-context resolver.
	 *
	 * @var ConversionContext
	 */
	private ConversionContext $context;

	/**
	 * Constructor.
	 *
	 * @param Converter         $converter Price conversion engine.
	 * @param CurrencyStore     $store     Currency data store.
	 * @param DetectionService  $detection Currency detection service.
	 * @param ConversionContext $context   Shared conversion-context resolver.
	 */
	public function __construct( Converter $converter, CurrencyStore $store, DetectionService $detection, ConversionContext $context ) {
		$this->converter = $converter;
		$this->store     = $store;
		$this->detection = $detection;
		$this->context   = $context;
	}

	/**
	 * Register WooCommerce cart and checkout hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'recalculate_fees' ), 100, 1 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_meta' ), 100, 2 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'maybe_recalculate_cart' ), 100, 0 );
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'remember_totals_currency' ), 100, 0 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'maybe_recalculate_for_currency_change' ), 100, 1 );
	}

	/**
	 * Record the currency the cart totals have just been calculated in.
	 *
	 * The totals WooCommerce persists in the session are plain numbers with no
	 * memory of how they were produced. Storing the currency alongside them is
	 * what lets the next request tell a fresh total from a stale one.
	 *
	 * @return void
	 */
	public function remember_totals_currency(): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::TOTALS_CURRENCY_KEY, $this->totals_currency() );
		}
	}

	/**
	 * The currency the totals that have just been calculated are expressed in.
	 *
	 * 🔴 Asks the context, not detection, and the difference is the whole
	 * point. Detection answers "which currency does this visitor want", which
	 * is NOT the same question. A recalculation triggered from a display
	 * context — a theme header total, an ajax-cart plugin, anything that calls
	 * calculate_totals() during a cacheable render — is answered "base" by the
	 * decision table, so the amounts come out unconverted. Recording the
	 * visitor's currency there would label base numbers as converted, and the
	 * next money-context render would compare stored against detected, find
	 * them equal, skip the correction and print base amounts under the
	 * visitor's symbol. That is the defect this whole mechanism exists to
	 * prevent, re-entered through a side door; found by the pre-release audit.
	 *
	 * @return string ISO 4217 code the stored totals belong to.
	 */
	private function totals_currency(): string {
		if ( ! $this->context->should_convert() ) {
			return $this->store->get_base_currency();
		}

		return $this->current_currency();
	}

	/**
	 * Recalculate stored totals when the visitor changed currency.
	 *
	 * 🔴 v1.1 regression this exists to close. Until the cache-friendly
	 * switcher, changing currency reloaded the page, so the next request
	 * rebuilt the totals in the new currency as a side effect. The switcher no
	 * longer reloads, and the first thing to render money afterwards is
	 * WooCommerce's cart-fragment refresh, which loads the cart from the
	 * session. Line items are filtered on the way out and come back converted;
	 * the subtotal is a stored number and does not. The mini-cart therefore
	 * showed the previous currency's amount formatted with the new currency's
	 * symbol — measured in the browser as "Subtotal: 36,80 ₺", where 36.80 was
	 * the EUR figure. The cart page and checkout recalculate on their own, so
	 * nothing was ever mischarged; what was wrong was visible, and on the one
	 * surface this feature exists to keep correct.
	 *
	 * Hooked on the load rather than the render so every surface built from
	 * these totals — mini-cart, fragments, shortcodes, Store API — sees the
	 * corrected figures, instead of one of them being patched.
	 *
	 * @param mixed $cart The cart being loaded from the session.
	 * @return void
	 */
	public function maybe_recalculate_for_currency_change( $cart ): void {
		if ( ! $cart instanceof WC_Cart || $cart->is_empty() ) {
			return;
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			$stored = WC()->session->get( self::TOTALS_CURRENCY_KEY );

			/*
			 * Nothing recorded yet means no totals of ours are in play — a
			 * first visit, or a session from before this version. There is no
			 * stale figure to correct, and recalculating on the strength of
			 * not knowing would put a full totals pass on the first request of
			 * every visitor.
			 */
			if ( ! is_string( $stored ) || '' === $stored ) {
				return;
			}

			if ( $stored === $this->current_currency() ) {
				return;
			}

			$cart->calculate_totals();
		}
	}

	/**
	 * The currency this visitor has chosen.
	 *
	 * Detection, deliberately: the comparison in
	 * maybe_recalculate_for_currency_change() runs while the cart loads, long
	 * before the `wp` action, and asking the context there would get "convert"
	 * from the pre-`wp` branch on every request — an answer it does not commit
	 * to and that says nothing about which currency is wanted.
	 *
	 * @return string ISO 4217 code; the base currency when nothing is chosen.
	 */
	private function current_currency(): string {
		$code = $this->detection->detect_currency();

		if ( ! is_string( $code ) || '' === $code ) {
			return $this->store->get_base_currency();
		}

		return $code;
	}

	/**
	 * Recalculate cart fee amounts in the active currency.
	 *
	 * Iterates over all fees attached to the cart and converts each
	 * fee amount from the base currency to the visitor's currency.
	 * Skips processing entirely when the visitor is using the base currency.
	 *
	 * Asks the shared context rather than detection alone: a fee converted on
	 * its own judgement while the line items it is added to followed the
	 * context (or the reverse) produces a cart total that is correct in no
	 * currency at all.
	 *
	 * @param mixed $cart WC_Cart instance.
	 * @return void
	 */
	public function recalculate_fees( $cart ): void {
		if ( ! $this->context->should_convert() ) {
			return;
		}

		if ( $this->detection->is_base_currency() ) {
			return;
		}

		$currency = $this->detection->get_current_currency();
		$fees     = $cart->get_fees();

		foreach ( $fees as $fee ) {
			// Rounded — a fee is part of what the customer pays, so it follows
			// the same rule as the line items it sits beside.
			$fee->amount = $this->converter->convert_with_rounding( (float) $fee->amount, $currency );
		}
	}

	/**
	 * Store currency metadata on the order at checkout.
	 *
	 * Saves the currency code, effective exchange rate, and base
	 * currency so that order display and reporting can reconstruct
	 * the conversion context later.
	 *
	 * Deliberately NOT bound to ConversionContext (design spec §3.5). This
	 * records the currency the customer was ACTUALLY charged in, which is a
	 * fact about the completed transaction, not a question about how the
	 * current request should render. Asking the context here would let a
	 * rendering decision rewrite the historical record on the order.
	 *
	 * @param mixed $order WC_Order instance.
	 * @param mixed $data  Checkout posted data.
	 * @return void
	 */
	public function save_order_meta( $order, $data ): void {
		$current = $this->detection->get_current_currency();
		$rate    = $this->converter->get_rate( $current );

		$order->update_meta_data( '_mhmcs_currency_code', $current );
		$order->update_meta_data( '_mhmcs_exchange_rate', $rate );
		$order->update_meta_data( '_mhmcs_base_currency', $this->store->get_base_currency() );
	}

	/**
	 * Force cart recalculation when a product is added.
	 *
	 * Ensures totals reflect the active currency after items are
	 * added to the cart in a non-base currency session.
	 *
	 * @return void
	 */
	public function maybe_recalculate_cart(): void {
		if ( $this->detection->is_base_currency() ) {
			return;
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->calculate_totals();
		}
	}
}
