<?php
/**
 * WooCommerce price filter — converts product prices to active currency.
 *
 * Hooks into all WooCommerce product price filters to apply the
 * current visitor's currency conversion via the Converter engine.
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
 * PriceFilter — WooCommerce product price conversion hooks.
 *
 * Registers filters at priority 100 on every WooCommerce price getter
 * so that product prices are converted from the base currency to the
 * visitor's selected currency.
 *
 * @since 0.1.0
 */
final class PriceFilter {

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
	 * Register all WooCommerce price hooks at priority 100.
	 *
	 * @return void
	 */
	public function init(): void {
		// Simple and variable product prices.
		add_filter( 'woocommerce_product_get_price', array( $this, 'convert_price' ), 100, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( $this, 'convert_price' ), 100, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'convert_sale_price' ), 100, 2 );

		// Variation-level prices.
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'convert_price' ), 100, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'convert_price' ), 100, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'convert_sale_price' ), 100, 2 );

		// Variable product price ranges (min/max across variations).
		add_filter( 'woocommerce_variation_prices_price', array( $this, 'convert_variation_price' ), 100, 3 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( $this, 'convert_variation_price' ), 100, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'convert_variation_price' ), 100, 3 );

		// Variation price cache hash — bust per currency.
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'add_currency_to_hash' ), 100, 3 );
	}

	/**
	 * Convert a product price to the current currency.
	 *
	 * Checks for a per-product fixed price first (Pro feature).
	 * Falls back to automatic exchange rate conversion.
	 *
	 * @param string|float $price   Product price (may be '' or numeric string).
	 * @param mixed        $product WC_Product instance.
	 * @return string|float Converted price, or original when base currency.
	 */
	public function convert_price( $price, $product ) {
		if ( '' === $price ) {
			return '';
		}

		if ( $this->detection->is_base_currency() ) {
			return $price;
		}

		$currency = $this->detection->get_current_currency();

		$fixed = $this->get_product_fixed_price( $product, $currency );
		if ( null !== $fixed ) {
			return $fixed;
		}

		return $this->converter->convert_with_rounding( (float) $price, $currency );
	}

	/**
	 * Convert a sale price to the current currency.
	 *
	 * Identical to convert_price except that an empty sale price
	 * returns '' (not 0) to indicate "no sale price set".
	 *
	 * @param string|float $price   Sale price (may be '' when no sale).
	 * @param mixed        $product WC_Product instance (unused but required by hook).
	 * @return string|float Converted sale price, or '' when not on sale.
	 */
	public function convert_sale_price( $price, $product ) {
		if ( '' === $price ) {
			return '';
		}

		if ( $this->detection->is_base_currency() ) {
			return $price;
		}

		$currency = $this->detection->get_current_currency();

		$fixed = $this->get_product_fixed_price( $product, $currency );
		if ( null !== $fixed ) {
			return $fixed;
		}

		return $this->converter->convert_with_rounding( (float) $price, $currency );
	}

	/**
	 * Convert a variation price within a variable product.
	 *
	 * Checks for a per-variation fixed price first (Pro feature).
	 * Falls back to automatic exchange rate conversion.
	 *
	 * @param string|float $price     Variation price.
	 * @param mixed        $variation WC_Product_Variation instance.
	 * @param mixed        $product   Parent WC_Product_Variable instance.
	 * @return string|float Converted variation price.
	 */
	public function convert_variation_price( $price, $variation, $product ) {
		if ( '' === $price ) {
			return '';
		}

		if ( $this->detection->is_base_currency() ) {
			return $price;
		}

		$currency = $this->detection->get_current_currency();

		$fixed = $this->get_product_fixed_price( $variation, $currency );
		if ( null !== $fixed ) {
			return $fixed;
		}

		return $this->converter->convert_with_rounding( (float) $price, $currency );
	}

	/**
	 * Get the fixed price for a product in a given currency.
	 *
	 * Returns null if no fixed price is set or if the Pro license
	 * is not active, allowing automatic conversion to proceed.
	 *
	 * @param mixed  $product  WC_Product instance.
	 * @param string $currency Currency code.
	 * @return float|null Fixed price, or null to use auto-conversion.
	 */
	private function get_product_fixed_price( $product, string $currency ): ?float {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return null;
		}

		return ProductPricing::get_fixed_price( $product->get_id(), $currency );
	}

	/**
	 * Append the current currency code to the variation prices hash.
	 *
	 * WooCommerce caches variation price ranges using a hash array.
	 * Adding the currency code ensures each currency gets its own
	 * cached price range.
	 *
	 * @param array<int, string> $hash        Hash components array.
	 * @param mixed              $product     WC_Product_Variable instance.
	 * @param bool               $for_display Whether prices are for display.
	 * @return array<int, string> Modified hash with currency appended.
	 */
	public function add_currency_to_hash( array $hash, $product, bool $for_display ): array {
		$hash[] = $this->detection->get_current_currency();

		return $hash;
	}
}
