<?php
/**
 * Country to currency mapping.
 *
 * Maps ISO 3166-1 alpha-2 country codes to their default
 * ISO 4217 currency codes for geolocation-based detection.
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
 * CountryCurrencyMap — static country to currency lookup.
 *
 * @since 0.4.0
 */
final class CountryCurrencyMap {

	/**
	 * Country code to currency code mapping.
	 *
	 * @var array<string, string>
	 */
	// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing -- data map, compact format intentional.
	private static array $map = array(
		// Eurozone.
		'AT' => 'EUR', 'BE' => 'EUR', 'CY' => 'EUR', 'DE' => 'EUR',
		'EE' => 'EUR', 'ES' => 'EUR', 'FI' => 'EUR', 'FR' => 'EUR',
		'GR' => 'EUR', 'HR' => 'EUR', 'IE' => 'EUR', 'IT' => 'EUR',
		'LT' => 'EUR', 'LU' => 'EUR', 'LV' => 'EUR', 'MT' => 'EUR',
		'NL' => 'EUR', 'PT' => 'EUR', 'SI' => 'EUR', 'SK' => 'EUR',

		// Americas.
		'US' => 'USD', 'CA' => 'CAD', 'MX' => 'MXN', 'BR' => 'BRL',
		'AR' => 'ARS', 'CL' => 'CLP', 'CO' => 'COP', 'PE' => 'PEN',

		// Europe (non-euro).
		'GB' => 'GBP', 'CH' => 'CHF', 'SE' => 'SEK', 'NO' => 'NOK',
		'DK' => 'DKK', 'PL' => 'PLN', 'CZ' => 'CZK', 'HU' => 'HUF',
		'RO' => 'RON', 'BG' => 'BGN', 'UA' => 'UAH', 'RU' => 'RUB',
		'TR' => 'TRY', 'IS' => 'ISK',

		// Asia.
		'JP' => 'JPY', 'CN' => 'CNY', 'KR' => 'KRW', 'IN' => 'INR',
		'ID' => 'IDR', 'TH' => 'THB', 'VN' => 'VND', 'MY' => 'MYR',
		'SG' => 'SGD', 'PH' => 'PHP', 'TW' => 'TWD', 'HK' => 'HKD',
		'BD' => 'BDT', 'PK' => 'PKR', 'LK' => 'LKR', 'KH' => 'KHR',
		'MM' => 'MMK', 'NP' => 'NPR',

		// Middle East.
		'AE' => 'AED', 'SA' => 'SAR', 'QA' => 'QAR', 'KW' => 'KWD',
		'BH' => 'BHD', 'OM' => 'OMR', 'IL' => 'ILS', 'JO' => 'JOD',
		'LB' => 'LBP',

		// Africa.
		'ZA' => 'ZAR', 'NG' => 'NGN', 'EG' => 'EGP', 'KE' => 'KES',
		'GH' => 'GHS', 'TZ' => 'TZS', 'MA' => 'MAD', 'TN' => 'TND',
		'DZ' => 'DZD',

		// Oceania.
		'AU' => 'AUD', 'NZ' => 'NZD', 'FJ' => 'FJD',
	);
	// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing

	/**
	 * Get the default currency for a country code.
	 *
	 * @param string $country_code ISO 3166-1 alpha-2 country code.
	 * @return string|null ISO 4217 currency code, or null when unmapped.
	 */
	public static function get_currency( string $country_code ): ?string {
		$code = strtoupper( trim( $country_code ) );

		return self::$map[ $code ] ?? null;
	}

	/**
	 * Get the full country to currency map.
	 *
	 * @return array<string, string> Country code to currency code.
	 */
	public static function get_map(): array {
		return self::$map;
	}
}
