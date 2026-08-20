<?php
/**
 * Frontend currency switcher — shortcode and widget rendering.
 *
 * Registers the [mhm_currency_switcher] shortcode and renders a
 * dropdown UI that lets visitors switch between enabled currencies.
 *
 * @package MhmCurrencySwitcher\Frontend
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Frontend;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MhmCurrencySwitcher\Core\ConversionContext;
use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;

/**
 * Switcher — currency switcher shortcode and widget.
 *
 * Renders an accessible dropdown with flag icons, currency symbols,
 * and codes for all enabled currencies plus the base currency.
 *
 * @since 0.3.0
 */
final class Switcher {

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
	 * The context is mandatory, not optional. It decides whether this render
	 * may carry the visitor's currency, and a forgotten optional argument
	 * would silently put every caller back on the leaking path — which is the
	 * bug this collaborator exists to prevent.
	 *
	 * @param CurrencyStore     $store     Currency data store.
	 * @param DetectionService  $detection Currency detection service.
	 * @param ConversionContext $context   The request's single context
	 *                                     resolver — the same instance the
	 *                                     price surfaces, the marker and the
	 *                                     asset loader use.
	 */
	public function __construct( CurrencyStore $store, DetectionService $detection, ConversionContext $context ) {
		$this->store     = $store;
		$this->detection = $detection;
		$this->context   = $context;
	}

	/**
	 * Register shortcode and widget hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_shortcode( 'mhm_currency_switcher', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Render the currency switcher dropdown HTML.
	 *
	 * Shortcode attributes:
	 *   - size: small|medium|large. A valid value overrides the saved
	 *     `mhmcs_settings['switcher']['size']` setting (existing
	 *     behaviour). An absent OR invalid (e.g. typo'd) value is not a
	 *     valid override, so both fall through to the saved setting the
	 *     same way; the saved setting in turn falls back to the
	 *     hard-coded 'medium' only if it is itself missing or invalid.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes. Before
	 *                                            WordPress 6.5,
	 *                                            shortcode_parse_atts()
	 *                                            passes an empty string
	 *                                            instead of array() when the
	 *                                            shortcode has no
	 *                                            attributes, so this must
	 *                                            not use a native `array`
	 *                                            type hint.
	 * @return string Escaped HTML string.
	 */
	public function render_shortcode( $atts = array() ): string {
		$atts       = is_array( $atts ) ? $atts : array();
		$display    = $this->get_display_settings();
		$valid_size = array( 'small', 'medium', 'large' );

		$requested_size = isset( $atts['size'] ) ? (string) $atts['size'] : '';
		$setting_size   = in_array( $display['size'], $valid_size, true ) ? $display['size'] : 'medium';
		$size           = in_array( $requested_size, $valid_size, true ) ? $requested_size : $setting_size;

		/*
		 * Whether this render is one a page cache may store, in which case it
		 * must say nothing about who is looking at it.
		 *
		 * The predicate is the shared context's own should_convert(), not the
		 * `cache_compat` setting: "the server did not convert" is exactly the
		 * render that leaves base prices behind for the client to fix up, and
		 * it already folds in the money context, the logged-in visitor and the
		 * switched-off mode. Asking a second, differently-phrased question is
		 * how the two answers drift apart.
		 *
		 * Reading it here, in the middle of body output, is safe with respect
		 * to the context's one-way latch. wp_enqueue_scripts fires inside
		 * wp_head — before any body content — so Enqueue.php has already asked
		 * the same question, and the latch only ever moves TOWARDS "convert".
		 * The dangerous direction therefore cannot occur: if Enqueue answered
		 * "convert" the latch is armed and this call answers "convert" too, so
		 * a page with no converter script never gets neutral markup. The
		 * reverse — non-neutral markup on a page that DOES load the converter
		 * — is harmless, because switcher.js syncs the indicator from the
		 * cookie on load either way.
		 */
		$neutral = ! $this->context->should_convert();

		$base    = $this->store->get_base_currency();
		$options = $this->build_options_list( $base );

		if ( empty( $options ) ) {
			return '';
		}

		/*
		 * The neutral path does not ask the detection service at all, rather
		 * than asking and discarding the answer. Two reasons, and the second
		 * is not obvious: the answer would leak the visitor's currency into
		 * cacheable HTML, AND the lookup itself runs geolocation on every page
		 * that contains a switcher — a cost paid for a value this branch is
		 * forbidden to print.
		 *
		 * An empty string matches no currency code, so the loop below falls
		 * through to $options[0] — the base currency, which build_options_list()
		 * always puts first — and no option is marked active.
		 */
		$current = $neutral ? '' : $this->detection->get_current_currency();

		// Find the current option for the button display.
		$current_option = null;
		foreach ( $options as $option ) {
			if ( $option['code'] === $current ) {
				$current_option = $option;
				break;
			}
		}

		// Fallback to first option if current not found.
		if ( null === $current_option ) {
			$current_option = $options[0];
		}

		$html = '<div class="mhm-cs-switcher mhm-cs-size--' . esc_attr( $size ) . '"';

		if ( ! $neutral ) {
			$html .= ' data-current="' . esc_attr( $current ) . '"';
		}

		$html .= '>';

		// Selected button.
		$html .= '<button class="mhm-cs-selected" aria-expanded="false" aria-haspopup="listbox">';

		if ( $display['show_flag'] ) {
			$html .= '<img src="' . esc_url( $current_option['flag_url'] ) . '" alt="' . esc_attr( $current_option['code'] ) . '" class="mhm-cs-flag" width="20" height="15" />';
		}

		$html .= '<span class="mhm-cs-label">' . esc_html( $this->build_label( $current_option, $display ) ) . '</span>';
		$html .= '<span class="mhm-cs-arrow">&#9662;</span>';
		$html .= '</button>';

		// Dropdown list.
		$html .= '<ul class="mhm-cs-dropdown" role="listbox">';

		foreach ( $options as $option ) {
			$active_class = $option['code'] === $current ? ' mhm-cs-active' : '';

			$html .= '<li role="option" data-currency="' . esc_attr( $option['code'] ) . '" class="mhm-cs-option' . esc_attr( $active_class ) . '">';

			if ( $display['show_flag'] ) {
				$html .= '<img src="' . esc_url( $option['flag_url'] ) . '" alt="' . esc_attr( $option['code'] ) . '" class="mhm-cs-flag" width="20" height="15" />';
			}

			$html .= ' <span>' . esc_html( $this->build_label( $option, $display ) ) . '</span>';
			$html .= '</li>';
		}

		$html .= '</ul>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Read the saved switcher display settings, filled in with defaults
	 * that preserve the plugin's pre-existing appearance (flag + symbol
	 * + code, no name, medium size).
	 *
	 * @return array{show_flag: bool, show_name: bool, show_symbol: bool, show_code: bool, size: string}
	 */
	private function get_display_settings(): array {
		$settings = get_option( 'mhmcs_settings', array() );
		$display  = ( is_array( $settings ) && isset( $settings['switcher'] ) && is_array( $settings['switcher'] ) )
			? $settings['switcher']
			: array();

		return array(
			'show_flag'   => isset( $display['show_flag'] ) ? (bool) $display['show_flag'] : true,
			'show_name'   => isset( $display['show_name'] ) ? (bool) $display['show_name'] : false,
			'show_symbol' => isset( $display['show_symbol'] ) ? (bool) $display['show_symbol'] : true,
			'show_code'   => isset( $display['show_code'] ) ? (bool) $display['show_code'] : true,
			'size'        => isset( $display['size'] ) && is_string( $display['size'] ) ? $display['size'] : 'medium',
		);
	}

