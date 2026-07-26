<?php
/**
 * Unit tests for DetectionService.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;
use MhmCurrencySwitcher\Core\GeolocationService;
use PHPUnit\Framework\TestCase;

/**
 * Class DetectionServiceTest
 *
 * Pure unit tests — no WordPress dependency.
 * Manipulates the $_COOKIE superglobal and the get_query_var() stub
 * ($GLOBALS['__mhmcs_test_query_vars']) directly.
 *
 * Setup: CurrencyStore with TRY base, USD (enabled), EUR (enabled).
 *
 * @covers \MhmCurrencySwitcher\Core\DetectionService
 */
class DetectionServiceTest extends TestCase {

	/**
	 * Currency data store.
	 *
	 * @var CurrencyStore
	 */
	private CurrencyStore $store;

	/**
	 * Detection service instance under test.
	 *
	 * @var DetectionService
	 */
	private DetectionService $service;

	/**
	 * Set up the store and service with TRY base, USD + EUR enabled.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->store = new CurrencyStore();
		$this->store->set_data(
			'TRY',
			array(
				array(
					'code'            => 'USD',
					'enabled'         => true,
					'sort_order'      => 0,
					'rate'            => array(
						'type'  => 'manual',
						'value' => 0.03,
					),
					'fee'             => array(
						'type'  => 'fixed',
						'value' => 0,
					),
					'rounding'        => array(
						'type'     => 'disabled',
						'value'    => 0,
						'subtract' => 0,
					),
					'format'          => array(
						'symbol'       => '$',
						'position'     => 'left',
						'thousand_sep' => ',',
						'decimal_sep'  => '.',
						'decimals'     => 2,
					),
				),
				array(
					'code'            => 'EUR',
					'enabled'         => true,
					'sort_order'      => 1,
					'rate'            => array(
						'type'  => 'manual',
						'value' => 0.025,
					),
					'fee'             => array(
						'type'  => 'fixed',
						'value' => 0,
					),
					'rounding'        => array(
						'type'     => 'disabled',
						'value'    => 0,
						'subtract' => 0,
					),
					'format'          => array(
						'symbol'       => "\u{20AC}",
						'position'     => 'right',
						'thousand_sep' => '.',
						'decimal_sep'  => ',',
						'decimals'     => 2,
					),
				),
			)
		);

		$this->service = new DetectionService( $this->store );

		// Ensure clean state.
		$this->reset_request_globals();
	}

	/**
	 * Clean up superglobals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->reset_request_globals();

		parent::tearDown();
	}

	/**
	 * Reset every per-request global this class touches.
	 *
	 * The geolocation counter and the recorded cookie writes are global
	 * state shared with GeolocationServiceTest; leaving either behind would
	 * make a later test pass or fail depending on execution order.
	 *
	 * @return void
	 */
	private function reset_request_globals(): void {
		unset( $_COOKIE[ DetectionService::COOKIE_NAME ] );
		unset( $GLOBALS['__mhmcs_test_query_vars'][ DetectionService::URL_PARAM ] );
		unset( $GLOBALS['__mhmcs_test_geo_country'] );
		unset( $GLOBALS['__mhmcs_test_headers_sent'] );
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );

