<?php
/**
 * Integration tests: the geolocation cookie priming that runs on
 * `template_redirect` never reaches the cookie write on a response a page
 * cache is meant to store (design spec §5.1, §5.3, §8.3).
 *
 * ⚠️ THE COOKIE ITSELF IS NOT OBSERVABLE HERE, and pretending otherwise
 * produces a test that passes for the wrong reason. This suite boots through
 * tests/bootstrap-integration.php, which keeps PHP's real setcookie() and
 * real headers_sent() — and headers_sent() answers TRUE for any CLI process
 * that has already produced output, which every PHPUnit run has from its
 * first progress dot. set_currency() therefore refuses to write on EVERY
 * path in this environment (correctly: that is the §8.3 guard), so
 * "$_COOKIE is empty" is true whether or not the fix exists. Measured, not
 * assumed: a diagnostic run reported headers_sent=true, cookie=NULL,
 * current=EUR on the cache-compat-OFF path, which must write.
 *
 * The observable that does distinguish the two paths is whether the priming
 * ran the detection chain at all. On a cacheable render it short-circuits
 * BEFORE the geolocation lookup, and therefore before the write; on every
 * other render it runs through to it. Byte-level cookie counting lives in
 * the unit suite, whose namespaced setcookie() stub records writes.
 *
 * What only real WordPress can answer, and is locked here:
 *
 *   1. The callback really is hooked to `template_redirect` at priority 0 —
 *      the moment the whole fix is written against.
 *   2. By the time that hook can run, the `wp` action HAS fired. The decision
 *      table answers "convert" before `wp` (branch 7), so were the ordering
 *      the other way round the suppression could never engage and the
 *      Set-Cookie would ride out on every cacheable page.
 *   3. The real ConversionContext the real Plugin built, reading the real
 *      `mhmcs_settings` option, reaches the display branch on an ordinary
 *      front-end request — and the toggle still flips it back.
 *
 * File name sorts before ConversionContextWiringTest on purpose: that class
 * defines WOOCOMMERCE_CART for the remainder of the PHPUnit process, and
 * every assertion here depends on NOT being in a money context.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use MhmCurrencySwitcher\Core\DetectionService;
use ReflectionProperty;

/**
 * Class CacheCookiePrimingTest
 */
class CacheCookiePrimingTest extends MhmcsIntegrationTestCase {

	/**
	 * Start each test as an anonymous front-end request with no currency
	 * cookie and cache compatibility explicitly on.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->set_cache_compat( true );
		$this->clear_visitor_currency();
	}

	/**
	 * Leave no setting or cookie behind.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->clear_visitor_currency();

		delete_option( 'mhmcs_settings' );

		parent::tear_down();
	}

	/**
	 * The hook the fix is written against: priority 0 on `template_redirect`,
	 * i.e. after the main query has run and before anything is rendered.
	 *
	 * @return void
	 */
	public function test_priming_is_hooked_to_template_redirect_at_priority_zero(): void {
		$detection = $this->shared_detection_service();

		$this->assertInstanceOf( DetectionService::class, $detection, 'Guard: the plugin must have booted.' );
		$this->assertSame(
			0,
			has_action( 'template_redirect', array( $detection, 'prime_currency_cookie' ) ),
			'prime_currency_cookie() must run at template_redirect priority 0; the whole suppression is written against that moment.'
		);
	}

