<?php
/**
 * WooCommerce format filter — adjusts currency display for active currency.
 *
 * Hooks into WooCommerce currency formatting filters to display the
 * correct symbol, position, separators, and decimal count for the
 * visitor's selected currency.
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
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;

/**
 * FormatFilter — WooCommerce currency formatting hooks.
 *
 * Registers filters at priority 100 on WooCommerce currency display
 * hooks so that the symbol, position, separators, and decimal count
 * match the visitor's selected currency.
 *
 * @since 0.1.0
 */
final class FormatFilter {

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
	 * Converter, consulted only for whether a currency can be priced at all.
	 *
	 * @var Converter
	 */
	private Converter $converter;

	/**
	 * Constructor.
	 *
	 * @param CurrencyStore     $store     Currency data store.
	 * @param DetectionService  $detection Currency detection service.
	 * @param ConversionContext $context   Shared conversion-context resolver.
	 * @param Converter         $converter Price converter, asked whether the
	 *                                     target currency has a usable rate.
	 */
	public function __construct( CurrencyStore $store, DetectionService $detection, ConversionContext $context, Converter $converter ) {
		$this->store     = $store;
		$this->detection = $detection;
		$this->context   = $context;
		$this->converter = $converter;
	}

	/**
	 * Whether this request should be shown in the visitor's currency.
	 *
	 * The three conditions every callback below shares, asked in one place so
	 * they cannot drift apart between the symbol, the position and the
	 * separators.
	 *
	 * 🔴 The third condition is the one that was missing. A currency the shop
	 * offers but has no rate for cannot be converted — Converter hands the base
	 * amount straight back — yet the format switched anyway, so the visitor saw
	 * a base-currency number under a foreign symbol. The number was wrong and
	 * nothing on the page said so. Whatever the amount does, the symbol does.
	 *
	 * @return bool True when the visitor's currency owns the display.
	 */
	private function visitor_currency_owns_the_display(): bool {
		if ( ! $this->context->should_convert() ) {
			return false;
		}

		if ( $this->detection->is_base_currency() ) {
			return false;
		}

		return $this->converter->has_usable_rate( $this->detection->get_current_currency() );
	}

	/**
	 * Register all WooCommerce format hooks at priority 100.
	 *
	 * Registration is unconditional. There used to be an `is_admin()` guard
	 * here, decided once on `init`; the context now owns that call and decides
	 * per invocation instead, for two reasons:
	 *
	 * - The old guard let admin-AJAX through (it only skipped non-AJAX admin
	 *   requests) while PriceFilter had no guard at all, so an admin-AJAX
	 *   request converted the symbol AND the amount — which is how the order
	 *   editor's "add product" wrote a converted price into a real line item.
	 * - A registration-time decision cannot see a money context that a cart or
	 *   checkout shortcode declares halfway through rendering.
	 *
	 * Every callback below asks the same question PriceFilter asks, so the
	 * symbol and the amount can never disagree within one request.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'woocommerce_currency', array( $this, 'get_currency_code' ), 100, 1 );
		add_filter( 'woocommerce_currency_symbol', array( $this, 'get_currency_symbol' ), 100, 2 );
		add_filter( 'pre_option_woocommerce_currency_pos', array( $this, 'get_currency_position' ), 100, 1 );
		add_filter( 'wc_get_price_thousand_separator', array( $this, 'get_thousand_separator' ), 100, 1 );
		add_filter( 'wc_get_price_decimal_separator', array( $this, 'get_decimal_separator' ), 100, 1 );
		add_filter( 'wc_get_price_decimals', array( $this, 'get_decimals' ), 100, 1 );
	}

	/**
	 * Override the WooCommerce currency code.
	 *
	 * @param string $currency Original currency code from WC settings.
	 * @return string Active currency code, or original when base.
	 */
	public function get_currency_code( string $currency ): string {
		if ( ! $this->visitor_currency_owns_the_display() ) {
			return $currency;
		}

		return $this->detection->get_current_currency();
	}

	/**
	 * Override the currency symbol.
	 *
	 * @param string $symbol   Original currency symbol.
	 * @param string $currency Currency code the symbol belongs to.
	 * @return string Active currency symbol, or original when base.
	 */
	public function get_currency_symbol( string $symbol, string $currency ): string {
		if ( ! $this->visitor_currency_owns_the_display() ) {
			return $symbol;
		}

		$format = $this->get_format();

		if ( null === $format ) {
			return $symbol;
		}

		return (string) ( $format['symbol'] ?? $symbol );
	}

	/**
	 * Override the currency position (left, right, left_space, right_space).
	 *
	 * Hooked to `pre_option_woocommerce_currency_pos` which expects
	 * false to fall through to the database value.
	 *
	 * @param mixed $position Pre-option value (false by default).
	 * @return mixed Currency position string, or original when base.
	 */
	public function get_currency_position( $position ) {
		if ( ! $this->visitor_currency_owns_the_display() ) {
			return $position;
		}

		$format = $this->get_format();

		if ( null === $format ) {
			return $position;
		}

		return (string) ( $format['position'] ?? $position );
	}

	/**
	 * Override the thousand separator.
	 *
	 * @param string $sep Original thousand separator.
	 * @return string Active currency thousand separator, or original when base.
	 */
	public function get_thousand_separator( string $sep ): string {
		if ( ! $this->visitor_currency_owns_the_display() ) {
			return $sep;
		}

		$format = $this->get_format();

		if ( null === $format ) {
			return $sep;
		}

		return (string) ( $format['thousand_sep'] ?? $sep );
	}

	/**
	 * Override the decimal separator.
	 *
	 * @param string $sep Original decimal separator.
	 * @return string Active currency decimal separator, or original when base.
	 */
	public function get_decimal_separator( string $sep ): string {
		if ( ! $this->visitor_currency_owns_the_display() ) {
			return $sep;
		}

		$format = $this->get_format();

		if ( null === $format ) {
			return $sep;
		}

		return (string) ( $format['decimal_sep'] ?? $sep );
	}

	/**
	 * Override the number of decimals.
	 *
	 * @param int $decimals Original number of decimals.
	 * @return int Active currency decimals, or original when base.
	 */
	public function get_decimals( int $decimals ): int {
		if ( ! $this->visitor_currency_owns_the_display() ) {
			return $decimals;
		}

		$format = $this->get_format();

		if ( null === $format ) {
			return $decimals;
		}

		return (int) ( $format['decimals'] ?? $decimals );
	}

	/**
	 * Retrieve the format array for the current currency.
	 *
	 * @return array<string, mixed>|null Format sub-array, or null when not found.
	 */
	private function get_format(): ?array {
		$code     = $this->detection->get_current_currency();
		$currency = $this->store->get_currency( $code );

		if ( null === $currency || ! isset( $currency['format'] ) ) {
			return null;
		}

		return $currency['format'];
	}
}
