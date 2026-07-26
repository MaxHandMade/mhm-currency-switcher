<?php
/**
 * Unit tests for DetectionService.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use MhmCurrencySwitcher\Core\ConversionContext;
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
	 * The request's conversion-context resolver, shared with the service.
	 *
	 * The real one rather than a double: it is the class that decides whether
	 * this render is the cacheable one, and the tests below turn on that
	 * decision being reached through the actual decision table.
	 *
	 * @var ConversionContext
	 */
	private ConversionContext $context;

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

		$this->context = new ConversionContext();
		$this->service = new DetectionService( $this->store, $this->context );

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
	 * The context-shaped globals are reset here too, and not as housekeeping.
	 * prime_currency_cookie() now asks ConversionContext whether this render
	 * is the cacheable one, so a `wp` counter or a `cache_compat` setting left
	 * behind by a neighbouring test file would silently change whether a
	 * cookie is written. The baseline they are reset TO is the pre-`wp`
	 * front-end request, which the decision table answers "convert" (branch 7)
	 * — so every test in this file that does not say otherwise is a
	 * non-cacheable render and its cookie writes go ahead.
	 *
	 * @return void
	 */
	private function reset_request_globals(): void {
		unset( $_COOKIE[ DetectionService::COOKIE_NAME ] );
		unset( $GLOBALS['__mhmcs_test_query_vars'][ DetectionService::URL_PARAM ] );
		unset( $GLOBALS['__mhmcs_test_geo_country'] );
		unset( $GLOBALS['__mhmcs_test_headers_sent'] );
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );

		unset(
			$GLOBALS['__mhmcs_test_is_admin'],
			$GLOBALS['__mhmcs_test_doing_ajax'],
			$GLOBALS['__mhmcs_test_doing_cron'],
			$GLOBALS['__mhmcs_test_logged_in'],
			$GLOBALS['__mhmcs_test_is_cart'],
			$GLOBALS['__mhmcs_test_is_checkout'],
			$GLOBALS['__mhmcs_test_is_account_page']
		);

		unset( $_GET['wc-ajax'], $_GET['rest_route'], $_SERVER['REQUEST_URI'] );

		$GLOBALS['__mhmcs_test_did_actions'] = array();
		$GLOBALS['__mhmcs_test_filters']     = array();

		unset( $GLOBALS['__mhmcs_test_options']['mhmcs_settings'] );

		$GLOBALS['__mhmcs_test_geolocate_calls'] = 0;
		$GLOBALS['__mhmcs_test_setcookie']       = array();
	}

	/**
	 * Put the request into the render this whole task is about: cache
	 * compatibility on, past `wp`, anonymous, no money context — decision 8,
	 * the response a page cache is meant to store.
	 *
	 * @return void
	 */
	private function enter_cacheable_display_render(): void {
		$GLOBALS['__mhmcs_test_options']['mhmcs_settings'] = array( 'cache_compat' => true );
		$GLOBALS['__mhmcs_test_did_actions']['wp']         = 1;
	}

	/**
	 * Put the request past `wp` with cache compatibility switched OFF —
	 * the v1.0.0 server-side path, where the cookie is how persistence works.
	 *
	 * @return void
	 */
	private function enter_cache_compat_off_render(): void {
		$GLOBALS['__mhmcs_test_options']['mhmcs_settings'] = array( 'cache_compat' => false );
		$GLOBALS['__mhmcs_test_did_actions']['wp']         = 1;
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

		$service = new DetectionService( $store, new ConversionContext() );

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

	// ─── clear_request_override() ────────────────────────────────────

	/**
	 * Clearing the override hands the answer back to the detection chain.
	 *
	 * The convert endpoint sets an override on a service instance that, in
	 * production, is shared by every price surface for the whole request. If
	 * the endpoint is dispatched through rest_do_request() in the middle of a
	 * page render, an override left behind would pin the REST caller's
	 * currency onto the rest of that page.
	 *
	 * @return void
	 */
	public function test_clear_request_override_restores_the_detection_chain(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$this->service->set_request_override( 'EUR' );

		$this->assertSame( 'EUR', $this->service->get_current_currency(), 'Guard: the override must actually be in force.' );

		$this->service->clear_request_override();

		$this->assertSame(
			'USD',
			$this->service->get_current_currency(),
			'After clearing, the visitor\'s own cookie must decide again.'
		);
	}

	/**
	 * Clearing an override that was never set is harmless.
	 *
	 * @return void
	 */
	public function test_clear_request_override_is_safe_without_an_override(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$this->service->clear_request_override();

		$this->assertSame( 'USD', $this->service->get_current_currency() );
	}

	// ─── detect_currency(): "detected" vs "fell back to base" ────────

	/**
	 * detect_currency() answers null when NOTHING could be detected, where
	 * get_current_currency() answers the base currency.
	 *
	 * The convert endpoint's `detected` flag rests entirely on this
	 * distinction. Collapsing the two — as get_current_currency() must, since
	 * every price surface needs a usable code — would make a failed
	 * geolocation indistinguishable from a visitor whose country genuinely
	 * maps to the base currency. The client writes its cookie on `detected`,
	 * so getting this wrong pins a guessed currency and the chain never
	 * retries (spec §5.4).
	 *
	 * @return void
	 */
	public function test_detect_currency_is_null_when_nothing_is_detectable(): void {
		$this->assertNull(
			$this->service->detect_currency(),
			'No cookie, no URL parameter, no geolocation: nothing was detected.'
		);
		$this->assertSame(
			'TRY',
			$this->service->get_current_currency(),
			'Guard: the same request still resolves to the base currency for the price surfaces.'
		);
	}

	/**
	 * A successful geolocation is a detection, even when it lands on a
	 * currency that happens to be the base one.
	 *
	 * @return void
	 */
	public function test_detect_currency_reports_a_geolocated_currency(): void {
		$this->enable_geolocation_to_germany();

		$this->assertSame( 'EUR', $this->service->detect_currency() );
		$this->assertSame( 1, $this->geolocate_calls(), 'Guard: geolocation must genuinely have run.' );
	}

	/**
	 * The chain order inside detect_currency() is the same one
	 * get_current_currency() uses: cookie first, then URL parameter.
	 *
	 * Spec §5.2: a visitor with an EUR cookie following a `?currency=USD`
	 * link must be answered EUR by BOTH, or the catalogue shows one currency
	 * while the cart charges another.
	 *
	 * @return void
	 */
	public function test_detect_currency_keeps_the_cookie_first_chain_order(): void {
		$this->service->set_url_param_enabled( true );

		$_COOKIE[ DetectionService::COOKIE_NAME ]                          = 'EUR';
		$GLOBALS['__mhmcs_test_query_vars'][ DetectionService::URL_PARAM ] = 'USD';

		$this->assertSame( 'EUR', $this->service->detect_currency() );
		$this->assertSame( 'EUR', $this->service->get_current_currency(), 'Both entry points must agree, or the visitor sees one currency and pays another.' );
	}

	// ─── Cookie persistence switch (spec §4, round 4 / L-1) ──────────

	/**
	 * 🔴 On the endpoint's `currency: null` path a SUCCESSFUL geolocation
	 * must write no cookie at all.
	 *
	 * Proven by COUNTING, not by reading the code: the namespaced setcookie()
	 * stub records every write, and the WC_Geolocation stub counts every
	 * lookup. Both numbers are asserted, because "0 cookie writes" is worth
	 * nothing on its own — it is also what a geolocation that never ran would
	 * produce, and that is precisely the vacuous green this file's control
	 * test below exists to rule out.
	 *
	 * Why it matters (spec §4, §5.3): in cache mode the cookie belongs to the
	 * CLIENT. A server-side write on the endpoint request means the same
	 * cookie is written from two places, and a currency the client decided not
	 * to persist would be pinned server-side anyway.
	 *
	 * @return void
	 */
	public function test_geolocation_writes_no_cookie_while_persistence_is_off(): void {
		$this->enable_geolocation_to_germany();

		$this->service->set_cookie_persistence( false );

		$this->assertSame( 'EUR', $this->service->detect_currency(), 'Geolocation must still RESOLVE — only the cookie write is suppressed.' );
		$this->assertSame( 1, $this->geolocate_calls(), 'Guard: the lookup genuinely ran, so "no cookie" is not vacuously true.' );

		// Flushing the queue must find nothing queued either.
		$this->service->prime_currency_cookie();

		$this->assertSame(
			array(),
			$this->cookie_writes(),
			'The convert endpoint must leave no Set-Cookie behind: the client owns this cookie (spec §5.3).'
		);
	}

	/**
	 * Control for the test above: the SAME setup, with the switch left alone,
	 * writes exactly one cookie.
	 *
	 * Without this control, "0 writes" could mean the geolocation cookie path
	 * is broken outright rather than deliberately suppressed.
	 *
	 * @return void
	 */
	public function test_the_same_geolocation_writes_exactly_one_cookie_with_persistence_on(): void {
		$this->enable_geolocation_to_germany();

		$this->assertSame( 'EUR', $this->service->detect_currency() );

		$this->service->prime_currency_cookie();

		$writes = $this->cookie_writes();

		$this->assertCount( 1, $writes, 'Exactly one cookie write is the normal, non-endpoint behaviour.' );
		$this->assertSame( DetectionService::COOKIE_NAME, $writes[0]['name'] );
		$this->assertSame( 'EUR', $writes[0]['value'] );
	}

	/**
	 * The switch closes the write itself, not just the queue.
	 *
	 * set_currency() is the single place in the plugin that calls setcookie(),
	 * so guarding it is what makes the suppression total rather than a
	 * property of the one code path that happens to reach it today.
	 *
	 * @return void
	 */
	public function test_cookie_persistence_off_blocks_a_direct_write(): void {
		$this->service->set_cookie_persistence( false );

		$this->service->set_currency( 'USD' );

		$this->assertSame( array(), $this->cookie_writes(), 'No Set-Cookie may be emitted while persistence is off.' );
		$this->assertArrayNotHasKey(
			DetectionService::COOKIE_NAME,
			$_COOKIE,
			'A write that was suppressed must not be reflected in $_COOKIE either, or the rest of the request behaves as though it had happened.'
		);
	}

	/**
	 * Persistence can be switched back on, so one endpoint call cannot
	 * silence the cookie for the remainder of a shared-instance request.
	 *
	 * @return void
	 */
	public function test_cookie_persistence_can_be_restored(): void {
		$this->service->set_cookie_persistence( false );
		$this->service->set_cookie_persistence( true );

		$this->service->set_currency( 'USD' );

		$this->assertCount( 1, $this->cookie_writes() );
	}

	// ─── Priming on a cacheable render (spec §5.1, §5.3) ─────────────

	/**
	 * 🔴 A render a page cache is meant to store emits NO Set-Cookie.
	 *
	 * Both failure directions are live without this. A cache that STORES the
	 * response stores its Set-Cookie with it, so the first visitor's
	 * geolocated currency is handed to everyone after them — and
	 * price-converter.js then faithfully converts a US visitor's page to the
	 * German visitor's EUR. A cache that REFUSES to store a response carrying
	 * Set-Cookie (WP Rocket, LiteSpeed) never caches the page at all, so on
	 * any site with auto-detect on the whole feature does nothing.
	 *
	 * The cookie belongs to the client here (spec §5.3): §5.1's flow has the
	 * browser send `currency: null`, the convert endpoint geolocate, and the
	 * client write the cookie itself when the answer comes back
	 * `detected: true`.
	 *
	 * @return void
	 */
	public function test_cacheable_display_render_writes_no_cookie(): void {
		$this->enable_geolocation_to_germany();
		$this->enter_cacheable_display_render();

		$this->assertFalse( $this->context->should_convert(), 'Guard: this must be the display branch.' );

		$this->service->prime_currency_cookie();

		$this->assertSame(
			array(),
			$this->cookie_writes(),
			'A cacheable render must emit no Set-Cookie; the client owns the cookie in this mode (spec §5.3).'
		);
	}

	/**
	 * Anti-vacuity control for the test above: the very same setup DOES
	 * geolocate to EUR, so "no cookie" cannot be an unwired stub or a
	 * currency the chain failed to resolve.
	 *
	 * @return void
	 */
	public function test_cacheable_render_still_resolves_the_geolocated_currency(): void {
		$this->enable_geolocation_to_germany();
		$this->enter_cacheable_display_render();

		$this->service->prime_currency_cookie();

		$this->assertSame(
			'EUR',
			$this->service->get_current_currency(),
			'Guard: geolocation is wired and resolves EUR in this exact setup.'
		);
		$this->assertSame( 1, $this->geolocate_calls() );
		$this->assertSame(
			array(),
			$this->cookie_writes(),
			'Resolving the currency on a cacheable render must still write nothing.'
		);
	}

	/**
	 * Cache compatibility OFF is untouched: exactly one cookie write.
	 *
	 * That mode emits no marker and ships no client converter, so the
	 * server-side cookie IS the persistence. Suppressing it there would send
	 * the visitor back through geolocation on every single page view — the
	 * §3.3 regression the priming exists to close.
	 *
	 * @return void
	 */
	public function test_cache_compat_off_still_primes_the_cookie(): void {
		$this->enable_geolocation_to_germany();
		$this->enter_cache_compat_off_render();

		$this->service->prime_currency_cookie();

		$writes = $this->cookie_writes();

		$this->assertCount( 1, $writes, 'Cache compatibility off must keep writing exactly one cookie.' );
		$this->assertSame( DetectionService::COOKIE_NAME, $writes[0]['name'] );
		$this->assertSame( 'EUR', $writes[0]['value'] );
	}

	/**
	 * Cache mode ON but a CONVERTED context — here a logged-in visitor —
	 * still writes exactly one cookie.
	 *
	 * The correct answer is "write", for two independent reasons. The
	 * response is not one anybody caches: every page cache in this class
	 * bypasses logged-in visitors, as it does cart and checkout. And the
	 * server renders those pages converted with NO marker (decision 6), so
	 * price-converter.js never runs and the client will never write the
	 * cookie on its behalf — the server-side write is the only persistence
	 * this request has. Suppressing it would geolocate the visitor again on
	 * every page they open while fixing nothing.
	 *
	 * @return void
	 */
	public function test_converted_render_in_cache_mode_still_primes_the_cookie(): void {
		$this->enable_geolocation_to_germany();
		$this->enter_cacheable_display_render();

		$GLOBALS['__mhmcs_test_logged_in'] = true;

		$this->assertTrue( $this->context->should_convert(), 'Guard: a logged-in render converts (decision 6).' );

		$this->service->prime_currency_cookie();

		$writes = $this->cookie_writes();

		$this->assertCount( 1, $writes, 'A converted render has no client converter, so the server must persist the cookie.' );
		$this->assertSame( 'EUR', $writes[0]['value'] );
	}

	/**
	 * The §8.3 timing guard survives the suppression: once the headers are on
	 * the wire nothing is written, even on the path that is otherwise allowed
	 * to write.
	 *
	 * @return void
	 */
	public function test_priming_writes_nothing_once_headers_are_sent(): void {
		$this->enable_geolocation_to_germany();
		$this->enter_cache_compat_off_render();

		$GLOBALS['__mhmcs_test_headers_sent'] = true;

		$this->service->prime_currency_cookie();

		$this->assertSame( array(), $this->cookie_writes() );
		$this->assertArrayNotHasKey(
			DetectionService::COOKIE_NAME,
			$_COOKIE,
			'A write that could not happen must not be reflected in $_COOKIE either.'
		);
	}

	/**
	 * 🔴 Priming asks the context, and asking does not latch.
	 *
	 * prime_currency_cookie() runs on template_redirect priority 0 — after
	 * `wp`, but before a single price has been rendered. ConversionContext's
	 * memo is a ONE-WAY latch, so a "convert" answer read there and left
	 * armed would force every price on the page to convert, and the page is
	 * then handed to a cache in one visitor's currency. The answer is read
	 * and the latch put back exactly as found.
	 *
	 * Two assertions, and both are needed. The filter counter proves the
	 * context was actually consulted — without it the latch assertion passes
	 * trivially on any build that never asks. The logged-out re-read proves
	 * the "convert" answer it got was not retained.
	 *
	 * @return void
	 */
	public function test_priming_asks_the_context_without_latching_a_convert_answer(): void {
		$this->enable_geolocation_to_germany();
		$this->enter_cacheable_display_render();

		$GLOBALS['__mhmcs_test_logged_in'] = true;

		$asked = 0;

		$GLOBALS['__mhmcs_test_filters']['mhmcs_should_convert'] = static function ( $decision ) use ( &$asked ) {
			++$asked;

			return $decision;
		};

		$this->service->prime_currency_cookie();

		$this->assertGreaterThan(
			0,
			$asked,
			'prime_currency_cookie() must actually consult the context, or the suppression above is untested.'
		);

		unset( $GLOBALS['__mhmcs_test_logged_in'] );

		$this->assertFalse(
			$this->context->should_convert(),
			'Priming must leave no "convert" latch behind, or the whole page renders converted from template_redirect onwards.'
		);
	}

	/**
	 * The mirror image: a request that had already latched — a cart page —
	 * comes out of priming still latched. Restoring, not clearing.
	 *
	 * @return void
	 */
	public function test_priming_preserves_an_existing_convert_latch(): void {
		$this->enable_geolocation_to_germany();
		$this->enter_cacheable_display_render();

		$_GET['wc-ajax'] = 'checkout';

		$this->assertTrue( $this->context->should_convert(), 'Guard: the money context latches.' );

		unset( $_GET['wc-ajax'] );

		$this->service->prime_currency_cookie();

		$this->assertTrue(
			$this->context->should_convert(),
			'Priming must not clear a latch the request had already armed for its own reasons.'
		);
	}
}