	/**
	 * 🔴 By the time `template_redirect` can fire, `wp` already has.
	 *
	 * WordPress fires `wp` from WP::main(), i.e. while routing the request;
	 * `template_redirect` is fired later, by wp-includes/template-loader.php,
	 * which wp-blog-header.php requires only after wp() has returned. This
	 * asserts the half a test can observe: routing a real front-end URL fires
	 * `wp`.
	 *
	 * It matters because decision 7 answers "convert" before `wp`. Were the
	 * ordering reversed, is_cacheable_render() would answer false on every
	 * request, the suppression would never engage, and the Set-Cookie would
	 * ride out on every cacheable page.
	 *
	 * @return void
	 */
	public function test_the_wp_action_has_fired_before_template_redirect_can_run(): void {
		unset( $GLOBALS['wp_actions']['wp'] );

		$this->assertSame( 0, did_action( 'wp' ), 'Guard: the request starts before the `wp` action.' );

		$this->go_to( home_url( '/' ) );

		$this->assertGreaterThan(
			0,
			did_action( 'wp' ),
			'Routing a front-end request must fire `wp`; template_redirect is fired strictly later, by the template loader.'
		);
	}

	/**
	 * 🔴 A cacheable render short-circuits before the lookup, and therefore
	 * before the cookie write.
	 *
	 * A Set-Cookie on such a response fails in both directions: a cache that
	 * stores it serves the first visitor's currency to everyone after them,
	 * and a cache that refuses to store any response carrying Set-Cookie
	 * (WP Rocket, LiteSpeed) never caches the page at all. §5.1 already covers
	 * this visitor: the client posts `currency: null`, the convert endpoint
	 * geolocates, and price-converter.js writes the cookie itself.
	 *
	 * @return void
	 */
	public function test_cacheable_render_never_reaches_the_cookie_write(): void {
		$this->go_to( home_url( '/' ) );

		$this->enable_geolocation_to( 'DE' );

		$detection = $this->shared_detection_service();
		$detection->prime_currency_cookie();

		$this->assertFalse(
			$this->geolocation_attempted( $detection ),
			'On a cacheable render the priming must return before the detection chain runs, so the cookie write is never reached.'
		);

		// Anti-vacuity: the very same setup DOES resolve EUR when actually
		// asked, so the assertion above is a decision and not a dead lookup.
		$this->assertSame(
			self::TARGET_CURRENCY,
			$detection->get_current_currency(),
			'Guard: geolocation is wired and resolves EUR on this request.'
		);
		$this->assertTrue(
			$this->geolocation_attempted( $detection ),
			'Guard: asking for the currency does run the lookup, so the flag genuinely distinguishes the two paths.'
		);
	}

	/**
	 * Control, and the behaviour that must NOT regress: with cache
	 * compatibility off the priming still runs the chain through to the
	 * cookie write. That mode emits no marker and ships no client converter,
	 * so the server-side cookie is the only persistence the visitor gets.
	 *
	 * @return void
	 */
	public function test_cache_compat_off_still_reaches_the_cookie_write(): void {
		$this->set_cache_compat( false );

		$this->go_to( home_url( '/' ) );

		$this->enable_geolocation_to( 'DE' );

		$detection = $this->shared_detection_service();
		$detection->prime_currency_cookie();

		$this->assertTrue(
			$this->geolocation_attempted( $detection ),
			'With cache compatibility off the priming must still resolve and persist the geolocated currency.'
		);
	}

	// ─── Helpers ─────────────────────────────────────────────────────

	/**
	 * Whether the service has run its single per-request geolocation lookup.
	 *
	 * Reflection because nothing in production ever asks — the same reason
	 * MhmcsIntegrationTestCase::reset_detection_service() reaches for this
	 * exact property.
	 *
	 * @param DetectionService $detection The shared detection service.
	 * @return bool
	 */
	private function geolocation_attempted( DetectionService $detection ): bool {
		$property = new ReflectionProperty( DetectionService::class, 'geolocation_attempted' );
		$property->setAccessible( true );

		return (bool) $property->getValue( $detection );
	}

	/**
	 * Write the cache-compatibility setting the real ConversionContext reads.
	 *
	 * @param bool $enabled Whether cache compatibility mode is on.
	 * @return void
	 */
	private function set_cache_compat( bool $enabled ): void {
		$settings = get_option( 'mhmcs_settings', array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings['cache_compat'] = $enabled;

		update_option( 'mhmcs_settings', $settings );
	}
}
