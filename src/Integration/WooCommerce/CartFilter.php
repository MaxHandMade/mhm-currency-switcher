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

use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;

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
	 * Constructor.
	 *
	 * @param Converter        $converter Price conversion engine.
	 * @param CurrencyStore    $store     Currency data store.
	 * @param DetectionService $detection Currency detection service.
	 */
	public function __construct( Converter $converter, CurrencyStore $store, DetectionService $detection ) {
		$this->converter = $converter;
		$this->store     = $store;
		$this->detection = $detection;
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
	}

	/**
	 * Recalculate cart fee amounts in the active currency.
	 *
	 * Iterates over all fees attached to the cart and converts each
	 * fee amount from the base currency to the visitor's currency.
	 * Skips processing entirely when the visitor is using the base currency.
	 *
	 * @param mixed $cart WC_Cart instance.
	 * @return void
	 */
	public function recalculate_fees( $cart ): void {
		if ( $this->detection->is_base_currency() ) {
			return;
		}

		$currency = $this->detection->get_current_currency();
		$fees     = $cart->get_fees();

		foreach ( $fees as $fee ) {
			$fee->amount = $this->converter->convert( (float) $fee->amount, $currency );
		}
	}

	/**
	 * Store currency metadata on the order at checkout.
	 *
	 * Saves the currency code, effective exchange rate, and base
	 * currency so that order display and reporting can reconstruct
	 * the conversion context later.
	 *
	 * @param mixed $order WC_Order instance.
	 * @param mixed $data  Checkout posted data.
	 * @return void
	 */
	public function save_order_meta( $order, $data ): void {
		$current = $this->detection->get_current_currency();
		$rate    = $this->converter->get_rate( $current );

		$order->update_meta_data( '_mhm_cs_currency_code', $current );
		$order->update_meta_data( '_mhm_cs_exchange_rate', $rate );
		$order->update_meta_data( '_mhm_cs_base_currency', $this->store->get_base_currency() );
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
