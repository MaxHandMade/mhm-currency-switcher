<?php
/**
 * WooCommerce shipping filter — converts shipping rate costs to active currency.
 *
 * Hooks into WooCommerce package rates filter to convert shipping
 * costs when the visitor is browsing in a non-base currency.
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
 * ShippingFilter — shipping rate cost conversion hooks.
 *
 * Registers a filter at priority 100 on `woocommerce_package_rates`
 * so that shipping costs are converted from the base currency to the
 * visitor's selected currency.
 *
 * @since 0.2.0
 */
final class ShippingFilter {

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
	 * Register the WooCommerce shipping rates filter.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'woocommerce_package_rates', array( $this, 'convert_shipping_rates' ), 100, 2 );
	}

	/**
	 * Convert shipping rate costs to the active currency.
	 *
	 * Iterates over all available shipping rates in the package and
	 * converts each rate's cost from the base currency. Skips when
	 * the visitor is using the base currency.
	 *
	 * @param array<string, mixed> $rates   Array of WC_Shipping_Rate objects.
	 * @param array<string, mixed> $package Package data (unused but required by hook).
	 * @return array<string, mixed> Modified rates array.
	 */
	public function convert_shipping_rates( array $rates, array $package ): array {
		if ( ! $this->context->should_convert() ) {
			return $rates;
		}

		if ( $this->detection->is_base_currency() ) {
			return $rates;
		}

		$currency = $this->detection->get_current_currency();

		foreach ( $rates as $rate ) {
			// Rounded, like every other amount that ends up in the cart total.
			// Converting this one straight through left the total carrying
			// cents the shop's rounding rule had removed from the line items.
			$rate->cost = $this->converter->convert_with_rounding( (float) $rate->cost, $currency );

			/*
			 * The tax lines travel with the cost. Leaving them behind put a
			 * base-currency amount into a cart priced in another one, and
			 * WooCommerce adds it to the total exactly as it finds it — so the
			 * customer paid base-currency tax on converted shipping.
			 *
			 * Converted without rounding, for the same reason the coupon
			 * threshold is: a tax line is derived from the rate, not a price
			 * anybody set, and rounding each one on its own would leave the tax
			 * no longer matching what it is tax on.
			 */
			if ( ! isset( $rate->taxes ) || ! is_array( $rate->taxes ) ) {
				continue;
			}

			foreach ( $rate->taxes as $tax_id => $tax_amount ) {
				$rate->taxes[ $tax_id ] = $this->converter->convert( (float) $tax_amount, $currency );
			}
		}

		return $rates;
	}
}
