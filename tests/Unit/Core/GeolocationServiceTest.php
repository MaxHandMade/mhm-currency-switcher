<?php
/**
 * Tests for GeolocationService — country detection cascade.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use MhmCurrencySwitcher\Core\GeolocationService;
use PHPUnit\Framework\TestCase;

class GeolocationServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->reset_geo_globals();
	}

	protected function tearDown(): void {
		$this->reset_geo_globals();
		parent::tearDown();
	}

	private function reset_geo_globals(): void {
		unset(
			$_SERVER['HTTP_CF_IPCOUNTRY'],
			$GLOBALS['__mhmcs_test_geolocate_args'],
			$GLOBALS['__mhmcs_test_geo_country']
		);

		$GLOBALS['__mhmcs_test_geolocate_calls'] = 0;
	}

	/**
	 * The plugin must never let WooCommerce reach for a remote geolocation API.
	 *
	 * `WC_Geolocation::geolocate_ip()` defaults `$api_fallback` to true. On a
	 * store with no MaxMind database that branch sends the visitor's IP address
	 * to a third-party service (ipinfo.io / api.country.is, see
	 * `WC_Geolocation::$geoip_apis`) and caches the answer per IP for a day.
	 * WooCommerce's own storefront call passes false for exactly that reason
	 * (see `wc_get_customer_default_location()`); this plugin has to match that,
	 * otherwise
	 * turning on currency detection quietly starts exporting visitor IPs.
	 */
	public function test_geolocation_never_lets_woocommerce_call_a_remote_api(): void {
		$GLOBALS['__mhmcs_test_geo_country'] = 'TR';

		$service = new GeolocationService();
		$service->detect_country();

		$this->assertArrayHasKey(
			'api_fallback',
			$GLOBALS['__mhmcs_test_geolocate_args'] ?? array(),
			'geolocate_ip() was never called, so this test proves nothing -- '
			. 'the cascade changed shape.'
		);

		$this->assertFalse(
			$GLOBALS['__mhmcs_test_geolocate_args']['api_fallback'],
			'geolocate_ip() must be called with $api_fallback = false.'
		);

		/*
		 * $fallback too, and this one is deliberately STRICTER than WooCommerce
		 * core, which passes true. That argument gates get_external_ip_address(),
		 * which asks four remote lookup services for the SERVER's own public IP.
		 * It only helps in local development, where the visitor IP is
		 * unresolvable; in production it buys nothing and reopens an outbound
		 * request. Asserted separately because a mutation flipping only this
		 * argument left the whole suite green.
		 */
		$this->assertFalse(
			$GLOBALS['__mhmcs_test_geolocate_args']['fallback'],
			'geolocate_ip() must be called with $fallback = false, otherwise '
			. 'WooCommerce resolves the server public IP through remote lookup '
			. 'services.'
		);
	}

	/**
	 * The CloudFlare header short-circuits before WooCommerce is consulted.
	 *
	 * Without this, deleting the whole MaxMind branch would still pass the test
	 * above -- a call that never happens cannot pass a bad argument.
	 */
	public function test_cloudflare_header_short_circuits_before_woocommerce(): void {
		$_SERVER['HTTP_CF_IPCOUNTRY']        = 'DE';
		$GLOBALS['__mhmcs_test_geo_country'] = 'TR';

		$service = new GeolocationService();

		$this->assertSame( 'DE', $service->detect_country() );
		$this->assertSame(
			0,
			$GLOBALS['__mhmcs_test_geolocate_calls'],
			'With a CloudFlare country header present, WooCommerce geolocation '
			. 'must not be consulted at all.'
		);
	}

	public function test_detect_from_cloudflare_header(): void {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'TR';
		$service = new GeolocationService();

		$this->assertSame( 'TR', $service->detect_country() );
	}

	public function test_detect_cloudflare_lowercases_to_upper(): void {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'de';
		$service = new GeolocationService();

		$this->assertSame( 'DE', $service->detect_country() );
	}

	public function test_detect_cloudflare_ignores_xx(): void {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'XX';
		$service = new GeolocationService();

		$this->assertNull( $service->detect_country() );
	}

	public function test_detect_cloudflare_ignores_t1(): void {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'T1';
		$service = new GeolocationService();

		$this->assertNull( $service->detect_country() );
	}

	public function test_detect_returns_null_without_providers(): void {
		$service = new GeolocationService();

		$this->assertNull( $service->detect_country() );
	}

	public function test_detect_cloudflare_validates_format(): void {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'INVALID';
		$service = new GeolocationService();

		$this->assertNull( $service->detect_country() );
	}
}
