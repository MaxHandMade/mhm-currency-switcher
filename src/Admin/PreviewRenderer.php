<?php
/**
 * Renders one price sample the way the storefront would render it.
 *
 * @package MhmCurrencySwitcher\Admin
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Admin;

use MhmCurrencySwitcher\Integration\WooCommerce\FormatFilter;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PreviewRenderer — one price sample, rendered by WooCommerce.
 *
 * @since 1.3.0
 */
final class PreviewRenderer {

	/**
	 * Render an amount in a currency's format, as plain text.
	 *
	 * 🔴 Two filters at priority 200, and both are needed for reasons that are
	 * easy to miss:
	 *
	 * - `wc_price()` never takes the SYMBOL from its arguments; it calls
	 *   `get_woocommerce_currency_symbol( $currency )`. Since the symbol is one
	 *   of the fields the panel now edits, a bare call would render the
	 *   WooCommerce table's symbol and silently contradict the field beside it.
	 * - This plugin's own `wc_price_args` hook sits at priority 100 and, today,
	 *   returns early in an admin REST request. Relying on that would make the
	 *   preview show SAVED formats the moment that guard changed, while the
	 *   admin is editing unsaved ones. 200 beats 100 either way.
	 *
	 * Both filters are removed immediately; nothing here outlives the call.
	 * The removal runs inside a `finally`, so that holds even if `wc_price()`
	 * — or anything hooked to `wc_price_args` or `woocommerce_currency_symbol`
	 * by another plugin — throws. Task 6 calls this in a loop over every
	 * currency row inside one REST dispatch; without the `finally`, one
	 * throwing row would leave the priority-200 symbol override registered
	 * for every row rendered after it, and for anything else in that request.
	 *
	 * A missing key in `$format` is filled from the same WooCommerce
	 * configuration `RestAPI::ensure_currency_format()` fills it from —
	 * `wc_get_price_decimal_separator()`, `wc_get_price_thousand_separator()`,
	 * the `woocommerce_currency_pos` option, and `RestAPI::default_symbol_for()`
	 * for the symbol — not a hardcoded US default. `$format` here is not
	 * guaranteed to have passed through `ensure_currency_format()`:
	 * `CurrencyStore::get_currencies()` returns raw persisted rows with no
	 * normalisation, so a legacy row, a partial save, or a manual import can
	 * reach this method with keys missing. Both call sites deriving the same
	 * fallback from the same source is what keeps a partial row's preview
	 * from disagreeing with what the storefront would actually show it.
	 *
	 * @param float                $amount Amount to render.
	 * @param string               $code   Currency code (ISO 4217).
	 * @param array<string, mixed> $format Format array: symbol, position, decimals, decimal_sep, thousand_sep.
	 *                                     Any key may be absent.
	 * @return string Plain text, no markup and no HTML entities.
	 */
	public static function render( float $amount, string $code, array $format ): string {
		$symbol = ( isset( $format['symbol'] ) && '' !== $format['symbol'] )
			? (string) $format['symbol']
			: RestAPI::default_symbol_for( $code );

		$symbol_filter = static function ( $current, $for ) use ( $symbol, $code ) {
			return $for === $code ? $symbol : $current;
		};

		$args_filter = static function ( $args ) use ( $format, $code ) {
			if ( ! is_array( $args ) || ( $args['currency'] ?? '' ) !== $code ) {
				return $args;
			}

			$args['decimals']           = (int) ( $format['decimals'] ?? 2 );
			$args['decimal_separator']  = (string) ( $format['decimal_sep'] ?? wc_get_price_decimal_separator() );
			$args['thousand_separator'] = (string) ( $format['thousand_sep'] ?? wc_get_price_thousand_separator() );
			$args['price_format']       = FormatFilter::price_format_for_position(
				(string) ( $format['position'] ?? get_option( 'woocommerce_currency_pos', 'left' ) )
			);

			return $args;
		};

		add_filter( 'woocommerce_currency_symbol', $symbol_filter, 200, 2 );
		add_filter( 'wc_price_args', $args_filter, 200, 1 );

		try {
			$html = wc_price( $amount, array( 'currency' => $code ) );
		} finally {
			remove_filter( 'wc_price_args', $args_filter, 200 );
			remove_filter( 'woocommerce_currency_symbol', $symbol_filter, 200 );
		}

		return trim( wp_strip_all_tags( html_entity_decode( (string) $html, ENT_QUOTES, 'UTF-8' ) ) );
	}
}
