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

		// 🔴 The five filters above are how the visitor's format reaches the
		// page, and four of them cannot tell WHICH currency they are being
		// asked about — WooCommerce passes no currency to the separator,
		// decimal or position filters. Only the symbol filter receives one,
		// which is why the symbol half of this could be fixed there and the
		// number half could not.
		//
		// `wc_price()` resolves all of those defaults and then hands the whole
		// argument array to `wc_price_args` — with `currency` in it. That is
		// the single point where the currency and the format meet, so it is
		// where an amount belonging to another currency gets its own format
		// back. Verified against WooCommerce 10.5.2,
		// wc-formatting-functions.php:596-611.
		add_filter( 'wc_price_args', array( $this, 'correct_format_for_named_currency' ), 100, 1 );
	}

	/**
	 * Give an amount that WooCommerce labelled with a specific currency the
	 * number format of THAT currency.
	 *
	 * Order screens render totals as
	 * `wc_price( $total, [ 'currency' => $order->get_currency() ] )`. Without
	 * this, the symbol said one currency and the digits were written in the
	 * visitor's convention: a EUR order shown as "90 €" to a visitor whose
	 * currency is configured with zero decimals, or "89#66" where a separator
	 * differs. The customer reads their own receipt in a stranger's notation.
	 *
	 * @param mixed $args Fully resolved `wc_price()` arguments.
	 * @return mixed
	 */
	public function correct_format_for_named_currency( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}

		$currency = isset( $args['currency'] ) ? (string) $args['currency'] : '';

		// Nothing named. WooCommerce is asking about the shop's CURRENT
		// currency, which for a converted visitor is theirs — the resolved
		// defaults are already the right answer and must be left alone.
		if ( '' === $currency ) {
			return $args;
		}

		// Our overrides never ran for this request, so there is nothing to undo.
		if ( ! $this->visitor_currency_owns_the_display() ) {
			return $args;
		}

		// Named, but it IS the visitor's currency. Same answer as above.
		if ( $currency === $this->detection->get_current_currency() ) {
			return $args;
		}

		$format = $this->format_for( $currency );

		if ( null !== $format ) {
			return $this->apply_currency_format( $args, $format );
		}

		// A currency the plugin holds no format for — in practice the shop's
		// own base, which has no row of its own because it is not a conversion
		// target. Its format is WooCommerce's setting, which has to be read
		// with our overrides lifted or it hands back the visitor's again.
		return $this->apply_shop_format( $args );
	}

	/**
	 * Overwrite the number-format arguments from a stored currency format.
	 *
	 * @param array<string, mixed> $args   `wc_price()` arguments.
	 * @param array<string, mixed> $format Stored format for the target currency.
	 * @return array<string, mixed>
	 */
	private function apply_currency_format( array $args, array $format ): array {
		if ( isset( $format['decimal_sep'] ) ) {
			$args['decimal_separator'] = (string) $format['decimal_sep'];
		}

		if ( isset( $format['thousand_sep'] ) ) {
			$args['thousand_separator'] = (string) $format['thousand_sep'];
		}

		if ( isset( $format['decimals'] ) ) {
			$args['decimals'] = (int) $format['decimals'];
		}

		if ( isset( $format['position'] ) ) {
			$args['price_format'] = self::price_format_for_position( (string) $format['position'] );
		}

		return $args;
	}

	/**
	 * Restore WooCommerce's own configured format.
	 *
	 * The four overrides are lifted around the reads rather than the values
	 * being fetched from the options table directly, so that anything ELSE
	 * hooked to those filters still gets its say — this plugin is not the only
	 * thing entitled to an opinion about the shop's number format.
	 *
	 * @param array<string, mixed> $args `wc_price()` arguments.
	 * @return array<string, mixed>
	 */
	private function apply_shop_format( array $args ): array {
		remove_filter( 'wc_get_price_decimal_separator', array( $this, 'get_decimal_separator' ), 100 );
		remove_filter( 'wc_get_price_thousand_separator', array( $this, 'get_thousand_separator' ), 100 );
		remove_filter( 'wc_get_price_decimals', array( $this, 'get_decimals' ), 100 );
		remove_filter( 'pre_option_woocommerce_currency_pos', array( $this, 'get_currency_position' ), 100 );

		$args['decimal_separator']  = wc_get_price_decimal_separator();
		$args['thousand_separator'] = wc_get_price_thousand_separator();
		$args['decimals']           = wc_get_price_decimals();
		$args['price_format']       = get_woocommerce_price_format();

		add_filter( 'pre_option_woocommerce_currency_pos', array( $this, 'get_currency_position' ), 100, 1 );
		add_filter( 'wc_get_price_thousand_separator', array( $this, 'get_thousand_separator' ), 100, 1 );
		add_filter( 'wc_get_price_decimal_separator', array( $this, 'get_decimal_separator' ), 100, 1 );
		add_filter( 'wc_get_price_decimals', array( $this, 'get_decimals' ), 100, 1 );

		return $args;
	}

	/**
	 * WooCommerce's price format string for a given symbol position.
	 *
	 * Built by asking WooCommerce rather than by repeating its switch, so the
	 * four position values and the `woocommerce_price_format` filter other
	 * plugins hook keep working exactly as they do everywhere else.
	 *
	 * Public and static because the admin preview renders samples through the
	 * same WooCommerce templates rather than repeating the switch. It uses no
	 * instance state.
	 *
	 * @param string $position left|right|left_space|right_space.
	 * @return string
	 */
	public static function price_format_for_position( string $position ): string {
		$override = static function () use ( $position ) {
			return $position;
		};

		add_filter( 'pre_option_woocommerce_currency_pos', $override, PHP_INT_MAX );

		$format = get_woocommerce_price_format();

		remove_filter( 'pre_option_woocommerce_currency_pos', $override, PHP_INT_MAX );

		return $format;
	}

	/**
	 * The stored format for a currency code, or null when the plugin holds none.
	 *
	 * @param string $code Currency code (ISO 4217).
	 * @return array<string, mixed>|null
	 */
	private function format_for( string $code ): ?array {
		$currency = $this->store->get_currency( $code );

		if ( null === $currency || ! isset( $currency['format'] ) || ! is_array( $currency['format'] ) ) {
			return null;
		}

		return $currency['format'];
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

		// 🔴 Answer about the currency WooCommerce actually asked about.
		//
		// This parameter was declared and documented from the start and then
		// never read, so every caller got the visitor's symbol. On shop pages
		// that is invisible and correct: WooCommerce resolves an empty argument
		// through `get_woocommerce_currency()` before firing this filter, and
		// `get_currency_code()` above has already answered that with the
		// visitor's code — so `$currency` IS the visitor's currency there.
		//
		// It is wrong exactly where the amount belongs to some other currency.
		// Order screens render totals as
		// `wc_price( $amount, [ 'currency' => $order->get_currency() ] )`, so a
		// customer whose cookie now says EUR saw last month's USD order printed
		// with the EUR symbol. `OrderFilter::format_order_totals()` cannot undo
		// it either: that swaps the BASE symbol, and an order in a third
		// currency is not a shape it looks for.
		if ( $currency !== $this->detection->get_current_currency() ) {
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
		return $this->format_for( $this->detection->get_current_currency() );
	}
}
