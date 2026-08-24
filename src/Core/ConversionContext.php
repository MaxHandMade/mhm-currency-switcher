<?php
/**
 * Conversion context resolver.
 *
 * Answers the single question every price surface must agree on:
 * "should this request convert prices out of the base currency?".
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
 * ConversionContext — the one decision shared by every conversion surface.
 *
 * Price, format, coupon, shipping, cart-fee and variation-hash surfaces all
 * ask this class instead of deciding for themselves; if one of them decided
 * on its own, a single request could show base amounts next to converted
 * symbols, or display base while charging converted.
 *
 * Decision order (first match wins):
 *
 *   0. force_convert() active .............................. convert
 *   1. admin context ....................................... base
 *   2. REST request, Store API excluded .................... base
 *   3. cron / WP-CLI ....................................... base
 *   4. cache_compat disabled ............................... convert
 *   5. money context (cart / checkout / wc-ajax / Store API) convert
 *   6. logged-in user ...................................... convert
 *   7. before the `wp` action .............................. convert
 *   8. otherwise (catalogue display) ....................... base
 *
 * Branches 1-3 sit ABOVE the toggle on purpose: they are production bug
 * fixes, not part of the cache feature, so "cache compatibility off" does
 * not resurrect them.
 *
 * Error modes are not symmetric. A catalogue page that converts by mistake
 * only breaks page caching; a cart or checkout that stays in the base
 * currency by mistake charges the wrong amount. Every ambiguous case is
 * therefore resolved towards "convert".
 *
 * @since 1.1.0
 */
final class ConversionContext {

	/**
	 * Route prefix of the WooCommerce Store API.
	 *
	 * @var string
	 */
	const STORE_API_PREFIX = '/wc/store';

	/**
	 * Whether conversion is forced regardless of the request context.
	 *
	 * Used by our own convert endpoint, which asks for converted output on
	 * a request that would otherwise resolve to base.
	 *
	 * @var bool
	 */
	private bool $force = false;

	/**
	 * Whether a "convert" answer has latched for this request.
	 *
	 * @var bool
	 */
	private bool $latched = false;

	/**
	 * Force conversion on or off for the rest of the request.
	 *
	 * @param bool $on Whether conversion is forced.
	 * @return void
	 */
	public function force_convert( bool $on ): void {
		$this->force = $on;
	}

	/**
	 * Run a callback with conversion forced on, then put the request back
	 * exactly as it was found.
	 *
	 * The convert endpoint's entry point, and the reason it is a scope rather
	 * than a bare force_convert( true ) … force_convert( false ) pair: reading
	 * the decision while forced ALSO arms the latch, because decision 0
	 * answers "convert" and the endpoint can run after the `wp` action. A
	 * latch left behind would keep converting for the rest of the request —
	 * and the request that can still have a "rest of" is the dangerous one: a
	 * page render that dispatches this endpoint through rest_do_request()
	 * would print every remaining price converted AND marker-less, then hand
	 * that page to the cache in one visitor's currency.
	 *
	 * Both flags are restored rather than cleared. A request that had already
	 * latched for its own reasons — a cart page — must come out still latched;
	 * unlatching it would drop the rest of the page back to base prices while
	 * its checkout charges the converted amount, which is the failure §3.3
	 * exists to prevent.
	 *
	 * @param callable $callback Work to run while conversion is forced.
	 * @return mixed The callback's return value.
	 */
	public function with_forced_conversion( callable $callback ) {
		$was_forced  = $this->force;
		$was_latched = $this->latched;

		$this->force_convert( true );

		try {
			return $callback();
		} finally {
			$this->force_convert( $was_forced );
			$this->latched = $was_latched;
		}
	}

