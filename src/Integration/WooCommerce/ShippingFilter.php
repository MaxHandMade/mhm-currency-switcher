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
	 * Constructor.
	 *
	 * @param Converter        $converter Price conversion engine.
	 * @param DetectionService $detection Currency detection service.
	 */
	public function __construct( Converter $converter, DetectionService $detection ) {
		$this->converter = $converter;
		$this->detection = $detection;
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
		if ( $this->detection->is_base_currency() ) {
			return $rates;
		}

		$currency = $this->detection->get_current_currency();

		foreach ( $rates as $rate ) {
			$rate->cost = $this->converter->convert( (float) $rate->cost, $currency );
		}

		return $rates;
	}
}
