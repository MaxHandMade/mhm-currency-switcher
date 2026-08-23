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

		// 🔴 The block checkout does not fire the action above. WooCommerce's
		// Store API builds its orders on a separate path — `woocommerce_checkout_
		// create_order` appears nowhere under its src/StoreApi — and the block
		// checkout has been the default since WooCommerce 8.3. So for the orders
		// most shops now take, the currency and the rate they were placed at
		// were never recorded, while readme.txt told owners to export orders and
		// convert them by exactly that rate.
		//
		// Both actions are needed, not either: `Checkout.php` fires the meta one
		// and the processed one, but `CheckoutOrder.php` — the route that pays
		// for an order that already exists — fires only the processed one.
		// Listening to a single hook would have fixed the common route and left
		// the same hole one door along. Writing the same three values twice is
		// harmless; not writing them at all is not.
		//
		// BlocksCheckoutOrderMetaTest holds these names against WooCommerce's own
		// source, so a rename upstream fails loudly here instead of silently
		// re-opening the hole.
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'save_order_meta_from_store_api' ), 100, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'save_order_meta_from_store_api' ), 100, 1 );
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
			//
			// 🔴 Converted by magnitude, then re-signed. `Converter::convert()`
			// returns anything `<= 0` untouched, which is the right rule for a
			// PRICE — a zero or negative price is not a conversion target — but
			// a fee is not a price. `WC_Cart::add_fee( 'Discount', -50 )` is how
			// third-party plugins apply a cart-level discount, and sending that
			// through the price rule left the discount at its base-currency
			// magnitude while every amount around it was converted: a 50 TRY
			// discount became a 50 USD discount, roughly forty times what the
			// shop intended. In the other direction the customer quietly loses
			// a discount they had earned.
			$amount = (float) $fee->amount;
			$sign   = $amount < 0 ? -1.0 : 1.0;

			$fee->amount = $sign * $this->converter->convert_with_rounding( abs( $amount ), $currency );
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
	public function save_order_meta( $order, $data = null ): void {
		$base    = $this->store->get_base_currency();
		$current = $this->detection->get_current_currency();

		/*
		 * The base currency converts to itself at 1, and saying so is not a
		 * formality. This handler is hooked unconditionally, so it runs for
		 * every order — including the ordinary ones placed in the shop's own
		 * currency — and the base is not a row in the currency list, so
		 * get_rate() answers 0.0 for it. Every base-currency order was
		 * therefore stamped `_mhmcs_exchange_rate = 0`.
		 *
		 * That value is load-bearing: Converter::revert() exists but is
		 * deliberately unused precisely because "the meta records the rate at
		 * purchase time", so a report, an accounting export or a refund that
		 * reconstructs what the customer was charged reads this field and
		 * multiplies or divides by zero.
		 *
		 * The pair is written as one fact about the completed transaction: the
		 * rate always belongs to the code recorded beside it.
		 */
		$rate = $current === $base ? 1.0 : $this->converter->get_rate( $current );

		$order->update_meta_data( '_mhmcs_currency_code', $current );
		$order->update_meta_data( '_mhmcs_exchange_rate', $rate );
		$order->update_meta_data( '_mhmcs_base_currency', $base );
	}

	/**
	 * Record the same currency audit trail for an order created by the Store
	 * API (the Cart & Checkout Blocks).
	 *
	 * Differs from the classic path in one way that matters: on
	 * `woocommerce_checkout_create_order` WooCommerce saves the order for us a
	 * moment later, so writing the meta is enough. The Store API actions fire
	 * at points where the next save is not ours to count on, so this persists
	 * explicitly. `update_meta_data` alone would leave the values in memory and
	 * the order on disk exactly as empty as before the fix — a change that
	 * every in-memory assertion would happily confirm.
	 *
	 * @param \WC_Order $order Order being created or processed.
	 * @return void
	 */
	public function save_order_meta_from_store_api( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->save_order_meta( $order );

		$order->save();
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