	/**
	 * Decide whether prices should be converted on this request.
	 *
	 * Memoization is a one-way latch (see the class docblock for why):
	 *
	 * - A pre-`wp` answer is never stored. The wp_loaded cart-session
	 *   validation legitimately answers "convert" long before rendering
	 *   starts; storing that answer converted the whole page and cached it.
	 * - After `wp`, only a "convert" answer is stored. A "base" answer is
	 *   recomputed on every call, because cart and checkout shortcodes
	 *   define WOOCOMMERCE_CART / WOOCOMMERCE_CHECKOUT in the middle of
	 *   rendering; a latched "base" would show base prices on a page whose
	 *   AJAX checkout then charges the converted amount.
	 *
	 * Once latched, the `mhmcs_should_convert` filter is not re-applied: it
	 * already saw this request and returned true, and re-opening the answer
	 * would defeat the latch.
	 *
	 * @return bool True when prices should be converted.
	 */
	public function should_convert(): bool {
		if ( $this->latched ) {
			return true;
		}

		$decision = $this->decide();

		/**
		 * Filters the conversion decision for the current request.
		 *
		 * Escape hatch for the rare third-party context this table cannot
		 * see. Returning true converts, false keeps the base currency.
		 *
		 * @since 1.1.0
		 *
		 * @param bool   $decision Whether prices should be converted.
		 * @param string $reason   Branch that produced the decision: one of
		 *                         `force`, `admin`, `rest`, `cron_cli`,
		 *                         `cache_compat_off`, `money`, `logged_in`,
		 *                         `pre_wp`, `display`.
		 */
		$result = (bool) apply_filters( 'mhmcs_should_convert', $decision[0], $decision[1] );

		if ( $result && did_action( 'wp' ) ) {
			$this->latched = true;
		}

		return $result;
	}

	/**
	 * Whether this render is one we intend a page cache to store.
	 *
	 * True for exactly one shape of request: cache compatibility on AND the
	 * table answering "do not convert" — decision 8, the anonymous catalogue
	 * view the server leaves in the base currency and marks up for
	 * price-converter.js. Everything else is either converted server-side
	 * (money, logged-in, mode off) or never reaches a cached render at all.
	 *
	 * Callers use it to decide whether a response may carry visitor-specific
	 * state. It is not a second opinion on the conversion decision: it asks
	 * the same should_convert() every price surface asks, so the two cannot
	 * drift apart.
	 *
	 * 🔴 Asking this question arms NOTHING. should_convert() latches a
	 * "convert" answer one-way, and the caller that matters here asks on
	 * `template_redirect` priority 0 — before a single price has rendered. A
	 * latch armed there would force the entire page into conversion and then
	 * hand it to a cache in one visitor's currency, which is the failure §3.3
	 * exists to prevent. The latch is therefore saved and restored, the same
	 * discipline with_forced_conversion() applies, and for the same reason:
	 * restore rather than clear, so a request that had already latched for its
	 * own reasons — a cart page — comes out still latched.
	 *
	 * Only meaningful on a front-end render. Decisions 1-3 (admin, REST,
	 * cron/CLI) also answer "do not convert", and this method would call them
	 * cacheable; none of them produces a page a cache stores, and the one
	 * caller runs on `template_redirect`, which those contexts never reach.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when the response may be stored by a page cache.
	 */
	public function is_cacheable_render(): bool {
		if ( ! $this->is_cache_compat_enabled() ) {
			return false;
		}

		$was_latched = $this->latched;

		try {
			return ! $this->should_convert();
		} finally {
			$this->latched = $was_latched;
		}
	}