		$GLOBALS['__mhmcs_test_geolocate_calls'] = 0;
		$GLOBALS['__mhmcs_test_setcookie']       = array();
	}

	/**
	 * Number of geolocation lookups performed so far.
	 *
	 * @return int
	 */
	private function geolocate_calls(): int {
		return (int) $GLOBALS['__mhmcs_test_geolocate_calls'];
	}

	/**
	 * Cookie writes recorded so far.
	 *
	 * @return array
	 */
	private function cookie_writes(): array {
		return (array) $GLOBALS['__mhmcs_test_setcookie'];
	}

	/**
	 * Enable geolocation on the service under test, resolving to Germany
	 * (which CountryCurrencyMap maps to EUR, an enabled currency here).
	 *
	 * @return void
	 */
	private function enable_geolocation_to_germany(): void {
		$GLOBALS['__mhmcs_test_geo_country'] = 'DE';

		$this->service->set_geolocation( new GeolocationService(), true );
	}

	/**
	 * Test that base currency is returned when no cookie and no URL param.
	 *
	 * @return void
	 */
	public function test_returns_base_when_no_cookie_no_param(): void {
		$this->assertSame( 'TRY', $this->service->get_current_currency() );
	}

	/**
	 * Test that currency is detected from cookie.
	 *
	 * @return void
	 */
	public function test_returns_currency_from_cookie(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$this->assertSame( 'USD', $this->service->get_current_currency() );
	}

	/**
	 * Test that currency is detected from URL parameter when enabled.
	 *
	 * @return void
	 */
	public function test_returns_currency_from_url_param(): void {
		$this->service->set_url_param_enabled( true );

		$GLOBALS['__mhmcs_test_query_vars'][ DetectionService::URL_PARAM ] = 'EUR';

		$this->assertSame( 'EUR', $this->service->get_current_currency() );
	}

	/**
	 * Test that cookie takes priority over URL parameter.
	 *
	 * @return void
	 */
	public function test_cookie_takes_priority_over_url_param(): void {
		$this->service->set_url_param_enabled( true );

		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$GLOBALS['__mhmcs_test_query_vars'][ DetectionService::URL_PARAM ] = 'EUR';

		$this->assertSame( 'USD', $this->service->get_current_currency() );
	}

	/**
	 * Test that an invalid currency code in the cookie is ignored.
	 *
	 * @return void
	 */
	public function test_ignores_invalid_currency_in_cookie(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'INVALID';

		$this->assertSame( 'TRY', $this->service->get_current_currency() );
	}

	/**
	 * Test that a disabled currency in the cookie is ignored.
	 *
	 * @return void
	 */
	public function test_ignores_disabled_currency(): void {
		// Re-create store with EUR disabled.
		$store = new CurrencyStore();
		$store->set_data(
			'TRY',
			array(
				array(
					'code'            => 'USD',
					'enabled'         => true,
					'sort_order'      => 0,
					'rate'            => array(
						'type'  => 'manual',
						'value' => 0.03,
					),
					'fee'             => array(
						'type'  => 'fixed',
						'value' => 0,
					),
					'rounding'        => array(
						'type'     => 'disabled',
						'value'    => 0,
						'subtract' => 0,
					),
					'format'          => array(
						'symbol'       => '$',
						'position'     => 'left',
						'thousand_sep' => ',',
						'decimal_sep'  => '.',
						'decimals'     => 2,
					),
				),
				array(
					'code'            => 'EUR',
					'enabled'         => false,
					'sort_order'      => 1,
					'rate'            => array(
						'type'  => 'manual',
						'value' => 0.025,
					),
					'fee'             => array(
						'type'  => 'fixed',
						'value' => 0,
					),
					'rounding'        => array(
						'type'     => 'disabled',
						'value'    => 0,
						'subtract' => 0,
					),
					'format'          => array(
						'symbol'       => "\u{20AC}",
						'position'     => 'right',
						'thousand_sep' => '.',
						'decimal_sep'  => ',',
						'decimals'     => 2,
					),
				),
			)
		);

		$service = new DetectionService( $store );

		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'EUR';

		$this->assertSame( 'TRY', $service->get_current_currency() );
	}

	/**
	 * Test that is_base_currency returns true when no cookie or param is set.
	 *
	 * @return void
	 */
	public function test_is_base_currency_returns_true(): void {
		$this->assertTrue( $this->service->is_base_currency() );
	}

	/**
	 * Test that add_query_var() appends the currency public query var.
	 *
	 * @return void
	 */
	public function test_add_query_var_appends_currency(): void {
		$vars = $this->service->add_query_var( array( 'existing' ) );

		$this->assertContains( DetectionService::URL_PARAM, $vars );
		$this->assertContains( 'existing', $vars );
	}

	/**
	 * Test that URL param is ignored when the query var is absent.
	 *
	 * @return void
	 */
	public function test_ignores_url_param_when_query_var_absent(): void {
		$this->service->set_url_param_enabled( true );

		// No query var set — should fall back to base currency.
		$this->assertSame( 'TRY', $this->service->get_current_currency() );
	}

	/**
	 * The request override outranks every other detection source and must
	 * leave no trace in the visitor's browser.
	 *
	 * The convert endpoint (design spec §5.1) resolves the currency itself
	 * and then asks the server to render in it. If that request also wrote
	 * the currency cookie, the same cookie would be written by both PHP and
	 * JS and §5.3's "the cookie belongs to the client" contract would break:
	 * a failed client-side detection could be silently pinned server-side and
	 * the detection chain would never retry.
	 *
	 * @return void
	 */
	public function test_request_override_wins_and_writes_no_cookie(): void {
		$this->service->set_url_param_enabled( true );

		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$GLOBALS['__mhmcs_test_query_vars'][ DetectionService::URL_PARAM ] = 'USD';

		$this->service->set_request_override( 'EUR' );

		$this->assertSame(
			'EUR',
			$this->service->get_current_currency(),
			'The request override must outrank both the cookie and the URL parameter.'
		);
		$this->assertSame(
			array(),
			$this->cookie_writes(),
			'A request override must never write the currency cookie.'
		);
	}

	/**
	 * With an override active, geolocation must not run at all.
	 *
	 * Not "runs but is ignored": the lookup itself is the side effect that
	 * matters, because a successful lookup is what queues the server-side
	 * cookie write the endpoint context must suppress (spec §4). The
	 * WC_Geolocation stub counts invocations so the two cases are
	 * distinguishable.
	 *
	 * @return void
	 */
	public function test_geolocation_does_not_run_while_override_active(): void {
		$this->enable_geolocation_to_germany();

		$this->service->set_request_override( 'USD' );

		$this->assertSame( 'USD', $this->service->get_current_currency() );

		$this->service->prime_currency_cookie();

		$this->assertSame(
			0,
			$this->geolocate_calls(),
			'Geolocation must not be consulted while a request override is active.'
		);
		$this->assertSame(
			array(),
			$this->cookie_writes(),
			'An override must also suppress the geolocation cookie write.'
		);
	}

	/**
	 * Control for the test above: without an override the very same setup
	 * DOES geolocate and DOES prime the cookie. Without this control,
	 * "0 lookups" could just mean the stub was never wired up.
	 *
	 * @return void
	 */
	public function test_geolocation_runs_and_primes_the_cookie_without_an_override(): void {
		$this->enable_geolocation_to_germany();

		$this->assertSame( 'EUR', $this->service->get_current_currency() );

		$this->service->prime_currency_cookie();

		$this->assertSame( 1, $this->geolocate_calls() );

		$writes = $this->cookie_writes();

		$this->assertCount( 1, $writes, 'Exactly one cookie write is expected.' );
		$this->assertSame( DetectionService::COOKIE_NAME, $writes[0]['name'] );
		$this->assertSame( 'EUR', $writes[0]['value'] );
	}

	/**
	 * Geolocation runs at most once per request (spec §3.3).
	 *
	 * setcookie() does not update $_COOKIE, so before the memo every price
	 * surface that asked for the currency re-ran the whole lookup — several
	 * MaxMind hits on a single page render.
	 *
	 * @return void
	 */
	public function test_geolocation_runs_at_most_once_per_request(): void {
		$this->enable_geolocation_to_germany();

		$this->service->get_current_currency();
		$this->service->get_current_currency();
		$this->service->get_current_currency();

		$this->assertSame(
			1,
			$this->geolocate_calls(),
			'Geolocation must be memoised for the request, not repeated per price read.'
		);
	}

	/**
	 * The geolocation cookie is NOT written from inside a currency read
	 * (spec §8.3).
	 *
	 * A currency read happens during price filtering, i.e. mid-render, when
	 * the response headers may already be on the wire — the write would then
	 * fail silently. The write belongs to prime_currency_cookie(), which
	 * runs on template_redirect before any output.
	 *
	 * @return void
	 */
	public function test_geolocation_cookie_is_not_written_during_a_currency_read(): void {
		$this->enable_geolocation_to_germany();

		$this->assertSame( 'EUR', $this->service->get_current_currency() );
		$this->assertSame(
			array(),
			$this->cookie_writes(),
			'Reading the currency must not write a cookie; only prime_currency_cookie() may.'
		);
	}

	/**
	 * Writing the cookie also updates $_COOKIE.
	 *
	 * PHP's setcookie() only queues a response header, so without this the
	 * chain's first step keeps missing for the rest of the request and every
	 * later read falls through to geolocation again (spec §3.3).
	 *
	 * @return void
	 */
	public function test_cookie_write_updates_the_cookie_superglobal(): void {
		$this->service->set_currency( 'USD' );

		$this->assertSame( 'USD', $_COOKIE[ DetectionService::COOKIE_NAME ] );
		$this->assertSame( 'USD', $this->service->detect_from_cookie() );
	}

	/**
	 * Once the headers are on the wire the cookie write is skipped rather
	 * than attempted.
	 *
	 * This is the failure §8.3 describes, made explicit: setcookie() after
	 * the headers have gone out cannot succeed, and the visitor would keep
	 * being geolocated on every page view. Skipping it keeps a PHP warning
	 * out of the response body; the early prime_currency_cookie() call is
	 * what makes sure the write normally happens while it still can.
	 *
	 * @return void
	 */
	public function test_cookie_is_not_written_once_headers_are_sent(): void {
		$GLOBALS['__mhmcs_test_headers_sent'] = true;

		$this->service->set_currency( 'USD' );

		$this->assertSame( array(), $this->cookie_writes() );
		$this->assertArrayNotHasKey(
			DetectionService::COOKIE_NAME,
			$_COOKIE,
			'A write that could not happen must not be reflected in $_COOKIE either.'
		);
	}

	/**
	 * prime_currency_cookie() is a no-op when the visitor already carries a
	 * cookie: there is nothing to detect and nothing to persist.
	 *
	 * @return void
	 */
	public function test_prime_currency_cookie_does_nothing_when_a_cookie_exists(): void {
		$this->enable_geolocation_to_germany();

		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$this->service->prime_currency_cookie();

		$this->assertSame( 0, $this->geolocate_calls() );
		$this->assertSame( array(), $this->cookie_writes() );
	}

	/**
	 * sanitize_currency_code() is shareable: public and static, so the
	 * convert endpoint can validate its `currency` parameter with exactly
	 * the same rule the detection chain applies, instead of a second
	 * hand-rolled regex that could drift.
	 *
	 * @return void
	 */
	public function test_sanitize_currency_code_is_publicly_shareable(): void {
		$this->assertSame( 'EUR', DetectionService::sanitize_currency_code( '  eur ' ) );
		$this->assertNull( DetectionService::sanitize_currency_code( 'EURO' ) );
		$this->assertNull( DetectionService::sanitize_currency_code( 'E1R' ) );
		$this->assertNull( DetectionService::sanitize_currency_code( 123 ) );
		$this->assertNull( DetectionService::sanitize_currency_code( null ) );
	}

	/**
	 * An override the store cannot honour resolves to the base currency
	 * rather than falling through to the ordinary detection chain.
	 *
	 * Falling through would be the dangerous reading: the caller asked for a
	 * fixed currency, so silently answering with the visitor's cookie (or a
	 * geolocation lookup the override is supposed to suppress) would make the
	 * endpoint's output depend on state it explicitly overrode.
	 *
	 * @return void
	 */
	public function test_invalid_request_override_falls_back_to_base(): void {
		$this->enable_geolocation_to_germany();

		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$this->service->set_request_override( 'XXX' );

		$this->assertSame( 'TRY', $this->service->get_current_currency() );
		$this->assertSame( 0, $this->geolocate_calls() );
		$this->assertSame( array(), $this->cookie_writes() );
	}

	/**
	 * A currency read taken before the main query must not freeze the
	 * answer: the URL parameter is only readable once query vars are parsed.
	 *
	 * This is the DetectionService counterpart of ConversionContext's rule
	 * that a pre-`wp` answer is never memoised (spec §3.3). Regression guard
	 * for the memo added by this task.
	 *
	 * @return void
	 */
	public function test_early_read_does_not_freeze_a_later_url_param(): void {
		$this->service->set_url_param_enabled( true );

		$this->assertSame( 'TRY', $this->service->get_current_currency() );

		$GLOBALS['__mhmcs_test_query_vars'][ DetectionService::URL_PARAM ] = 'EUR';

		$this->assertSame(
			'EUR',
			$this->service->get_current_currency(),
			'An early read must not memoise the answer past the point where the URL parameter becomes readable.'
		);
	}
}
