<?php
/**
 * WooCommerce coupon filter — converts fixed coupon amounts to active currency.
 *
 * Hooks into WooCommerce coupon amount filters to convert fixed-value
 * discounts and min/max thresholds when the visitor is browsing in a
 * non-base currency. Percentage coupons are currency-agnostic and
 * are not modified.
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
use MhmCurrencySwitcher\Core\DetectionService;

/**
 * CouponFilter — coupon amount and threshold conversion hooks.
 *
 * Registers filters at priority 100 on WooCommerce coupon getter hooks
 * so that fixed-value discount amounts and minimum/maximum spend
 * thresholds are converted to the visitor's selected currency.
 *
 * @since 0.2.0
 */
final class CouponFilter {

	/**
	 * Discount types that represent fixed monetary amounts.
	 *
	 * @var array<int, string>
	 */
	const FIXED_DISCOUNT_TYPES = array( 'fixed_cart', 'fixed_product' );

	/**
	 * Price conversion engine.
	 *
	 * @var Converter
	 */
	private Converter $converter;

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
	 * @param DetectionService  $detection Currency detection service.
	 * @param ConversionContext $context   Shared conversion-context resolver.
	 */
	public function __construct( Converter $converter, DetectionService $detection, ConversionContext $context ) {
		$this->converter = $converter;
		$this->detection = $detection;
		$this->context   = $context;
	}

	/**
	 * Register WooCommerce coupon amount hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'woocommerce_coupon_get_amount', array( $this, 'convert_coupon_amount' ), 100, 2 );
		add_filter( 'woocommerce_coupon_get_minimum_amount', array( $this, 'convert_min_max_amount' ), 100, 2 );
		add_filter( 'woocommerce_coupon_get_maximum_amount', array( $this, 'convert_min_max_amount' ), 100, 2 );
	}

	/**
	 * Convert a coupon's discount amount for fixed-value types only.
	 *
	 * Percentage coupons are currency-agnostic (10% off is 10% regardless
	 * of currency) and are returned unchanged. Only `fixed_cart` and
	 * `fixed_product` discount types are converted.
	 *
	 * @param string|float $amount Coupon discount amount.
	 * @param mixed        $coupon WC_Coupon instance.
	 * @return string|float Converted amount, or original for percentage coupons.
	 */
	public function convert_coupon_amount( $amount, $coupon ) {
		if ( ! $this->context->should_convert() ) {
			return $amount;
		}

		if ( $this->detection->is_base_currency() ) {
			return $amount;
		}

		$discount_type = $coupon->get_discount_type();

		if ( ! in_array( $discount_type, self::FIXED_DISCOUNT_TYPES, true ) ) {
			return $amount;
		}

		$currency = $this->detection->get_current_currency();

		// Rounded: a fixed discount is money coming off the total, so it obeys
		// the same rule as the amounts it is subtracted from. The threshold
		// below deliberately does not — see that method.
		return $this->converter->convert_with_rounding( (float) $amount, $currency );
	}

	/**
	 * Convert a coupon's minimum or maximum spend threshold.
	 *
	 * Min/max amounts are always fixed monetary values regardless
	 * of the discount type, so they are always converted.
	 *
	 * 🔴 Converted but NOT rounded, and that asymmetry with every other amount
	 * in this plugin is deliberate. The rounding rule exists to make the prices
	 * a customer is charged tidy; a spend threshold is not an amount anybody
	 * pays, it is the line at which the merchant's coupon begins to apply.
	 * Rounding it moves that line — a coupon written to need 123 would start
	 * applying at 4.0 rather than 3.69 — which edits a rule the merchant set
	 * rather than tidying a price they show. The exchange rate already moves it
	 * as little as it can. RoundingConsistencyTest locks this as an exception so
	 * that the next audit of the rounding class reads it as a decision.
	 *
	 * @param string|float $amount Threshold amount.
	 * @param mixed        $coupon WC_Coupon instance (unused but required by hook).
	 * @return string|float Converted threshold, or original when empty or base currency.
	 */
	public function convert_min_max_amount( $amount, $coupon ) {
		if ( ! $this->context->should_convert() ) {
			return $amount;
		}

		if ( $this->detection->is_base_currency() ) {
			return $amount;
		}

		if ( '' === $amount || 0.0 === (float) $amount ) {
			return $amount;
		}

		$currency = $this->detection->get_current_currency();

		return $this->converter->convert( (float) $amount, $currency );
	}
}
