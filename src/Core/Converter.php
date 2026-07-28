<?php
/**
 * Price conversion engine.
 *
 * Converts a price in the base currency to a target currency
 * using rate, fee, and rounding rules from CurrencyStore.
 *
 * @package MhmCurrencySwitcher\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converter — price conversion with fee, rounding, and revert.
 *
 * Takes a price in the WooCommerce base currency and converts it
 * to a target currency using the effective rate (rate + fee) and
 * optional rounding rules stored in CurrencyStore.
 *
 * @since 0.1.0
 */
final class Converter {

	/**
	 * Currency data store instance.
	 *
	 * @var CurrencyStore
	 */
	private CurrencyStore $store;

	/**
	 * Constructor.
	 *
	 * @param CurrencyStore $store Currency data store.
	 */
	public function __construct( CurrencyStore $store ) {
		$this->store = $store;
	}

	/**
	 * Convert a price from base currency to the target currency.
	 *
	 * Returns the original price when: price <= 0, target is the
	 * base currency, or the target currency is unknown.
	 *
	 * @param float  $price Price in base currency.
	 * @param string $to    Target currency code (ISO 4217).
	 * @return float Converted price.
	 */
	public function convert( float $price, string $to ): float {
		if ( $price <= 0.0 ) {
			return $price;
		}

		if ( $to === $this->store->get_base_currency() ) {
			return $price;
		}

		if ( ! $this->has_usable_rate( $to ) ) {
			return $price;
		}

		return $price * $this->get_rate( $to );
	}

	/**
	 * Whether this currency can actually be priced in.
	 *
	 * 🔴 Asked of the RAW rate, deliberately. The old guard compared the
	 * EFFECTIVE rate against zero, and a fixed fee is added to the raw rate —
	 * so a currency with no rate at all but a fee of 2 came out with an
	 * effective rate of 2 and every price was multiplied by a number that came
	 * from nowhere. A fee is a margin on a rate; it cannot stand in for one.
	 *
	 * The base currency is always usable: showing it involves no rate. Saying
	 * otherwise would make a shop whose base currency happens to carry a zero
	 * rate row unable to display its own prices.
	 *
	 * The whole display follows this answer, not just the amount. FormatFilter
	 * asks it too, so a currency that cannot be converted also keeps the base
	 * symbol — otherwise the visitor reads a base-currency NUMBER under a
	 * foreign symbol, which is a wrong price with no outward sign of trouble.
	 *
	 * @since 1.1.0
	 *
	 * @param string $code Currency code (ISO 4217).
	 * @return bool True when prices can be shown in this currency.
	 */
	public function has_usable_rate( string $code ): bool {
		if ( $code === $this->store->get_base_currency() ) {
			return true;
		}

		return $this->get_raw_rate( $code ) > 0.0;
	}

	/**
	 * Convert a price and apply rounding rules.
	 *
	 * @param float  $price Price in base currency.
	 * @param string $to    Target currency code (ISO 4217).
	 * @return float Converted and rounded price.
	 */
	public function convert_with_rounding( float $price, string $to ): float {
		$converted = $this->convert( $price, $to );

		$currency = $this->store->get_currency( $to );

		if ( null === $currency || ! isset( $currency['rounding'] ) ) {
			return $converted;
		}

		return $this->apply_rounding( $converted, $currency['rounding'] );
	}

	/**
	 * Get the effective exchange rate for a currency (fee included).
	 *
	 * Percentage fee: rate * (1 + fee% / 100).
	 * Fixed fee:      rate + fee.
	 *
	 * @param string $code Currency code (ISO 4217).
	 * @return float Effective rate, or 0.0 when the currency is unknown.
	 */
	public function get_rate( string $code ): float {
		$currency = $this->store->get_currency( $code );

		if ( null === $currency ) {
			return 0.0;
		}

		$raw_rate = (float) ( $currency['rate']['value'] ?? 0.0 );
		// `none`, matching every writer of this field: the REST sanitiser's own
		// default, its fallback for an unrecognised value, and the admin UI's
		// initial state. This reader used to assume `fixed` and was the only
		// place in the plugin that did, so a stored currency missing the key
		// had a fee quietly added to its rate.
		$fee_type = (string) ( $currency['fee']['type'] ?? 'none' );
		$fee_val  = (float) ( $currency['fee']['value'] ?? 0.0 );

		if ( 'percentage' === $fee_type ) {
			return $raw_rate * ( 1.0 + $fee_val / 100.0 );
		}

		if ( 'none' === $fee_type ) {
			return $raw_rate;
		}

		return $raw_rate + $fee_val;
	}

	/**
	 * Get the raw exchange rate without fee.
	 *
	 * @param string $code Currency code (ISO 4217).
	 * @return float Raw rate, or 0.0 when the currency is unknown.
	 */
	public function get_raw_rate( string $code ): float {
		$currency = $this->store->get_currency( $code );

		if ( null === $currency ) {
			return 0.0;
		}

		return (float) ( $currency['rate']['value'] ?? 0.0 );
	}

	/**
	 * Reverse-convert a price from target currency back to base.
	 *
	 * Divides by the effective rate. Used at checkout to obtain the
	 * base-currency amount.
	 *
	 * @param float  $price Price in the target currency.
	 * @param string $from  Source currency code (ISO 4217).
	 * @return float Price in base currency.
	 */
	public function revert( float $price, string $from ): float {
		if ( $price <= 0.0 ) {
			return $price;
		}

		if ( $from === $this->store->get_base_currency() ) {
			return $price;
		}

		$rate = $this->get_rate( $from );

		if ( 0.0 === $rate ) {
			return $price;
		}

		return $price / $rate;
	}

	/**
	 * Apply rounding rules to a converted price.
	 *
	 * Rounding types:
	 *   - nearest: round(price / value) * value - subtract
	 *   - up:      ceil(price / value) * value - subtract
	 *   - down:    floor(price / value) * value - subtract
	 *   - disabled: return as-is
	 *
	 * @param float                $price    Converted price.
	 * @param array<string, mixed> $rounding Rounding configuration.
	 * @return float Rounded price.
	 */
	private function apply_rounding( float $price, array $rounding ): float {
		$type     = (string) ( $rounding['type'] ?? 'disabled' );
		$value    = (float) ( $rounding['value'] ?? 0.0 );
		$subtract = (float) ( $rounding['subtract'] ?? 0.0 );

		if ( 'disabled' === $type || 0.0 === $value ) {
			return $price;
		}

		switch ( $type ) {
			case 'nearest':
				$rounded = round( $price / $value ) * $value;
				break;

			case 'up':
				$rounded = ceil( $price / $value ) * $value;
				break;

			case 'down':
				$rounded = floor( $price / $value ) * $value;
				break;

			default:
				return $price;
		}

		return $rounded - $subtract;
	}
}
