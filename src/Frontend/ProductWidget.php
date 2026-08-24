<?php
/**
 * Product page multi-currency price display.
 *
 * Shows converted prices with flag icons on WooCommerce single
 * product pages, and provides a [mhmcs_currency_prices] shortcode.
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
use MhmCurrencySwitcher\Integration\WooCommerce\ProductPricing;

/**
 * ProductWidget — flagged price display on product pages.
 *
 * Renders converted prices for configured currencies, each with
 * a flag icon, below the WooCommerce product price. Can also be
 * used via the [mhmcs_currency_prices] shortcode.
 *
 * @since 0.3.0
 */
final class ProductWidget {

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const SETTINGS_KEY = 'mhmcs_settings';

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
		add_shortcode( 'mhmcs_currency_prices', array( $this, 'render_shortcode' ) );
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
	 *   - show_flags: Override the saved product_widget.show_flags
	 *     setting when explicitly passed (true/false); absent/null
	 *     falls through to the saved setting.
	 *
	 * @param array<string, string|bool|null>|string $atts Shortcode
	 *                                                      attributes. Before
	 *                                                      WordPress 6.5,
	 *                                                      shortcode_parse_atts()
	 *                                                      passes an empty
	 *                                                      string instead of
	 *                                                      array() when the
	 *                                                      shortcode has no
	 *                                                      attributes, so
	 *                                                      this must not use
	 *                                                      a native `array`
	 *                                                      type hint.
	 * @return string Escaped HTML string, or empty when nothing to render.
	 */
	public function render_shortcode( $atts = array() ): string {
		$atts = is_array( $atts ) ? $atts : array();

		$atts = array_merge(
			array(
				'product_id' => '',
				'price'      => '',
				'currencies' => '',
				'show_flags' => null,
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

		// Resolve product ID for fixed price lookups.
		$product_id = $this->resolve_product_id( $atts );

		// Determine whether to print flag icons (att overrides the setting).
		$show_flags = null !== $atts['show_flags'] ? $this->parse_bool_attr( $atts['show_flags'] ) : $this->resolve_show_flags_setting();

		// Build the HTML.
		$items = array();

		foreach ( $currency_codes as $code ) {
			$fixed     = $product_id ? ProductPricing::get_fixed_price( $product_id, $code ) : null;
			$converted = null !== $fixed ? $fixed : $this->converter->convert_with_rounding( $price, $code );
			$formatted = $this->format_price( $converted, $code );

			$flag_html = '';

			if ( $show_flags ) {
				$flag_url  = FlagMapper::get_flag_url( $code );
				$flag_html = '<img src="' . esc_url( $flag_url ) . '" alt="' . esc_attr( $code ) . '" class="mhm-cs-flag" width="20" height="15" />';
			}

			$items[] = '<span class="mhm-cs-product-price">'
				. $flag_html
				. '<span class="mhm-cs-amount">' . esc_html( $formatted ) . '</span>'
				. '</span>';
		}

		$separator = '<span class="mhm-cs-separator">|</span>';

		/*
		 * Escaped again on the way OUT, not only at each interpolation.
		 *
		 * Every value above already goes through esc_html/esc_attr/esc_url, so
		 * this changes nothing about today's output -- measured, byte for byte,
		 * against real WordPress: wp_kses_post() returns this markup unchanged,
		 * data-* and aria-* and role included. It is here because the shape is
		 * what a reviewer reads, and "the callback's RETURN value is unescaped"
		 * is the single most repeated rejection class in this house's WP.org
		 * history -- three rounds running on another plugin. WordPress's own rule
		 * is to escape as late as possible, and for a shortcode the latest point
		 * is the return.
		 *
		 * If a future edit adds an attribute kses drops, this line is where it
		 * disappears. This widget emits no data-* attributes; what its tests
		 * pin is the <img> flag markup and the mhm-cs-* class names, so those
		 * are what would fail here. An attribute nothing asserts would not.
		 */
		return wp_kses_post(
			'<div class="mhm-cs-product-prices">'
			. implode( $separator, $items )
			. '</div>'
		);
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
	 * Resolve the product ID from attributes or global $product.
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return int Product ID, or 0 when unavailable.
	 */
	private function resolve_product_id( array $atts ): int {
		if ( '' !== $atts['product_id'] ) {
			return (int) $atts['product_id'];
		}

		global $product;

		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			return $product->get_id();
		}

		return 0;
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

			return $this->drop_unconfigured( $codes );
		}

		// Fall back to widget settings.
		$settings = $this->get_widget_settings();

		if ( ! empty( $settings['currencies'] ) && is_array( $settings['currencies'] ) ) {
			return $this->drop_unconfigured(
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
	 * Remove currencies the shop has not configured.
	 *
	 * 🔴 Without this the widget prints a number no rate produced. Each
	 * piece is defensible alone: this method used to accept any
	 * three-letter code that was not the base, `Converter::convert()`
	 * deliberately returns the price untouched rather than invent a rate
	 * for a currency it does not know, and `format_price()` falls back to
	 * "CODE 1,234.56" when it has no format data. Combined, an
	 * unconfigured code renders the BASE amount wearing a foreign code and
	 * a foreign flag — a real-looking price that is simply wrong.
	 *
	 * Measured on a live TRY shop with nothing configured: a 1000 TRY
	 * product rendered "USD 1,000.00 | EUR 1,000.00 | GBP 1,000.00". The
	 * Elementor widget ships `USD,EUR,GBP` as its control default, so
	 * dropping it on a page was enough to produce that, with no error and
	 * nothing for a gate to catch.
	 *
	 * Applies to both sources on purpose. The saved list is not safer than
	 * the shortcode attribute: a currency removed from the configuration
	 * stays behind in the widget's own list.
	 *
	 * @param array<int|string, string> $codes Candidate currency codes.
	 * @return array<int, string> Codes the store actually knows.
	 */
	private function drop_unconfigured( array $codes ): array {
		return array_values(
			array_filter(
				$codes,
				function ( string $code ): bool {
					$currency = $this->store->get_currency( $code );

					// Never configured: the original reason this filter exists.
					if ( null === $currency ) {
						return false;
					}

					// 🔴 Switched off by the shop. `get_enabled_currencies()`
					// and DetectionService both honour this flag; this widget
					// did not, so turning a currency off left it on every
					// product page with nothing to explain why. Same emptiness
					// test as CurrencyStore uses, so the two cannot drift.
					if ( empty( $currency['enabled'] ) ) {
						return false;
					}

					// 🔴 Configured, enabled, but cannot produce a price — a
					// rate of zero (which the panel saves without a word when
					// the field is cleared) or a fee that cancels the rate out.
					//
					// Asking only "is it configured" let those through, and
					// what follows is not a missing price but a WRONG one:
					// `Converter::convert()` deliberately returns the BASE
					// amount rather than invent a rate, and `format_price()`
					// then wraps that base amount in the TARGET currency's
					// symbol. A lira figure wearing a pound sign, with nothing
					// on the page to suggest trouble.
					//
					// That class was fixed for the price display in v1.1.3.
					// This surface was never swept, which is why it is here.
					return $this->converter->has_usable_rate( $code );
				}
			)
		);
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
	 * Parse a boolean-ish shortcode/attribute value.
	 *
	 * Shortcode attributes always arrive as strings (`[mhmcs_currency_prices
	 * show_flags="false"]` hands the callback the literal string "false"),
	 * so a plain `(bool)` cast is wrong — `(bool) 'false'` is `true` in
	 * PHP. This accepts the same spellings WooCommerce's
	 * `wc_string_to_bool()` does, plus real booleans passed programmatically
	 * (e.g. from the Elementor widget). Anything unrecognised falls back to
	 * `$default` rather than silently flipping.
	 *
	 * @param mixed $value   Raw attribute value.
	 * @param bool  $default Fallback for unrecognised values.
	 * @return bool Parsed boolean.
	 */
	private function parse_bool_attr( $value, bool $default = true ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		$normalized = strtolower( trim( (string) $value ) );

		if ( in_array( $normalized, array( 'true', 'yes', '1', 'on' ), true ) ) {
			return true;
		}

		if ( in_array( $normalized, array( 'false', 'no', '0', 'off' ), true ) ) {
			return false;
		}

		return $default;
	}

	/**
	 * Read the saved show_flags setting, defaulting to true.
	 *
	 * Flags are printed unconditionally in the pre-Task-7 code, so the
	 * default here must stay true — anything else would silently change
	 * the appearance of every existing product widget on upgrade.
	 *
	 * @return bool Whether to print flag icons.
	 */
	private function resolve_show_flags_setting(): bool {
		$settings = $this->get_widget_settings();

		return isset( $settings['show_flags'] ) ? (bool) $settings['show_flags'] : true;
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
