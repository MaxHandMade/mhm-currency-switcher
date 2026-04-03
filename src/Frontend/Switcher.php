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
	 *   - size: small|medium|large (default: medium)
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return string Escaped HTML string.
	 */
	public function render_shortcode( array $atts = array() ): string {
		$atts = array_merge(
			array(
				'size' => 'medium',
			),
			$atts
		);

		$size    = in_array( $atts['size'], array( 'small', 'medium', 'large' ), true )
			? $atts['size']
			: 'medium';
		$current = $this->detection->get_current_currency();
		$base    = $this->store->get_base_currency();
		$options = $this->build_options_list( $base );

		if ( empty( $options ) ) {
			return '';
		}

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

		$html = '<div class="mhm-cs-switcher mhm-cs-size--' . esc_attr( $size ) . '" data-current="' . esc_attr( $current ) . '">';

		// Selected button.
		$html .= '<button class="mhm-cs-selected" aria-expanded="false" aria-haspopup="listbox">';
		$html .= '<img src="' . esc_url( $current_option['flag_url'] ) . '" alt="' . esc_attr( $current_option['code'] ) . '" class="mhm-cs-flag" width="20" height="15" />';
		$html .= '<span class="mhm-cs-label">' . esc_html( $current_option['symbol'] . ' ' . $current_option['code'] ) . '</span>';
		$html .= '<span class="mhm-cs-arrow">&#9662;</span>';
		$html .= '</button>';

		// Dropdown list.
		$html .= '<ul class="mhm-cs-dropdown" role="listbox">';

		foreach ( $options as $option ) {
			$active_class = $option['code'] === $current ? ' mhm-cs-active' : '';

			$html .= '<li role="option" data-currency="' . esc_attr( $option['code'] ) . '" class="mhm-cs-option' . esc_attr( $active_class ) . '">';
			$html .= '<img src="' . esc_url( $option['flag_url'] ) . '" alt="' . esc_attr( $option['code'] ) . '" class="mhm-cs-flag" width="20" height="15" />';
			$html .= ' <span>' . esc_html( $option['symbol'] . ' ' . $option['code'] ) . '</span>';
			$html .= '</li>';
		}

		$html .= '</ul>';
		$html .= '</div>';

		return $html;
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
		$options = array();
		$seen    = array();
		$enabled = $this->store->get_enabled_currencies();

		// Always include the base currency first.
		$options[]     = array(
			'code'     => $base,
			'symbol'   => $this->get_currency_symbol( $base ),
			'flag_url' => FlagMapper::get_flag_url( $base ),
		);
		$seen[ $base ] = true;

		// Add all enabled currencies.
		foreach ( $enabled as $currency ) {
			$code = $currency['code'] ?? '';

			if ( '' === $code || isset( $seen[ $code ] ) ) {
				continue;
			}

			$options[]     = array(
				'code'     => $code,
				'symbol'   => $this->get_currency_symbol( $code ),
				'flag_url' => FlagMapper::get_flag_url( $code ),
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