	/**
	 * Build the visible label text for a currency option from the parts
	 * the admin has enabled (symbol, code, name).
	 *
	 * Never returns an empty string — if every part is disabled, the
	 * code is used as a fallback so the button/list item is never blank.
	 *
	 * @param array<string, string>                                                                     $option  Option data (code, symbol, name, flag_url).
	 * @param array{show_flag: bool, show_name: bool, show_symbol: bool, show_code: bool, size: string} $display Display settings.
	 * @return string Label text.
	 */
	private function build_label( array $option, array $display ): string {
		$parts = array();

		if ( $display['show_symbol'] ) {
			$parts[] = $option['symbol'];
		}

		if ( $display['show_code'] ) {
			$parts[] = $option['code'];
		}

		if ( $display['show_name'] ) {
			$parts[] = $option['name'];
		}

		if ( empty( $parts ) ) {
			$parts[] = $option['code'];
		}

		return implode( ' ', $parts );
	}

	/**
	 * The currencies this shop offers, for callers outside the dropdown.
	 *
	 * Exposed so Enqueue.php can hand the same list to the client-side
	 * converter instead of assembling a second one. The symbol table below is
	 * the plugin's own — deliberately not WooCommerce's, so that another
	 * multi-currency plugin filtering `woocommerce_currency_symbol` cannot
	 * rewrite it — and a copy of it built somewhere else would be free to drift
	 * away from what the switcher itself renders.
	 *
	 * The list is also, exactly, what DetectionService::validate_code() will
	 * accept: the base currency plus the enabled ones. The client validates a
	 * cookie or a `?currency=` value against it for that reason, so both sides
	 * reject the same codes.
	 *
	 * @return array<int, array<string, string>> Options list: code, symbol,
	 *                                           flag_url, name.
	 */
	public function get_currency_options(): array {
		return $this->build_options_list( $this->store->get_base_currency() );
	}