	/**
	 * Evaluate the decision table.
	 *
	 * @return array{0: bool, 1: string} Decision and the reason that produced it.
	 */
	private function decide(): array {
		// 0. Our own convert endpoint. Above the REST branch so that branch
		// cannot swallow it.
		if ( $this->force ) {
			return array( true, 'force' );
		}

		// 1. Admin screens and admin-owned AJAX never convert: the order
		// editor writes what it reads, so a converted read becomes a stored
		// line item price.
		if ( $this->is_admin_context() ) {
			return array( false, 'admin' );
		}

		// 2. REST reads (wc/v3) stay in the base currency. Must be above the
		// logged-in branch: wc/v3 is always authenticated, so behind it this
		// branch would never run at all.
		if ( $this->is_rest_request() && ! $this->is_store_api_request() ) {
			return array( false, 'rest' );
		}

		// 3. No visitor to convert for. Without this, WC_Geolocation
		// resolving the server IP to a country would convert cron reads.
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return array( false, 'cron_cli' );
		}

		// 4. Cache compatibility disabled: the v1.0.0 display behaviour.
		if ( ! $this->is_cache_compat_enabled() ) {
			return array( true, 'cache_compat_off' );
		}

		// 5. The amount the customer is about to be charged.
		if ( $this->is_money_context() ) {
			return array( true, 'money' );
		}

		// 6. Logged-in visitors take the server-side path; no marker is
		// emitted for them, so nothing would convert their prices later.
		if ( is_user_logged_in() ) {
			return array( true, 'logged_in' );
		}

		// 7. Too early to tell. Conditional tags are not set up yet, so
		// "base" would be a guess on the dangerous side.
		if ( ! did_action( 'wp' ) ) {
			return array( true, 'pre_wp' );
		}

