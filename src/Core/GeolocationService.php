<?php
/**
 * Geolocation service — detect visitor country from IP.
 *
 * Uses a cascading provider chain: CloudFlare header first (zero-cost),
 * then WooCommerce MaxMind GeoIP database as fallback.
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
 * GeolocationService — country detection via IP geolocation.
 *
 * @since 0.4.0
 */
final class GeolocationService {

	/**
	 * CloudFlare country codes that should be ignored.
	 *
	 * XX = unknown, T1 = Tor exit node.
	 *
	 * @var array<int, string>
	 */
	const IGNORED_CODES = array( 'XX', 'T1' );

	/**
	 * Detect the visitor's country code.
	 *
	 * @return string|null ISO 3166-1 alpha-2 country code, or null when unavailable.
	 */
	public function detect_country(): ?string {
		$country = $this->detect_from_cloudflare();

		if ( null !== $country ) {
			return $country;
		}

		return $this->detect_from_wc_maxmind();
	}

	/**
	 * Detect country from CloudFlare CF-IPCountry header.
	 *
	 * @return string|null Country code, or null.
	 */
	private function detect_from_cloudflare(): ?string {
		if ( ! isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			return null;
		}

		$code = strtoupper( trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) ) );

		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) ) {
			return null;
		}

		if ( in_array( $code, self::IGNORED_CODES, true ) ) {
			return null;
		}

		return $code;
	}

	/**
	 * Detect country from WooCommerce MaxMind GeoIP database.
	 *
	 * @return string|null Country code, or null.
	 */
	private function detect_from_wc_maxmind(): ?string {
		if ( ! class_exists( 'WC_Geolocation' ) ) {
			return null;
		}

		$geo = \WC_Geolocation::geolocate_ip();

		if ( empty( $geo['country'] ) ) {
			return null;
		}

		$code = strtoupper( trim( $geo['country'] ) );

		return 1 === preg_match( '/^[A-Z]{2}$/', $code ) ? $code : null;
	}
}
