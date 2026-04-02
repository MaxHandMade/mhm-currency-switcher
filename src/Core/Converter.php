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

		$rate = $this->get_rate( $to );

		if ( 0.0 === $rate ) {
			return $price;
		}

		return $price * $rate;
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
		$fee_type = (string) ( $currency['fee']['type'] ?? 'fixed' );
		$fee_val  = (float) ( $currency['fee']['value'] ?? 0.0 );

		if ( 'percentage' === $fee_type ) {
			return $raw_rate * ( 1.0 + $fee_val / 100.0 );
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
