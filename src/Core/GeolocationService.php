<?php
/**
 * Geolocation service — detect visitor country from IP.
 *
 * Uses a cascading provider chain: CloudFlare header first (zero-cost),
 * then WooCommerce's LOCAL MaxMind GeoIP database as fallback. WooCommerce's
 * remote-API fallback is deliberately switched off, so nothing about the
 * visitor ever leaves this server; with neither source present, detection
 * finds nothing rather than asking a third party.
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

		/*
		 * $api_fallback = false is the whole point of these arguments.
		 *
		 * geolocate_ip()'s own default is TRUE, and on a store with no MaxMind
		 * database that branch sends the visitor's IP address to a third-party
		 * service (ipinfo.io / api.country.is) and caches the answer per IP for
		 * a day. WooCommerce core does not do that on the storefront either --
		 * its own call passes false (see wc_get_customer_default_location()) -- so
		 * this with the bare default would make THIS plugin the thing that
		 * exports visitor IPs, not WooCommerce.
		 *
		 * $fallback = false is deliberate too, and here we are STRICTER than
		 * WooCommerce core, which passes true. That argument gates
		 * get_external_ip_address(), which resolves the SERVER's own public IP
		 * through yet another set of remote lookup services
		 * (woocommerce_geolocation_ip_lookup_apis). It exists for local
		 * development, where the visitor IP is 127.0.0.1 and therefore
		 * unresolvable. In production the visitor IP is already real, so the
		 * branch buys nothing and would reopen the outbound request this whole
		 * change closes.
		 *
		 * The cost is honest: with neither a CloudFlare header nor a MaxMind
		 * database, detection simply finds nothing and the visitor gets the
		 * base currency until they choose one.
		 */
		$geo = \WC_Geolocation::geolocate_ip( '', false, false );

		if ( empty( $geo['country'] ) ) {
			return null;
		}

		$code = strtoupper( trim( $geo['country'] ) );

		return 1 === preg_match( '/^[A-Z]{2}$/', $code ) ? $code : null;
	}
}
