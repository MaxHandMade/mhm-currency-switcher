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
	const COOKIE_NAME = 'mhmcs_currency';

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
	 * The request's conversion-context resolver.
	 *
	 * Consulted by prime_currency_cookie() only, to tell a render a page
	 * cache is meant to store from one that is converted server-side.
	 *
	 * @var ConversionContext
	 */
	private ConversionContext $context;

	/**
	 * Whether URL parameter detection is enabled.
	 *
	 * @var bool
	 */
	private bool $url_param_enabled = false;

	/**
	 * Rate reader, lazily built. See converter().
	 *
	 * @var Converter|null
	 */
	private ?Converter $converter = null;

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
	 * Currency forced for this request only, bypassing the detection chain.
	 *
	 * @var string|null
	 */
	private ?string $request_override = null;

	/**
	 * Currency waiting to be persisted to the visitor's cookie.
	 *
	 * @var string|null
	 */
	private ?string $pending_cookie = null;

	/**
	 * Whether geolocation has already been attempted on this request.
	 *
	 * @var bool
	 */
	private bool $geolocation_attempted = false;

	/**
	 * Result of this request's single geolocation attempt.
	 *
	 * @var string|null
	 */
	private ?string $geolocation_result = null;

	/**
	 * Whether a detected currency may be persisted to the visitor's cookie.
	 *
	 * On by default: an ordinary page view SHOULD remember a geolocated
	 * currency, or geolocation runs again on every subsequent view.
	 *
	 * The convert endpoint switches it off (design spec §4, round 4 / L-1).
	 * In cache-compatibility mode the cookie belongs to the CLIENT (§5.3): the
	 * browser decides whether the detection was good enough to keep, and a
	 * server-side write on the endpoint request would mean the same cookie is
	 * written from two places. Worse, a currency the client deliberately did
	 * NOT persist — a failed detection it wants retried — would be pinned
	 * server-side anyway, and the chain would stop at step 1 forever.
	 *
	 * @var bool
	 */
	private bool $cookie_persistence = true;

	/**
	 * Constructor.
	 *
	 * The context is required rather than optional. It is the only thing that
	 * can tell prime_currency_cookie() whether the response it is about to
	 * attach a Set-Cookie to is one a page cache will store, and a service
	 * left without one would emit that header on every cacheable render —
	 * the exact bug this dependency exists to close. A required parameter
	 * makes forgetting it a fatal error instead of a silent regression.
	 *
	 * @param CurrencyStore     $store             Currency data store.
	 * @param ConversionContext $context           The request's context resolver.
	 * @param bool              $url_param_enabled Whether to detect currency from URL param.
	 */
	public function __construct( CurrencyStore $store, ConversionContext $context, bool $url_param_enabled = false ) {
		$this->store             = $store;
		$this->context           = $context;
		$this->url_param_enabled = $url_param_enabled;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * Registers `currency` as a public query var so the URL parameter
	 * can be read via get_query_var() instead of the $_GET superglobal.
	 * Must run before the main query is parsed (i.e. on `init`).
	 *
	 * Also schedules the geolocation cookie write at the very start of
	 * `template_redirect`: the main query has run by then (so the URL
	 * parameter is readable) but nothing has been rendered yet, so the
	 * Set-Cookie header can still be sent. See prime_currency_cookie().
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'prime_currency_cookie' ), 0 );
	}

	/**
	 * Append the currency query var to the public query vars list.
	 *
	 * @param string[] $vars Registered public query vars.
	 * @return string[] Query vars including the currency parameter.
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::URL_PARAM;

		return $vars;
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
	 * Force a currency for the remainder of this request only.
	 *
	 * Used by the convert endpoint, which resolves the visitor's currency
	 * itself and then asks the server to render in it. Two properties matter
	 * and are locked by tests:
	 *
	 * - It writes NO cookie. The cookie belongs to the client in cache mode
	 *   (design spec §5.3); a server-side write on the endpoint request would
	 *   mean the same cookie is written from two places, and a failed
	 *   client-side detection could be pinned server-side so the detection
	 *   chain never retries.
	 * - Geolocation does not run while it is active. The lookup itself is the
	 *   side effect being suppressed (§4), not just its result.
	 *
	 * A code the store cannot honour resolves to the base currency rather
	 * than falling through to the ordinary chain: the caller asked for a
	 * fixed currency, so answering with the visitor's cookie instead would
	 * make the output depend on the very state that was overridden.
	 *
	 * @param string $code ISO 4217 currency code.
	 * @return void
	 */
	public function set_request_override( string $code ): void {
		$sanitised = self::sanitize_currency_code( $code );
		$validated = null === $sanitised ? null : $this->validate_code( $sanitised );

		$this->request_override = null === $validated
			? $this->store->get_base_currency()
			: $validated;
	}

	/**
	 * Drop the request override, handing the answer back to the detection
	 * chain.
	 *
	 * The counterpart of set_request_override(), and not a convenience. In
	 * production one DetectionService instance serves the whole request and is
	 * shared by every price surface. The convert endpoint can be dispatched
	 * through rest_do_request() from inside a page render, so an override left
	 * behind would pin the REST caller's currency onto every price the rest of
	 * that page prints — and onto a page that is about to be cached.
	 *
	 * @return void
	 */
	public function clear_request_override(): void {
		$this->request_override = null;
	}

	/**
	 * Allow or forbid persisting a detected currency to the visitor's cookie
	 * for the remainder of this request.
	 *
	 * See the $cookie_persistence property for why the convert endpoint turns
	 * this off. Callers that switch it off are responsible for switching it
	 * back on, since the instance outlives any single endpoint call.
	 *
	 * @param bool $enabled Whether cookie writes are permitted.
	 * @return void
	 */
	public function set_cookie_persistence( bool $enabled ): void {
		$this->cookie_persistence = $enabled;
	}

	/**
	 * Whether persisting a detected currency to the cookie is permitted.
	 *
	 * Exists so a caller that switches persistence off can put back what it
	 * found instead of assuming the default — the instance is shared for the
	 * whole request, so an assumed `true` would switch it on underneath
	 * whoever had switched it off.
	 *
	 * @return bool
	 */
	public function is_cookie_persistence_enabled(): bool {
		return $this->cookie_persistence;
	}

	/**
	 * Resolve the currency early and persist a geolocated one to the cookie.
	 *
	 * Runs on `template_redirect` at priority 0. Before this existed the
	 * cookie was written from inside detect_from_geolocation(), i.e. during
	 * price filtering, half-way through rendering — by then the response
	 * headers may already have been flushed and the write failed silently,
	 * so geolocation ran again on the visitor's every subsequent page view
	 * (spec §8.3).
	 *
	 * Only a geolocated currency is persisted. A cookie the visitor already
	 * has needs no rewrite, and a request override deliberately leaves no
	 * trace.
	 *
	 * 🔴 And nothing at all is persisted on a render a page cache is meant to
	 * store. A Set-Cookie on such a response fails in both directions: a cache
	 * that stores it hands the first visitor's geolocated currency to everyone
	 * after them — a US visitor receives a German visitor's EUR cookie and
	 * price-converter.js dutifully converts their page to EUR — while a cache
	 * that refuses to store any response carrying Set-Cookie (WP Rocket,
	 * LiteSpeed) never caches the page at all, so on every site with
	 * auto-detect on the cache feature does nothing.
	 *
	 * There is nothing to replace, because §5.1 already covers the case end to
	 * end: the client sends `currency: null`, the convert endpoint geolocates,
	 * and price-converter.js writes the cookie itself on `detected: true`. The
	 * cookie belongs to the client in this mode (§5.3), so the server-side
	 * write here is redundant as well as harmful.
	 *
	 * The suppression is deliberately narrow. With cache compatibility OFF, or
	 * on a converted render (money context, logged-in visitor), the server
	 * emits no marker and ships no client converter — this write is the only
	 * persistence those requests have, and removing it would send the visitor
	 * back through geolocation on every page view (§3.3). Those responses are
	 * not cached by anyone either.
	 *
	 * @return void
	 */
	public function prime_currency_cookie(): void {
		if ( null !== $this->request_override ) {
			return;
		}

		if ( ! $this->geolocation_enabled || null === $this->geolocation ) {
			return;
		}

		/*
		 * Asked here, one hook before anything renders, and asked in the one
		 * way that arms nothing: is_cacheable_render() puts the context's
		 * one-way latch back exactly as it found it. A "convert" answer
		 * latched at template_redirect priority 0 would force every price on
		 * the page to convert and then hand that page to the cache in a single
		 * visitor's currency — §3.3's failure, reached from a new direction.
		 *
		 * The lookup is skipped along with the write: on a cacheable render
		 * nothing server-side needs the geolocated currency, so the MaxMind
		 * hit is pure cost.
		 */
		if ( $this->context->is_cacheable_render() ) {
			return;
		}

		// Resolving may run geolocation, which queues the pending cookie.
		$this->get_current_currency();

		if ( null === $this->pending_cookie ) {
			return;
		}

		$code                 = $this->pending_cookie;
		$this->pending_cookie = null;

		$this->set_currency( $code );
	}

	/**
	 * Get the current visitor's currency code.
	 *
	 * Detection order:
	 *   0. Request override (if set).
	 *   1. Cookie (if set and valid).
	 *   2. URL parameter (if enabled and valid).
	 *   3. Geolocation (if enabled and valid).
	 *   4. Base currency (default).
	 *
	 * Only step 3 is memoised, and deliberately only step 3. Steps 1 and 2
	 * read request state that can legitimately change mid-request (the
	 * switcher writing the cookie, the query vars becoming readable once the
	 * main query has run), and they are two array lookups. Step 3 is the
	 * expensive one and its inputs — the visitor's IP and headers — cannot
	 * change within a request. Memoising the whole answer instead would
	 * reintroduce exactly the class of bug §3.3 documents for
	 * ConversionContext: an answer computed too early, frozen, and then used
	 * after the facts behind it changed.
	 *
	 * @return string ISO 4217 currency code.
	 */
	public function get_current_currency(): string {
		$detected = $this->detect_currency();

		return null === $detected
			? $this->store->get_base_currency()
			: $detected;
	}

	/**
	 * Run the detection chain and report what it found, or null when it found
	 * nothing.
	 *
	 * The same chain as get_current_currency(), in the same order — this is
	 * the one implementation and that method is the base-currency fallback
	 * wrapped around it. Spec §5.2 turns on the order being identical
	 * everywhere: a visitor with an EUR cookie who follows a `?currency=USD`
	 * link must be answered EUR by every caller, or the catalogue shows one
	 * currency while the cart charges another. A second copy of the chain
	 * written for the endpoint would be free to drift into exactly that.
	 *
	 * The difference from get_current_currency() is the null, and only the
	 * convert endpoint needs it: `detected: false` in the response tells the
	 * client "nothing could be detected for you, do not persist this" (§5.4).
	 * A visitor whose country legitimately maps to the base currency IS a
	 * detection and must not be reported as a failure, so "equals the base
	 * currency" cannot stand in for it.
	 *
	 * @return string|null Detected ISO 4217 code, or null when the chain came
	 *                     up empty.
	 */
	public function detect_currency(): ?string {
		if ( null !== $this->request_override ) {
			return $this->request_override;
		}

		$from_cookie = $this->detect_from_cookie();

		if ( null !== $from_cookie ) {
			return $from_cookie;
		}

		$from_url = $this->detect_from_url_param();

		if ( null !== $from_url ) {
			return $from_url;
		}

		return $this->detect_from_geolocation();
	}

	/**
	 * Set the currency cookie.
	 *
	 * Sets `mhmcs_currency={code}` with path `/`, max-age 30 days,
	 * and SameSite=Lax.
	 *
	 * Does nothing once the response headers are on the wire — setcookie()
	 * would only emit a PHP warning there — and updates $_COOKIE on success.
	 * PHP populates $_COOKIE from the REQUEST, so without that line the rest
	 * of this request keeps missing at step 1 of the detection chain and
	 * falls through to geolocation on every single price read (spec §3.3).
	 *
	 * @param string $code ISO 4217 currency code.
	 * @return void
	 */
	public function set_currency( string $code ): void {
		/*
		 * The single choke point. This method holds the plugin's only
		 * setcookie() call, so guarding it here is what makes the convert
		 * endpoint's suppression total (spec §4) rather than a property of
		 * whichever code path happens to reach the write today.
		 */
		if ( ! $this->cookie_persistence ) {
			return;
		}

		if ( headers_sent() ) {
			return;
		}

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

		$_COOKIE[ self::COOKIE_NAME ] = $code;
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
	 * Runs at most once per request. The lookup can reach the MaxMind
	 * database, and the currency is read once per filtered price, so before
	 * the memo a single product archive could geolocate dozens of times —
	 * setcookie() does not update $_COOKIE, so the write that was supposed
	 * to short-circuit step 1 never did (spec §3.3).
	 *
	 * A successful detection is QUEUED for the cookie rather than written
	 * here; see prime_currency_cookie() for why (spec §8.3).
	 *
	 * @return string|null Currency code, or null when unavailable/disabled.
	 */
	private function detect_from_geolocation(): ?string {
		if ( $this->geolocation_attempted ) {
			return $this->geolocation_result;
		}

		if ( ! $this->geolocation_enabled || null === $this->geolocation ) {
			return null;
		}

		$this->geolocation_attempted = true;

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

		$this->geolocation_result = $currency;

		/*
		 * Queue the cookie so geolocation doesn't re-run on the next page
		 * load. It is written from template_redirect, while headers are open.
		 *
		 * Nothing is queued while cookie persistence is off: the write is
		 * already refused at set_currency(), but leaving a value sitting in
		 * the queue would mean any later flush — a page render that dispatched
		 * this endpoint through rest_do_request(), say — emitted it after the
		 * fact.
		 */
		if ( $this->cookie_persistence ) {
			$this->pending_cookie = $currency;
		}

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
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- value validated by sanitize_currency_code() to strict ^[A-Z]{3}$ ISO-4217 format; any non-conforming input returns null.
		$raw = self::sanitize_currency_code( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );

		if ( null === $raw ) {
			return null;
		}

		return $this->validate_code( $raw );
	}

	/**
	 * Detect currency from the URL query parameter.
	 *
	 * Reads the `currency` public query var via get_query_var()
	 * (registered in register()), sanitises the value, and validates
	 * it against the enabled currencies or base currency. Only active
	 * when URL parameter detection is enabled. Using the query var
	 * instead of $_GET avoids the NonceVerification/ValidatedSanitizedInput
	 * concerns of reading the superglobal directly.
	 *
	 * Scope: this reads the query var of the page request, so it applies to
	 * front-end page renders (after the main query is parsed). It is not the
	 * cross-context persistence mechanism — that is the cookie, which is read
	 * first in the detection chain and works in every context (REST, cron,
	 * AJAX). The URL param only overrides on the initial link-driven view;
	 * the switcher persists the choice to the cookie for subsequent requests.
	 *
	 * @return string|null Currency code, or null when param is absent, invalid, or feature disabled.
	 */
	public function detect_from_url_param(): ?string {
		if ( ! $this->url_param_enabled ) {
			return null;
		}

		$value = get_query_var( self::URL_PARAM );

		// get_query_var() returns '' when the var is absent.
		if ( '' === $value ) {
			return null;
		}

		$raw = self::sanitize_currency_code( $value );

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
	 * Public and static so that other entry points which accept a currency
	 * code from the outside — the convert endpoint above all — validate it
	 * with this exact rule instead of a second hand-rolled regex that can
	 * drift away from the one the detection chain enforces.
	 *
	 * @param mixed $input Raw input value.
	 * @return string|null Sanitised 3-letter code, or null when invalid.
	 */
	public static function sanitize_currency_code( $input ): ?string {
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

		if ( null === $currency || empty( $currency['enabled'] ) ) {
			return null;
		}

		/*
		 * Enabled is not the same as usable, and this half was missing. A
		 * currency added in the panel starts at rate 0 and stays there until
		 * the first sync, and a fee can cancel a rate out afterwards. Accepting
		 * it here handed a currency nothing can be priced in to every money
		 * surface downstream, and they did not agree on what to do with it:
		 * PriceFilter applied a per-product fixed price while FormatFilter fell
		 * back to the base symbol, so the visitor read a foreign amount wearing
		 * the base currency's identity — and the order was saved with that
		 * mismatch recorded as fact.
		 *
		 * Refusing it here rather than in each consumer is what makes the
		 * surfaces agree: there is one answer to "which currency is in force",
		 * and every path into detection already funnels through this method
		 * (request override, cookie, URL parameter, geolocation).
		 *
		 * Converter is built here rather than injected for the reason Switcher
		 * already states: it is a pure reader over the same store, so a second
		 * instance answers identically, and threading a fourth constructor
		 * argument through Plugin and both Elementor widgets would change four
		 * call sites to gain nothing.
		 */
		if ( ! $this->converter()->has_usable_rate( $code ) ) {
			return null;
		}

		return $code;
	}

	/**
	 * The rate reader, built on first use and kept for the rest of the request.
	 *
	 * @return Converter Reader over this service's own store.
	 */
	private function converter(): Converter {
		if ( null === $this->converter ) {
			$this->converter = new Converter( $this->store );
		}

		return $this->converter;
	}
}
