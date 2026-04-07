<?php
/**
 * WooCommerce order filter — displays order totals in the purchase currency.
 *
 * Hooks into WooCommerce order display filters to format amounts
 * using the currency that was active at checkout, and ensures
 * order-related emails use the correct currency symbol and format.
 *
 * @package MhmCurrencySwitcher\Integration\WooCommerce
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Integration\WooCommerce;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;

/**
 * OrderFilter — order display and email currency formatting.
 *
 * Reads the `_mhm_cs_currency_code` meta stored at checkout by
 * CartFilter and overrides WooCommerce formatting hooks so that
 * order totals, subtotals, and email amounts display in the
 * currency the customer used.
 *
 * @since 0.2.0
 */
final class OrderFilter {

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
	 * Order being processed in the current email context.
	 *
	 * Temporarily set during `woocommerce_email_order_details` to
	 * provide the order's currency code to the `woocommerce_currency`
	 * filter within the email template.
	 *
	 * @var mixed|null
	 */
	private $email_order = null;

	/**
	 * Constructor.
	 *
	 * @param CurrencyStore    $store     Currency data store.
	 * @param DetectionService $detection Currency detection service.
	 */
	public function __construct( CurrencyStore $store, DetectionService $detection ) {
		$this->store     = $store;
		$this->detection = $detection;
	}

	/**
	 * Register WooCommerce order display and email hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'woocommerce_get_order_item_totals', array( $this, 'format_order_totals' ), 100, 3 );
		add_filter( 'woocommerce_order_subtotal_to_display', array( $this, 'format_order_subtotal' ), 100, 3 );

		// Email support: set order context before email details render.
		add_action( 'woocommerce_email_order_details', array( $this, 'set_email_order_context' ), 5, 4 );
		add_filter( 'woocommerce_currency', array( $this, 'override_email_currency' ), 200, 1 );
	}

	/**
	 * Format order total rows with the order's purchase currency.
	 *
	 * Reads `_mhm_cs_currency_code` from the order meta. When the
	 * order was placed in a non-base currency, each total row's
	 * `value` is reformatted with the correct currency symbol.
	 *
	 * @param array<string, array<string, string>> $total_rows Array of total rows (key => label/value).
	 * @param mixed                                $order      WC_Order instance.
	 * @param string                               $tax_display Tax display mode ('excl' or 'incl').
	 * @return array<string, array<string, string>> Modified total rows.
	 */
	public function format_order_totals( array $total_rows, $order, $tax_display ): array {
		$order_currency = self::get_order_currency( $order );

		if ( null === $order_currency ) {
			return $total_rows;
		}

		if ( $order_currency === $this->store->get_base_currency() ) {
			return $total_rows;
		}

		$currency_data = $this->store->get_currency( $order_currency );

		if ( null === $currency_data || ! isset( $currency_data['format'] ) ) {
			return $total_rows;
		}

		$symbol = $currency_data['format']['symbol'] ?? $order_currency;

		foreach ( $total_rows as $key => $row ) {
			if ( isset( $row['value'] ) ) {
				$total_rows[ $key ]['value'] = $this->replace_currency_symbol( $row['value'], $symbol );
			}
		}

		return $total_rows;
	}

	/**
	 * Format the order subtotal display with the purchase currency.
	 *
	 * @param string $subtotal    Formatted subtotal HTML string.
	 * @param mixed  $order       WC_Order instance.
	 * @param string $tax_display Tax display mode.
	 * @return string Modified subtotal, or original when no currency meta.
	 */
	public function format_order_subtotal( string $subtotal, $order, string $tax_display ): string {
		$order_currency = self::get_order_currency( $order );

		if ( null === $order_currency ) {
			return $subtotal;
		}

		if ( $order_currency === $this->store->get_base_currency() ) {
			return $subtotal;
		}

		$currency_data = $this->store->get_currency( $order_currency );

		if ( null === $currency_data || ! isset( $currency_data['format'] ) ) {
			return $subtotal;
		}

		$symbol = $currency_data['format']['symbol'] ?? $order_currency;

		return $this->replace_currency_symbol( $subtotal, $symbol );
	}

	/**
	 * Store the order reference for email currency context.
	 *
	 * Called early in `woocommerce_email_order_details` to capture
	 * the order being rendered so that `override_email_currency()`
	 * can return the correct currency code.
	 *
	 * @param mixed $order         WC_Order instance.
	 * @param bool  $sent_to_admin Whether the email is for admin.
	 * @param bool  $plain_text    Whether plain text email.
	 * @param mixed $email         WC_Email instance.
	 * @return void
	 */
	public function set_email_order_context( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		$this->email_order = $order;
	}

	/**
	 * Override the WooCommerce currency in email context.
	 *
	 * When an order email is being rendered and the order has
	 * currency meta, returns the order's currency code instead
	 * of the store default.
	 *
	 * @param string $currency Current WooCommerce currency code.
	 * @return string Order currency code, or original when not in email context.
	 */
	public function override_email_currency( string $currency ): string {
		if ( null === $this->email_order ) {
			return $currency;
		}

		$order_currency = self::get_order_currency( $this->email_order );

		if ( null === $order_currency ) {
			return $currency;
		}

		return $order_currency;
	}

	/**
	 * Get the currency code stored on an order by CartFilter.
	 *
	 * Reads the `_mhm_cs_currency_code` meta key that was saved
	 * during checkout. Returns null when the meta is not present
	 * (e.g. orders placed before the plugin was active).
	 *
	 * @param mixed $order WC_Order instance.
	 * @return string|null ISO 4217 currency code, or null when not set.
	 */
	public static function get_order_currency( $order ): ?string {
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		$code = $order->get_meta( '_mhm_cs_currency_code', true );

		if ( empty( $code ) ) {
			return null;
		}

		return (string) $code;
	}

	/**
	 * Replace the currency symbol in a formatted price string.
	 *
	 * This is a simple helper that prepends the target currency
	 * symbol by stripping known base currency symbols from the
	 * formatted value. When the exact symbol cannot be located,
	 * the target symbol is prepended.
	 *
	 * @param string $formatted Formatted price HTML/string.
	 * @param string $symbol    Target currency symbol to display.
	 * @return string Modified formatted string.
	 */
	private function replace_currency_symbol( string $formatted, string $symbol ): string {
		$base_currency = $this->store->get_base_currency();
		$base_data     = $this->store->get_currency( $base_currency );

		if ( null !== $base_data && isset( $base_data['format']['symbol'] ) ) {
			$base_symbol = $base_data['format']['symbol'];

			if ( strpos( $formatted, $base_symbol ) !== false ) {
				return str_replace( $base_symbol, $symbol, $formatted );
			}
		}

		// Fallback: the formatted string likely already has a symbol from WC.
		// Return as-is since we cannot reliably detect the original symbol.
		return $formatted;
	}
}