		// 8. Catalogue, product and archive display — the cacheable case.
		return array( false, 'display' );
	}

	/**
	 * Whether this request belongs to the admin side.
	 *
	 * An indeterminate referer counts as ADMIN, not front-end. Security
	 * plugins that send `Referrer-Policy: no-referrer` strip the referer
	 * from admin-ajax calls; guessing "front-end" there let the logged-in
	 * branch convert admin order-editor reads. WooCommerce's own front-end
	 * money paths do not run through admin-ajax.php (they use wc-ajax), and
	 * the `mhmcs_should_convert` filter is the escape hatch for a
	 * third-party exception.
	 *
	 * @return bool
	 */
	private function is_admin_context(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		if ( ! wp_doing_ajax() ) {
			return true;
		}

		$referer = wp_get_referer();

		if ( ! is_string( $referer ) || '' === $referer ) {
			return true;
		}

		return 0 === strpos( $referer, admin_url() );
	}

	/**
	 * Whether this request is being served as a REST API request.
	 *
	 * The primitive is pinned deliberately. It is REQUEST_URI-based, like
	 * WooCommerce's own check, plus the `rest_route` parameter that plain
	 * permalinks use. A dispatch-aware primitive would classify the Store
	 * API calls that WooCommerce Blocks preloads through rest_do_request()
	 * *during a page render* as REST requests; those would then fall into
	 * the REST branch and hydrate the cart/checkout blocks with base
	 * amounts while the browser's later Store API calls returned converted
	 * ones.
	 *
	 * @return bool
	 */
	private function is_rest_request(): bool {
		if ( '' !== $this->get_rest_route_param() ) {
			return true;
		}

		return function_exists( 'WC' ) && (bool) WC()->is_rest_api_request();
	}

	/**
	 * Whether the current REST request targets the WooCommerce Store API.
	 *
	 * Only meaningful together with is_rest_request(); the Store API carries
	 * real cart and checkout amounts, so it is excluded from the REST branch
	 * and picked up by the money branch instead.
	 *
	 * @return bool
	 */
	private function is_store_api_request(): bool {
		$route = $this->get_rest_route_param();

		if ( '' !== $route ) {
			return 0 === strpos( $route, self::STORE_API_PREFIX );
		}

		return false !== strpos( $this->get_request_uri(), self::STORE_API_PREFIX . '/' );
	}

	/**
	 * Whether the customer-facing amount is at stake on this request.
	 *
	 * @return bool
	 */
	private function is_money_context(): bool {
		if ( defined( 'WOOCOMMERCE_CHECKOUT' ) || defined( 'WOOCOMMERCE_CART' ) ) {
			return true;
		}

		if ( $this->has_wc_ajax_action() ) {
			return true;
		}

		if ( $this->is_rest_request() && $this->is_store_api_request() ) {
			return true;
		}

		return did_action( 'wp' ) && $this->is_wc_money_page();
	}

	/**
	 * Whether a WooCommerce AJAX action is being served.
	 *
	 * There is no allowlist on purpose: payment gateways register their own
	 * wc-ajax endpoints (`wc_stripe_*`, `ppc-create-order`, …), so a finite
	 * list would miss them and express checkout would charge base amounts.
	 *
	 * The emptiness test matches WC_AJAX::do_wc_ajax(), which only serves an
	 * AJAX response for a NON-EMPTY value. `page/?wc-ajax=` renders as an
	 * ordinary page; treating it as money context would emit a fully
	 * converted, marker-less page — a cache-poisoning vector wherever the
	 * query string is dropped from the cache key.
	 *
	 * @return bool
	 */
	private function has_wc_ajax_action(): bool {
		/*
		 * Nonce verification does not apply: this is a read-only probe of the
		 * request shape, changes no state, and the wc-ajax endpoints it
		 * detects belong to WooCommerce and third-party gateways, which carry
		 * their own nonces (or none at all) on parameters we never read.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['wc-ajax'] ) || ! is_string( $_GET['wc-ajax'] ) ) {
			return false;
		}

		$action = sanitize_key( wp_unslash( $_GET['wc-ajax'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return '' !== $action;
	}

	/**
	 * Whether a WooCommerce money page is being rendered.
	 *
	 * Conditional tags only exist once the main query has run, so callers
	 * must gate this behind did_action( 'wp' ).
	 *
	 * @return bool
	 */
	private function is_wc_money_page(): bool {
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		return function_exists( 'is_account_page' ) && is_account_page();
	}

	/**
	 * Whether cache compatibility mode is enabled.
	 *
	 * Defaults to enabled when the setting has never been written, which
	 * matches the activation default.
	 *
	 * Public so CacheCompatDiagnostic can ask whether the mode is switched on
	 * without reading the setting a second time. That reading is not a trivial
	 * `get_option()` — the "absent key means enabled" rule has to match the
	 * activation default and Enqueue's copy of it exactly, and a fourth place
	 * deciding it independently is how those three drifted apart before.
	 *
	 * Distinct from is_cacheable_render(): this asks what the shop owner
	 * SWITCHED ON, that one asks what this particular request ended up doing.
	 * The diagnostic exists precisely to report when those two disagree.
	 *
	 * @return bool
	 */
	public function is_cache_compat_enabled(): bool {
		$settings = get_option( 'mhmcs_settings', array() );

		if ( ! is_array( $settings ) || ! array_key_exists( 'cache_compat', $settings ) ) {
			return true;
		}

		return (bool) $settings['cache_compat'];
	}

	/**
	 * Read the `rest_route` request parameter used by plain permalinks.
	 *
	 * WooCommerce's REQUEST_URI check only looks for the `wp-json/` prefix,
	 * so on sites without pretty permalinks both wc/v3 and the Store API
	 * would miss their branches. The direction is money-safe either way,
	 * but the REST fix would silently not apply on those sites.
	 *
	 * @return string Route path, or an empty string when absent.
	 */
	private function get_rest_route_param(): string {
		/*
		 * Nonce verification does not apply: read-only probe of the request
		 * shape, mirroring the `rest_route` variable WordPress core itself
		 * inspects to decide whether to serve the REST API. No state change.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['rest_route'] ) || ! is_string( $_GET['rest_route'] ) ) {
			return '';
		}

		$route = sanitize_text_field( wp_unslash( $_GET['rest_route'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $route;
	}

	/**
	 * Current request URI, sanitized.
	 *
	 * @return string
	 */
	private function get_request_uri(): string {
		if ( empty( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	}
}
