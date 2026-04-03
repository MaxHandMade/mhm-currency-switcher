<?php
/**
 * Currency to country code mapper for flag images.
 *
 * Provides a static mapping of ISO 4217 currency codes to
 * ISO 3166-1 alpha-2 country codes used for flag SVG filenames.
 *
 * @package MhmCurrencySwitcher\Frontend
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Frontend;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FlagMapper — currency-to-country code mapping for flag images.
 *
 * @since 0.3.0
 */
final class FlagMapper {

	/**
	 * Currency code to country code mapping.
	 *
	 * Uses the primary country associated with each currency.
	 *
	 * @var array<string, string>
	 */
	private static array $map = array(
		'USD' => 'us',
		'EUR' => 'eu',
		'GBP' => 'gb',
		'TRY' => 'tr',
		'JPY' => 'jp',
		'CAD' => 'ca',
		'AUD' => 'au',
		'CHF' => 'ch',
		'CNY' => 'cn',
		'SEK' => 'se',
		'NZD' => 'nz',
		'KRW' => 'kr',
		'SGD' => 'sg',
		'NOK' => 'no',
		'MXN' => 'mx',
		'INR' => 'in',
		'RUB' => 'ru',
		'ZAR' => 'za',
		'BRL' => 'br',
		'HKD' => 'hk',
		'DKK' => 'dk',
		'PLN' => 'pl',
		'THB' => 'th',
		'MYR' => 'my',
		'IDR' => 'id',
		'CZK' => 'cz',
		'HUF' => 'hu',
		'ILS' => 'il',
		'PHP' => 'ph',
		'RON' => 'ro',
		'AED' => 'ae',
		'SAR' => 'sa',
		'ARS' => 'ar',
		'CLP' => 'cl',
		'COP' => 'co',
		'EGP' => 'eg',
		'TWD' => 'tw',
		'UAH' => 'ua',
		'VND' => 'vn',
		'PKR' => 'pk',
		'BGN' => 'bg',
		'HRK' => 'hr',
		'ISK' => 'is',
		'AFN' => 'af',
		'ALL' => 'al',
		'AMD' => 'am',
		'ANG' => 'an',
		'AOA' => 'ao',
		'AWG' => 'aw',
		'AZN' => 'az',
		'BAM' => 'ba',
		'BBD' => 'bb',
		'BDT' => 'bd',
		'BHD' => 'bh',
		'BIF' => 'bi',
		'BMD' => 'bm',
		'BND' => 'bn',
		'BOB' => 'bo',
		'BSD' => 'bs',
		'BTN' => 'bt',
		'BWP' => 'bw',
		'BYR' => 'by',
		'BZD' => 'bz',
		'CDF' => 'cd',
		'CRC' => 'cr',
		'CUC' => 'cu',
		'CUP' => 'cu',
		'CVE' => 'cv',
		'DJF' => 'dj',
		'DOP' => 'do',
		'DZD' => 'dz',
		'ERN' => 'er',
		'ETB' => 'et',
		'FJD' => 'fj',
		'FKP' => 'fk',
		'GEL' => 'ge',
		'GGP' => 'gg',
		'GHS' => 'gh',
		'GIP' => 'gi',
		'GMD' => 'gm',
		'GNF' => 'gn',
		'GTQ' => 'gt',
		'GYD' => 'gy',
		'HNL' => 'hn',
		'HTG' => 'ht',
		'IMP' => 'im',
		'IQD' => 'iq',
		'IRR' => 'ir',
		'JEP' => 'je',
		'JMD' => 'jm',
		'JOD' => 'jo',
		'KES' => 'ke',
		'KGS' => 'kg',
		'KHR' => 'kh',
		'KMF' => 'km',
		'KPW' => 'kp',
		'KWD' => 'kw',
		'KYD' => 'ky',
		'KZT' => 'kz',
		'LAK' => 'la',
		'LBP' => 'lb',
		'LKR' => 'lk',
		'LRD' => 'lr',
		'LSL' => 'ls',
		'LYD' => 'ly',
		'MAD' => 'ma',
		'MDL' => 'md',
		'MGA' => 'mg',
		'MKD' => 'mk',
		'MMK' => 'mm',
		'MNT' => 'mn',
		'MOP' => 'mo',
		'MRU' => 'mr',
		'MUR' => 'mu',
		'MVR' => 'mv',
		'MWK' => 'mw',
		'MZN' => 'mz',
		'NAD' => 'na',
		'NGN' => 'ng',
		'NIO' => 'ni',
		'NPR' => 'np',
		'OMR' => 'om',
		'PAB' => 'pa',
		'PEN' => 'pe',
		'PGK' => 'pg',
		'PRB' => 'pr',
		'PYG' => 'py',
		'QAR' => 'qa',
		'RSD' => 'rs',
		'RWF' => 'rw',
		'SBD' => 'sb',
		'SCR' => 'sc',
		'SDG' => 'sd',
		'SHP' => 'sh',
		'SLL' => 'sl',
		'SOS' => 'so',
		'SRD' => 'sr',
		'SSP' => 'ss',
		'STN' => 'st',
		'SYP' => 'sy',
		'SZL' => 'sz',
		'TJS' => 'tj',
		'TMT' => 'tm',
		'TND' => 'tn',
		'TOP' => 'to',
		'TTD' => 'tt',
		'TZS' => 'tz',
		'UGX' => 'ug',
		'UYU' => 'uy',
		'UZS' => 'uz',
		'VEF' => 've',
		'VES' => 've',
		'VUV' => 'vu',
		'WST' => 'ws',
		'XAF' => 'xa',
		'XCD' => 'xc',
		'XOF' => 'xo',
		'XPF' => 'xp',
		'YER' => 'ye',
		'ZMW' => 'zm',
		'ZWL' => 'zw',
	);

	/**
	 * Get the full currency-to-country mapping array.
	 *
	 * @return array<string, string> Currency code => country code map.
	 */
	public static function get_map(): array {
		return self::$map;
	}

	/**
	 * Get the country code for a given currency code.
	 *
	 * Returns the lowercase ISO 3166-1 alpha-2 country code
	 * associated with the currency. Falls back to the first two
	 * lowercase letters of the currency code when no mapping exists.
	 *
	 * @param string $currency_code ISO 4217 currency code (e.g. "USD").
	 * @return string Lowercase country code (e.g. "us").
	 */
	public static function get_country( string $currency_code ): string {
		$upper = strtoupper( $currency_code );

		if ( isset( self::$map[ $upper ] ) ) {
			return self::$map[ $upper ];
		}

		// Fallback: use first two characters lowercased.
		return strtolower( substr( $currency_code, 0, 2 ) );
	}

	/**
	 * Get the full flag image URL for a currency code.
	 *
	 * @param string $currency_code ISO 4217 currency code (e.g. "USD").
	 * @return string Full URL to the flag SVG image.
	 */
	public static function get_flag_url( string $currency_code ): string {
		$country = self::get_country( $currency_code );

		return MHM_CS_URL . 'assets/images/flags/' . $country . '.svg';
	}
}
