<?php
/**
 * Currency detection service.
 *
 * Determines which currency the current visitor is using via
 * cookie, URL parameter, geolocation, or base currency fallback.
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
 * DetectionService — cookie / URL param / geolocation currency detection.
 *
 * Detection order:
 *   1. Cookie (if set and valid).
 *   2. URL parameter `?currency=XXX` (if enabled and valid).
 *   3. Geolocation (if enabled and country maps to an enabled currency).
 *   4. Base currency (default fallback).
 *
 * "Valid" means the code exists in the enabled currencies list
 * or equals the base currency.
 *
 * @since 0.1.0
 */
final class DetectionService {

	/**
	 * Cookie name for storing the selected currency.
	 *
	 * @var string
	 */
	const COOKIE_NAME = 'mhm_cs_currency';

	/**
	 * URL query parameter name for currency switching.
	 *
	 * @var string
	 */
	const URL_PARAM = 'currency';

	/**
	 * Cookie lifetime in days.
	 *
	 * @var int
	 */
	const COOKIE_DAYS = 30;

	/**
	 * Currency data store instance.
	 *
	 * @var CurrencyStore
	 */
	private CurrencyStore $store;

	/**
	 * Whether URL parameter detection is enabled.
	 *
	 * @var bool
	 */
	private bool $url_param_enabled = false;

	/**
	 * Geolocation service instance.
	 *
	 * @var GeolocationService|null
	 */
	private ?GeolocationService $geolocation = null;

	/**
	 * Whether geolocation detection is enabled.
	 *
	 * @var bool
	 */
	private bool $geolocation_enabled = false;

	/**
	 * Constructor.
	 *
	 * @param CurrencyStore $store             Currency data store.
	 * @param bool          $url_param_enabled Whether to detect currency from URL param.
	 */
	public function __construct( CurrencyStore $store, bool $url_param_enabled = false ) {
		$this->store             = $store;
		$this->url_param_enabled = $url_param_enabled;
	}

	/**
	 * Enable or disable URL parameter detection.
	 *
	 * @param bool $enabled Whether URL param detection is enabled.
	 * @return void
	 */
	public function set_url_param_enabled( bool $enabled ): void {
		$this->url_param_enabled = $enabled;
	}

	/**
	 * Set the geolocation service and enable/disable it.
	 *
	 * @param GeolocationService $geolocation Geolocation service.
	 * @param bool               $enabled     Whether geolocation is enabled.
	 * @return void
	 */
	public function set_geolocation( GeolocationService $geolocation, bool $enabled ): void {
		$this->geolocation         = $geolocation;
		$this->geolocation_enabled = $enabled;
	}

	/**
	 * Get the current visitor's currency code.
	 *
	 * Detection order:
	 *   1. Cookie (if set and valid).
	 *   2. URL parameter (if enabled and valid).
	 *   3. Geolocation (if enabled and valid).
	 *   4. Base currency (default).
	 *
	 * @return string ISO 4217 currency code.
	 */
	public function get_current_currency(): string {
		$from_cookie = $this->detect_from_cookie();

		if ( null !== $from_cookie ) {
			return $from_cookie;
		}

		$from_url = $this->detect_from_url_param();

		if ( null !== $from_url ) {
			return $from_url;
		}

		$from_geo = $this->detect_from_geolocation();

		if ( null !== $from_geo ) {
			return $from_geo;
		}

		return $this->store->get_base_currency();
	}

	/**
	 * Set the currency cookie.
	 *
	 * Sets `mhm_cs_currency={code}` with path `/`, max-age 30 days,
	 * and SameSite=Lax.
	 *
	 * @param string $code ISO 4217 currency code.
	 * @return void
	 */
	public function set_currency( string $code ): void {
		$expires = time() + ( self::COOKIE_DAYS * DAY_IN_SECONDS );

		setcookie(
			self::COOKIE_NAME,
			$code,
			array(
				'expires'  => $expires,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Check if the current currency is the base currency.
	 *
	 * @return bool True when current currency equals the base currency.
	 */
	public function is_base_currency(): bool {
		return $this->get_current_currency() === $this->store->get_base_currency();
	}

	/**
	 * Get the cookie name constant.
	 *
	 * @return string Cookie name.
	 */
	public function get_cookie_name(): string {
		return self::COOKIE_NAME;
	}

	/**
	 * Detect currency from visitor geolocation.
	 *
	 * @return string|null Currency code, or null when unavailable/disabled.
	 */
	private function detect_from_geolocation(): ?string {
		if ( ! $this->geolocation_enabled || null === $this->geolocation ) {
			return null;
		}

		$country = $this->geolocation->detect_country();

		if ( null === $country ) {
			return null;
		}

		$currency = CountryCurrencyMap::get_currency( $country );

		if ( null === $currency ) {
			return null;
		}

		// Validate that the detected currency is enabled.
		if ( ! $this->validate_code( $currency ) ) {
			return null;
		}

		// Set cookie so geolocation doesn't re-run on next page load.
		$this->set_currency( $currency );

		return $currency;
	}

	/**
	 * Detect currency from the cookie.
	 *
	 * Reads `$_COOKIE[ COOKIE_NAME ]`, sanitises the value, and
	 * validates it against the enabled currencies or base currency.
	 *
	 * @return string|null Currency code, or null when cookie is absent or invalid.
	 */
	public function detect_from_cookie(): ?string {
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return null;
		}

		$raw = $this->sanitize_currency_code( $_COOKIE[ self::COOKIE_NAME ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( null === $raw ) {
			return null;
		}

		return $this->validate_code( $raw );
	}

	/**
	 * Detect currency from the URL query parameter.
	 *
	 * Reads `$_GET[ URL_PARAM ]`, sanitises the value, and validates
	 * it against the enabled currencies or base currency. Only active
	 * when URL parameter detection is enabled.
	 *
	 * @return string|null Currency code, or null when param is absent, invalid, or feature disabled.
	 */
	public function detect_from_url_param(): ?string {
		if ( ! $this->url_param_enabled ) {
			return null;
		}

		if ( ! isset( $_GET[ self::URL_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
			return null;
		}

		$raw = $this->sanitize_currency_code( $_GET[ self::URL_PARAM ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput

		if ( null === $raw ) {
			return null;
		}

		return $this->validate_code( $raw );
	}

	/**
	 * Sanitise and normalise a currency code from user input.
	 *
	 * Uppercases the value and ensures it is exactly 3 uppercase
	 * ASCII letters (ISO 4217 format).
	 *
	 * @param mixed $input Raw input value.
	 * @return string|null Sanitised 3-letter code, or null when invalid.
	 */
	private function sanitize_currency_code( $input ): ?string {
		if ( ! is_string( $input ) ) {
			return null;
		}

		$code = strtoupper( trim( $input ) );

		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $code ) ) {
			return null;
		}

		return $code;
	}

	/**
	 * Validate a currency code against enabled currencies or base currency.
	 *
	 * @param string $code Sanitised currency code.
	 * @return string|null The code when valid, or null otherwise.
	 */
	private function validate_code( string $code ): ?string {
		// Always accept the base currency.
		if ( $code === $this->store->get_base_currency() ) {
			return $code;
		}

		// Check if it is an enabled currency in the store.
		$currency = $this->store->get_currency( $code );

		if ( null !== $currency && ! empty( $currency['enabled'] ) ) {
			return $code;
		}

		return null;
	}
}
