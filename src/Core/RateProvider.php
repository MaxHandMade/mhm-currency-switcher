<?php
/**
 * Exchange rate API fetcher with fallback chain.
 *
 * Fetches live exchange rates from external APIs and caches
 * them in WordPress transients for performance.
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
 * RateProvider — exchange rate API with fallback chain.
 *
 * Tries to fetch rates from a transient cache first, then falls
 * back to ExchangeRate-API, and finally to the Fawaz Ahmed API.
 * Successful responses are cached as transients for one day.
 *
 * @since 0.1.0
 */
final class RateProvider {

	/**
	 * Transient key prefix for cached rates.
	 *
	 * @var string
	 */
	const TRANSIENT_KEY_PREFIX = 'mhm_cs_rates_';

	/**
	 * Transient cache duration in seconds (1 day = 86400).
	 *
	 * @var int
	 */
	const TRANSIENT_EXPIRY = 86400;

	/**
	 * Fetch exchange rates for the given base currency.
	 *
	 * Lookup order:
	 *   1. Transient cache.
	 *   2. ExchangeRate-API (primary).
	 *   3. Fawaz Ahmed API (fallback).
	 *
	 * On success the result is stored in the transient cache.
	 *
	 * @param string $base Base currency code (ISO 4217, e.g. "TRY").
	 * @return array<string, float> Currency code => rate map, or empty array on failure.
	 */
	public function fetch_rates( string $base ): array {
		$cached = get_transient( self::TRANSIENT_KEY_PREFIX . strtoupper( $base ) );

		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		// Try primary API.
		$rates = $this->fetch_from_exchangerate_api( $base );

		// Fallback API.
		if ( empty( $rates ) ) {
			$rates = $this->fetch_from_fawaz_api( $base );
		}

		if ( ! empty( $rates ) ) {
			set_transient(
				self::TRANSIENT_KEY_PREFIX . strtoupper( $base ),
				$rates,
				self::TRANSIENT_EXPIRY
			);
		}

		return $rates;
	}

	/**
	 * Fetch the exchange rate for a single target currency.
	 *
	 * @param string $base   Base currency code (ISO 4217).
	 * @param string $target Target currency code (ISO 4217).
	 * @return float|null Rate or null when unavailable.
	 */
	public function fetch_single_rate( string $base, string $target ): ?float {
		$rates = $this->fetch_rates( $base );

		$target_upper = strtoupper( $target );

		if ( isset( $rates[ $target_upper ] ) ) {
			return (float) $rates[ $target_upper ];
		}

		return null;
	}

	/**
	 * Clear the transient cache for one or all base currencies.
	 *
	 * When `$base` is empty, a blanket delete is not possible with
	 * the Transient API, so this is effectively a no-op. Callers
	 * should pass a specific base currency code.
	 *
	 * @param string $base Base currency code, or empty for all.
	 * @return void
	 */
	public function clear_cache( string $base = '' ): void {
		if ( '' !== $base ) {
			delete_transient( self::TRANSIENT_KEY_PREFIX . strtoupper( $base ) );
		}
	}

	/**
	 * Parse the response body from ExchangeRate-API.
	 *
	 * Expected format: `{"rates": {"USD": 0.029, "EUR": 0.025, ...}}`
	 *
	 * @param array<string, mixed> $body Decoded JSON body.
	 * @return array<string, float> Currency code => rate map.
	 */
	public static function parse_exchangerate_response( array $body ): array {
		if ( ! isset( $body['rates'] ) || ! is_array( $body['rates'] ) ) {
			return array();
		}

		$rates = array();

		foreach ( $body['rates'] as $code => $value ) {
			if ( is_numeric( $value ) ) {
				$rates[ strtoupper( (string) $code ) ] = (float) $value;
			}
		}

		return $rates;
	}

	/**
	 * Parse the response body from the Fawaz Ahmed API.
	 *
	 * Expected format: `{"try": {"usd": 0.029, "eur": 0.025, ...}}`
	 * The outer key is the lowercase base currency code.
	 *
	 * @param array<string, mixed> $body Decoded JSON body.
	 * @param string               $base Base currency code.
	 * @return array<string, float> Currency code => rate map (uppercased keys).
	 */
	public static function parse_fawaz_response( array $body, string $base ): array {
		$base_lower = strtolower( $base );

		if ( ! isset( $body[ $base_lower ] ) || ! is_array( $body[ $base_lower ] ) ) {
			return array();
		}

		$rates = array();

		foreach ( $body[ $base_lower ] as $code => $value ) {
			if ( is_numeric( $value ) ) {
				$rates[ strtoupper( (string) $code ) ] = (float) $value;
			}
		}

		return $rates;
	}

	/**
	 * Fetch rates from the ExchangeRate-API (primary source).
	 *
	 * @param string $base Base currency code (ISO 4217).
	 * @return array<string, float> Currency code => rate map.
	 */
	private function fetch_from_exchangerate_api( string $base ): array {
		$url = 'https://api.exchangerate-api.com/v4/latest/' . strtoupper( $base );

		$data = $this->do_request( $url );

		if ( null === $data ) {
			return array();
		}

		return self::parse_exchangerate_response( $data );
	}

	/**
	 * Fetch rates from the Fawaz Ahmed Currency API (fallback).
	 *
	 * @param string $base Base currency code (ISO 4217).
	 * @return array<string, float> Currency code => rate map.
	 */
	private function fetch_from_fawaz_api( string $base ): array {
		$base_lower = strtolower( $base );
		$url        = 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/' . $base_lower . '.json';

		$data = $this->do_request( $url );

		if ( null === $data ) {
			return array();
		}

		return self::parse_fawaz_response( $data, $base );
	}

	/**
	 * Perform an HTTP GET request and return the decoded JSON body.
	 *
	 * @param string $url Full request URL.
	 * @return array<string, mixed>|null Decoded body or null on failure.
	 */
	private function do_request( string $url ): ?array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 10,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			return null;
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return $decoded;
	}
}