	/**
	 * Build the list of currency options for the dropdown.
	 *
	 * Includes the base currency and all enabled currencies.
	 * Each item contains: code, symbol, flag_url.
	 *
	 * @param string $base Base currency code.
	 * @return array<int, array<string, string>> Options list.
	 */
	private function build_options_list( string $base ): array {
		$options       = array();
		$seen          = array();
		$enabled       = $this->store->get_enabled_currencies();
		$wc_currencies = function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies() : array();

		// Always include the base currency first.
		$options[]     = array(
			'code'     => $base,
			'symbol'   => $this->get_currency_symbol( $base ),
			'flag_url' => FlagMapper::get_flag_url( $base ),
			'name'     => $wc_currencies[ $base ] ?? $base,
		);
		$seen[ $base ] = true;

		// 🔴 Offering a currency the plugin cannot convert into is worse than
		// not offering it. Choosing one does nothing at all: Converter returns
		// the base amount, FormatFilter keeps the base symbol, and every price
		// on the page stays exactly as it was. The visitor picks a currency,
		// the page reloads, nothing changes, and no surface explains why.
		//
		// This is reachable without any misuse: clearing the rate field in the
		// panel stores a rate of zero without a word (`parseFloat('') || 0`),
		// and a fee can cancel a rate out. Measured in a real browser on the
		// development stack.
		//
		// Converter is built here rather than injected because it is a pure
		// reader over the same store — a second instance answers identically —
		// and threading a fourth constructor argument through Plugin and the
		// Elementor widgets would change four call sites to gain nothing.
		$converter = new Converter( $this->store );

		// Add all enabled currencies that can actually produce a price.
		foreach ( $enabled as $currency ) {
			$code = $currency['code'] ?? '';

			if ( '' === $code || isset( $seen[ $code ] ) ) {
				continue;
			}

			if ( ! $converter->has_usable_rate( $code ) ) {
				continue;
			}

			$options[]     = array(
				'code'     => $code,
				'symbol'   => $this->get_currency_symbol( $code ),
				'flag_url' => FlagMapper::get_flag_url( $code ),
				'name'     => $wc_currencies[ $code ] ?? $code,
			);
			$seen[ $code ] = true;
		}

		return $options;
	}

	/**
	 * Get the display symbol for a currency code.
	 *
	 * Uses a built-in map to avoid other plugins (e.g. YayCurrency)
	 * filtering all symbols to the base currency via the
	 * woocommerce_currency_symbol hook.
	 *
	 * @param string $code ISO 4217 currency code.
	 * @return string Currency symbol (e.g. "$", "€", "₺").
	 */
	private function get_currency_symbol( string $code ): string {
		$currency = $this->store->get_currency( $code );

		if ( null !== $currency && isset( $currency['format']['symbol'] ) ) {
			return (string) $currency['format']['symbol'];
		}

		$symbols = array(
			'AED' => 'د.إ',
			'ARS' => '$',
			'AUD' => 'A$',
			'BDT' => '৳',
			'BGN' => 'лв.',
			'BRL' => 'R$',
			'CAD' => 'C$',
			'CHF' => 'CHF',
			'CLP' => '$',
			'CNY' => '¥',
			'COP' => '$',
			'CZK' => 'Kč',
			'DKK' => 'kr.',
			'EGP' => 'E£',
			'EUR' => '€',
			'GBP' => '£',
			'GEL' => '₾',
			'HKD' => 'HK$',
			'HUF' => 'Ft',
			'IDR' => 'Rp',
			'ILS' => '₪',
			'INR' => '₹',
			'ISK' => 'kr.',
			'JPY' => '¥',
			'KRW' => '₩',
			'KWD' => 'د.ك',
			'MXN' => 'MX$',
			'MYR' => 'RM',
			'NGN' => '₦',
			'NOK' => 'kr',
			'NZD' => 'NZ$',
			'PEN' => 'S/',
			'PHP' => '₱',
			'PKR' => '₨',
			'PLN' => 'zł',
			'QAR' => 'ر.ق',
			'RON' => 'lei',
			'RUB' => '₽',
			'SAR' => 'ر.س',
			'SEK' => 'kr',
			'SGD' => 'S$',
			'THB' => '฿',
			'TRY' => '₺',
			'TWD' => 'NT$',
			'UAH' => '₴',
			'USD' => '$',
			'VND' => '₫',
			'ZAR' => 'R',
		);

		if ( isset( $symbols[ $code ] ) ) {
			return $symbols[ $code ];
		}

		return $code;
	}
}
