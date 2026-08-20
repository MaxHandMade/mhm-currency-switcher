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

		// The raw rate is asked first, and that order is the point. A fee must
		// not be able to MANUFACTURE a rate: a currency with no rate at all and
		// a fixed fee of 2 would otherwise convert every price at "rate 2", a
		// number that came from nowhere.
		if ( $this->get_raw_rate( $code ) <= 0.0 ) {
			return false;
		}

		// The mirror of that rule, which was missing: a fee can also DESTROY a
		// rate. `get_rate()` applies the fee with no floor, so a percentage fee
		// of exactly -100 makes the effective rate 0 and prices every product
		// in the shop at zero, while anything below that turns them negative.
		// Asking only the raw rate here answered "usable" in both cases, so
		// FormatFilter dressed those figures in the visitor's symbol and the
		// page looked entirely normal. The amounts are not display-only: they
		// reach the cart and the charge.
		return $this->get_rate( $code ) > 0.0;
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
	 * Divides by the effective rate. Nothing in the plugin calls this: order
	 * meta records the rate at purchase time instead (CartFilter::save_order_meta),
	 * so no surface needs to invert a conversion. Kept as the arithmetic
	 * counterpart of convert(), and covered by ConverterTest. The docblock used
	 * to claim it was "used at checkout", which was never true.
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

		$result = $rounded - $subtract;

		// 🔴 Rounding must not destroy the price. Neither step has a floor of
		// its own: "nearest 1" takes anything under 0.50 to zero, and the
		// subtraction then carries it below. "Round to the nearest 1 and
		// subtract 0.01" is ordinary psychological pricing — it is the
		// configuration on this plugin's own development stack — and on a cheap
		// item it produced 0.00, or -0.01.
		//
		// That figure is not display-only. It reaches PriceFilter,
		// ShippingFilter, CartFilter and CouponFilter, which is to say the
		// amount the customer is charged.
		//
		// When the rule would take the price to zero or below, the unrounded
		// converted price is returned instead. Rounding is a presentation
		// preference; the price surviving is not.
		//
		// 🔴 `>= 0.0`, not `> 0.0`. The first version of this guard read
		// `$price > 0.0` and so skipped an amount of exactly zero: round(0) is
		// 0, and the subtraction then carried it to -0.01. Zero is not an edge
		// case here, it is the busiest member of the class — FREE SHIPPING has
		// a cost of 0.00 and reaches this method straight from ShippingFilter,
		// and WooCommerce adds whatever comes back into the cart total exactly
		// as it finds it. A free product does the same on the shop page.
		//
		// The guard was written alongside a test whose name promised "never
		// produces a price of zero or less" while only ever passing it a
		// positive number, so the hole and its test shipped together.
		if ( $result <= 0.0 && $price >= 0.0 ) {
			return $price;
		}

		return $result;
	}
}
