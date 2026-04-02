<?php
/**
 * Product page multi-currency price display.
 *
 * Shows converted prices with flag icons on WooCommerce single
 * product pages, and provides a [mhm_currency_prices] shortcode.
 *
 * @package MhmCurrencySwitcher\Frontend
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Frontend;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;

/**
 * ProductWidget — flagged price display on product pages.
 *
 * Renders converted prices for configured currencies, each with
 * a flag icon, below the WooCommerce product price. Can also be
 * used via the [mhm_currency_prices] shortcode.
 *
 * @since 0.3.0
 */
final class ProductWidget {

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const SETTINGS_KEY = 'mhm_currency_switcher_settings';

	/**
	 * Currency data store.
	 *
	 * @var CurrencyStore
	 */
	private CurrencyStore $store;

	/**
	 * Price converter.
	 *
	 * @var Converter
	 */
	private Converter $converter;

	/**
	 * Constructor.
	 *
	 * @param CurrencyStore $store     Currency data store.
	 * @param Converter     $converter Price conversion engine.
	 */
	public function __construct( CurrencyStore $store, Converter $converter ) {
		$this->store     = $store;
		$this->converter = $converter;
	}

	/**
	 * Register shortcode and WooCommerce hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_shortcode( 'mhm_currency_prices', array( $this, 'render_shortcode' ) );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_on_product_page' ), 15 );
	}

	/**
	 * Render the product price widget on single product pages.
	 *
	 * Reads the global $product, checks if the widget is enabled
	 * in settings, and echoes the rendered shortcode output.
	 *
	 * @return void
	 */
	public function render_on_product_page(): void {
		global $product;

		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}

		$settings = $this->get_widget_settings();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		if ( empty( $settings['currencies'] ) || ! is_array( $settings['currencies'] ) ) {
			return;
		}

		echo wp_kses_post(
			$this->render_shortcode(
				array(
					'product_id' => (string) $product->get_id(),
				)
			)
		);
	}

	/**
	 * Render the multi-currency price shortcode.
	 *
	 * Accepts optional attributes:
	 *   - product_id: WC product ID (falls back to global $product).
	 *   - price:      Override price value (useful for testing).
	 *   - currencies: Comma-separated currency codes to display.
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return string Escaped HTML string, or empty when nothing to render.
	 */
	public function render_shortcode( array $atts = array() ): string {
		$atts = array_merge(
			array(
				'product_id' => '',
				'price'      => '',
				'currencies' => '',
			),
			$atts
		);

		// Determine the price.
		$price = $this->resolve_price( $atts );

		if ( null === $price || $price <= 0.0 ) {
			return '';
		}

		// Determine which currencies to display.
		$currency_codes = $this->resolve_currencies( $atts );

		if ( empty( $currency_codes ) ) {
			return '';
		}

		// Build the HTML.
		$items = array();

		foreach ( $currency_codes as $code ) {
			$converted = $this->converter->convert_with_rounding( $price, $code );
			$formatted = $this->format_price( $converted, $code );
			$flag_url  = FlagMapper::get_flag_url( $code );

			$items[] = '<span class="mhm-cs-product-price">'
				. '<img src="' . esc_url( $flag_url ) . '" alt="' . esc_attr( $code ) . '" class="mhm-cs-flag" width="20" height="15" />'
				. '<span class="mhm-cs-amount">' . esc_html( $formatted ) . '</span>'
				. '</span>';
		}

		if ( empty( $items ) ) {
			return '';
		}

		$separator = '<span class="mhm-cs-separator">|</span>';

		return '<div class="mhm-cs-product-prices">'
			. implode( $separator, $items )
			. '</div>';
	}

	/**
	 * Resolve the product price from attributes or global $product.
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return float|null Price value, or null when unavailable.
	 */
	private function resolve_price( array $atts ): ?float {
		// Explicit price attribute takes priority (for testing).
		if ( '' !== $atts['price'] ) {
			return (float) $atts['price'];
		}

		// Try product_id attribute — use raw meta to avoid PriceFilter double-conversion.
		if ( '' !== $atts['product_id'] && function_exists( 'wc_get_product' ) ) {
			$raw = get_post_meta( (int) $atts['product_id'], '_price', true );

			return '' !== $raw && false !== $raw ? (float) $raw : null;
		}

		// Fall back to global $product — use raw meta.
		global $product;

		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$raw = get_post_meta( $product->get_id(), '_price', true );

			return '' !== $raw && false !== $raw ? (float) $raw : null;
		}

		return null;
	}

	/**
	 * Resolve which currencies to display.
	 *
	 * Reads from the shortcode `currencies` attribute first,
	 * then falls back to the product_widget settings.
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return array<int, string> Array of currency codes.
	 */
	private function resolve_currencies( array $atts ): array {
		$base = $this->store->get_base_currency();

		// Explicit currencies attribute.
		if ( '' !== $atts['currencies'] ) {
			$codes = array_map( 'trim', explode( ',', $atts['currencies'] ) );
			$codes = array_filter(
				array_map( 'strtoupper', $codes ),
				function ( string $code ) use ( $base ): bool {
					return 3 === strlen( $code ) && $code !== $base;
				}
			);

			return array_values( $codes );
		}

		// Fall back to widget settings.
		$settings = $this->get_widget_settings();

		if ( ! empty( $settings['currencies'] ) && is_array( $settings['currencies'] ) ) {
			return array_values(
				array_filter(
					$settings['currencies'],
					function ( string $code ) use ( $base ): bool {
						return $code !== $base;
					}
				)
			);
		}

		return array();
	}

	/**
	 * Format a price for display with the currency symbol.
	 *
	 * Uses the currency's format configuration from the store.
	 * Falls back to a basic number_format when format data is missing.
	 *
	 * @param float  $price Price value.
	 * @param string $code  Currency code (ISO 4217).
	 * @return string Formatted price string (e.g. "$30.60", "25,50 €").
	 */
	private function format_price( float $price, string $code ): string {
		$currency = $this->store->get_currency( $code );

		if ( null === $currency || ! isset( $currency['format'] ) ) {
			return $code . ' ' . number_format( $price, 2, '.', ',' );
		}

		$fmt      = $currency['format'];
		$symbol   = $fmt['symbol'] ?? $code;
		$decimals = (int) ( $fmt['decimals'] ?? 2 );
		$dec_sep  = $fmt['decimal_sep'] ?? '.';
		$thou_sep = $fmt['thousand_sep'] ?? ',';
		$position = $fmt['position'] ?? 'left';

		$number = number_format( $price, $decimals, $dec_sep, $thou_sep );

		switch ( $position ) {
			case 'left':
				return $symbol . $number;

			case 'left_space':
				return $symbol . ' ' . $number;

			case 'right':
				return $number . $symbol;

			case 'right_space':
				return $number . ' ' . $symbol;

			default:
				return $symbol . $number;
		}
	}

	/**
	 * Get the product widget settings from the plugin settings option.
	 *
	 * @return array<string, mixed> Widget settings sub-array.
	 */
	private function get_widget_settings(): array {
		$settings = get_option( self::SETTINGS_KEY, array() );

		if ( ! is_array( $settings ) ) {
			return array();
		}

		return $settings['product_widget'] ?? array();
	}
}
