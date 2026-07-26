<?php
/**
 * Unit tests for ConversionContext.
 *
 * Locks the nine-branch decision table (design spec §3.1), the money
 * context definition (§3.2) and the one-way memoization latch (§3.3).
 * The branch ORDER is the product of five independent audit rounds —
 * several tests exist only to prove one branch is evaluated before
 * another, so they must keep asserting through the public API.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use MhmCurrencySwitcher\Core\ConversionContext;
use PHPUnit\Framework\TestCase;

/**
 * Class ConversionContextTest
 *
 * Pure unit tests — no WordPress. Request context is driven through the
 * $GLOBALS['__mhmcs_test_*'] stubs declared in tests/bootstrap.php.
 *
 * @covers \MhmCurrencySwitcher\Core\ConversionContext
 */
class ConversionContextTest extends TestCase {

	/**
	 * Context under test.
	 *
	 * @var ConversionContext
	 */
	private ConversionContext $context;

	/**
	 * Reset every request-shaped global before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->reset_request();
		$this->context = new ConversionContext();
	}

	/**
	 * Leave no context behind for neighbouring tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->reset_request();

		parent::tearDown();
	}

	/**
	 * Clear all context stubs to "plain anonymous front-end request".
	 *
	 * @return void
	 */
	private function reset_request(): void {
		unset(
			$GLOBALS['__mhmcs_test_is_admin'],
			$GLOBALS['__mhmcs_test_doing_ajax'],
			$GLOBALS['__mhmcs_test_doing_cron'],
			$GLOBALS['__mhmcs_test_referer'],
			$GLOBALS['__mhmcs_test_logged_in'],
			$GLOBALS['__mhmcs_test_is_cart'],
			$GLOBALS['__mhmcs_test_is_checkout'],
			$GLOBALS['__mhmcs_test_is_account_page']
		);

		unset( $_GET['wc-ajax'], $_GET['rest_route'], $_SERVER['REQUEST_URI'] );

		$GLOBALS['__mhmcs_test_options']     = array();
		$GLOBALS['__mhmcs_test_filters']     = array();
		$GLOBALS['__mhmcs_test_did_actions'] = array();
	}

	/**
	 * Mark the `wp` action as fired (i.e. conditional tags are available).
	 *
	 * @return void
	 */
	private function fire_wp(): void {
		$GLOBALS['__mhmcs_test_did_actions']['wp'] = 1;
	}

	// ─── Decision 0: force_convert ───────────────────────────────────

	/**
	 * Decision 0 sits above every other branch, including admin and REST.
	 *
	 * @return void
	 */
	public function test_force_convert_wins_over_everything(): void {
		$GLOBALS['__mhmcs_test_is_admin']    = true;
		$GLOBALS['__mhmcs_test_doing_cron']  = true;
		$_SERVER['REQUEST_URI']              = '/wp-json/wc/v3/products';

		$this->assertFalse( $this->context->should_convert(), 'Guard: this context must be "base" without the force flag.' );

		$this->context->force_convert( true );
		$this->assertTrue( $this->context->should_convert(), 'force_convert() must beat the admin, REST and cron branches.' );

		$this->context->force_convert( false );
		$this->assertFalse( $this->context->should_convert(), 'force_convert( false ) must hand the decision back to the table.' );
	}

	// ─── Decision 1: admin context (three sub-cases) ─────────────────

	/**
	 * Plain wp-admin page load: never converted (spec §8.1).
	 *
	 * @return void
	 */
	public function test_admin_page_does_not_convert(): void {
		$GLOBALS['__mhmcs_test_is_admin'] = true;

		$this->assertFalse( $this->context->should_convert() );
	}

	/**
	 * admin-ajax.php called from a wp-admin screen: still admin.
	 *
	 * @return void
	 */
	public function test_admin_ajax_with_admin_referer_does_not_convert(): void {
		$GLOBALS['__mhmcs_test_is_admin']   = true;
		$GLOBALS['__mhmcs_test_doing_ajax'] = true;
		$GLOBALS['__mhmcs_test_referer']    = 'https://example.test/wp-admin/post.php?post=12&action=edit';

		$this->assertFalse( $this->context->should_convert() );
	}

	/**
	 * Round 3 / H-3: a missing referer counts as ADMIN, not front-end.
	 *
	 * Security plugins that send `Referrer-Policy: no-referrer` strip it;
	 * treating that as front-end let the logged-in branch reopen the
	 * "order line item stored at a converted price" bug.
	 *
	 * @return void
	 */
	public function test_admin_ajax_with_missing_referer_does_not_convert(): void {
		$GLOBALS['__mhmcs_test_is_admin']   = true;
		$GLOBALS['__mhmcs_test_doing_ajax'] = true;
		$GLOBALS['__mhmcs_test_logged_in']  = true;

		$this->assertFalse( $this->context->should_convert() );
	}

	/**
	 * Round 4 / L-2: front-end admin-ajax falls through to decision 7.
	 *
	 * `wp` never fires on admin-ajax.php, so WooCommerce's legacy
	 * wp_ajax_woocommerce_* handlers reach the terminal pre-`wp` branch
	 * and convert.
	 *
	 * @return void
	 */
	public function test_frontend_admin_ajax_with_frontend_referer_converts(): void {
		$GLOBALS['__mhmcs_test_is_admin']   = true;
		$GLOBALS['__mhmcs_test_doing_ajax'] = true;
		$GLOBALS['__mhmcs_test_referer']    = 'https://example.test/shop/';

		$this->assertTrue( $this->context->should_convert() );
	}

	// ─── Decision 2: REST (Store API excluded) ───────────────────────

	/**
	 * wc/v3 product reads stay in the base currency (spec §8.4).
	 *
	 * @return void
	 */
	public function test_rest_request_does_not_convert(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/products/17';

		$this->assertFalse( $this->context->should_convert() );
	}

	/**
	 * Round 3 / NB-1: decision 2 must be evaluated BEFORE the logged-in
	 * branch — wc/v3 is always authenticated, so the REST branch never
	 * ran while it sat behind decision 6.
	 *
	 * @return void
	 */
	public function test_authenticated_rest_request_does_not_convert(): void {
		$_SERVER['REQUEST_URI']            = '/wp-json/wc/v3/products/17';
		$GLOBALS['__mhmcs_test_logged_in'] = true;

		$this->assertFalse( $this->context->should_convert() );
	}

	/**
	 * Plain permalinks: REST arrives as ?rest_route=… (spec §3.2, tur 5 / M-3).
	 *
	 * @return void
	 */
	public function test_rest_route_query_param_is_treated_as_rest(): void {
		$_SERVER['REQUEST_URI']   = '/?rest_route=/wc/v3/products/17';
		$_GET['rest_route']       = '/wc/v3/products/17';

		$this->assertFalse( $this->context->should_convert() );
	}

	/**
	 * Store API is excluded from decision 2 and caught by decision 5.
	 *
	 * @return void
	 */
	public function test_store_api_request_converts(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart';

		$this->assertTrue( $this->context->should_convert() );
	}

	/**
	 * Store API on a plain-permalink site converts too.
	 *
	 * @return void
	 */
	public function test_store_api_via_rest_route_query_param_converts(): void {
		$_SERVER['REQUEST_URI'] = '/?rest_route=/wc/store/v1/cart';
		$_GET['rest_route']     = '/wc/store/v1/cart';

		$this->assertTrue( $this->context->should_convert() );
	}

	/**
	 * Round 4 / YB4-2: WC Blocks preloads Store API routes through
	 * rest_do_request() WHILE the cart page renders. REQUEST_URI is still
	 * the page URL, so the pinned primitive says "not REST" and the money
	 * branch keeps the hydrate data converted. A dispatch-aware primitive
	 * would drop this into decision 2 and print base amounts.
	 *
	 * @return void
	 */
	public function test_internal_rest_dispatch_during_page_render_is_not_rest(): void {
		$_SERVER['REQUEST_URI']          = '/cart/';
		$GLOBALS['__mhmcs_test_is_cart'] = true;
		$this->fire_wp();

		$this->assertTrue( $this->context->should_convert() );
	}

	// ─── Decision 3: cron / CLI ──────────────────────────────────────

	/**
	 * Cron runs have no visitor; geolocation must not convert their reads.
	 *
	 * @return void
	 */
	public function test_cron_does_not_convert(): void {
		$GLOBALS['__mhmcs_test_doing_cron'] = true;
		$this->fire_wp();

		$this->assertFalse( $this->context->should_convert() );
	}

	/**
	 * Same for WP-CLI.
	 *
	 * Runs isolated because `WP_CLI` is a constant and cannot be undefined
	 * once set — defining it in-process would leak into every later test.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_cli_does_not_convert(): void {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		$this->fire_wp();

		$this->assertFalse( $this->context->should_convert() );
	}

	// ─── Decision 4: cache_compat toggle ─────────────────────────────

	/**
	 * Mode OFF restores the v1.0.0 display behaviour (server-side convert).
	 *
	 * @return void
	 */
	public function test_toggle_off_converts(): void {
		$GLOBALS['__mhmcs_test_options']['mhmcs_settings'] = array( 'cache_compat' => false );
		$this->fire_wp();

		$this->assertTrue( $this->context->should_convert() );
	}

	/**
	 * Decisions 1-3 sit ABOVE the toggle (spec §3.4): mode OFF is not
	 * "v1.0.0 verbatim", the admin/REST/cron bug fixes survive it.
	 *
	 * @return void
	 */
	public function test_toggle_off_does_not_resurrect_admin_conversion(): void {
		$GLOBALS['__mhmcs_test_options']['mhmcs_settings'] = array( 'cache_compat' => false );
		$GLOBALS['__mhmcs_test_is_admin']                  = true;

		$this->assertFalse( $this->context->should_convert() );
	}

	// ─── Decision 5: money context ───────────────────────────────────

	/**
	 * Round 4 / M-1: `?wc-ajax=` with an EMPTY value is rendered by
	 * WooCommerce as a normal page. Treating it as money context would
	 * emit a converted, marker-less full page — a cache-poisoning vector
	 * on any edge config that drops the query string from the cache key.
	 *
	 * @return void
	 */
	public function test_empty_wc_ajax_param_is_not_money_context(): void {
		$_GET['wc-ajax'] = '';
		$this->fire_wp();

		$this->assertFalse( $this->context->should_convert() );
	}

	/**
	 * Same vector as the empty value: WC_AJAX::do_wc_ajax() gates on the
	 * truthiness of the action, so `?wc-ajax=0` is ALSO served as an
	 * ordinary page. `! empty()` — the emptiness test the spec pins — is
	 * what makes "0" behave like "".
	 *
	 * @return void
	 */
	public function test_zero_valued_wc_ajax_param_is_not_money_context(): void {
		$_GET['wc-ajax'] = '0';
		$this->fire_wp();

		$this->assertFalse( $this->context->should_convert() );
	}

	/**
	 * Round 2 / YB-2: no allowlist — ANY non-empty wc-ajax action converts,
	 * because payment gateways register their own endpoints.
	 *
	 * @return void
	 */
	public function test_non_empty_wc_ajax_param_converts(): void {
		$_GET['wc-ajax'] = 'wc_stripe_get_cart_details';
		$this->fire_wp();

		$this->assertTrue( $this->context->should_convert() );
	}

	/**
	 * Conditional tags, once available, mark the cart/checkout/account area
	 * as money context.
	 *
	 * @return void
	 */
	public function test_checkout_conditional_tag_converts(): void {
		$GLOBALS['__mhmcs_test_is_checkout'] = true;
		$this->fire_wp();

		$this->assertTrue( $this->context->should_convert() );
	}

	/**
	 * The classic checkout constant is money context on its own.
	 *
	 * Isolated: WOOCOMMERCE_CHECKOUT cannot be undefined once set.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_woocommerce_cart_constant_converts(): void {
		if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
		}

		$this->fire_wp();

		$this->assertTrue( $this->context->should_convert() );
	}

	// ─── Decision 6: logged-in ───────────────────────────────────────

	/**
	 * Logged-in visitors take the server-side path (no marker, no JS).
	 *
	 * @return void
	 */
	public function test_logged_in_user_converts(): void {
		$GLOBALS['__mhmcs_test_logged_in'] = true;
		$this->fire_wp();

		$this->assertTrue( $this->context->should_convert() );
	}

	// ─── Decision 7: pre-`wp` ────────────────────────────────────────

	/**
	 * Reads before `wp` (e.g. the wp_loaded cart-session validation)
	 * convert: conditional tags do not exist yet, so "base" would be a
	 * guess on the dangerous side.
	 *
	 * @return void
	 */
	public function test_pre_wp_read_converts(): void {
		$this->assertTrue( $this->context->should_convert() );
	}

	// ─── Decision 8: display ─────────────────────────────────────────

	/**
	 * The whole point: anonymous catalogue rendering stays in base.
	 *
	 * @return void
	 */
	public function test_catalog_display_does_not_convert(): void {
		$this->fire_wp();

		$this->assertFalse( $this->context->should_convert() );
	}

	// ─── Escape hatch ────────────────────────────────────────────────

	/**
	 * `mhmcs_should_convert` can flip the decision in both directions and
	 * receives the branch reason as its second argument.
	 *
	 * @return void
	 */
	public function test_filter_can_override_decision(): void {
		$this->fire_wp();

		$seen = array();

		$GLOBALS['__mhmcs_test_filters']['mhmcs_should_convert'] = static function ( $decision, $reason ) use ( &$seen ) {
			$seen[] = array( $decision, $reason );

			return true;
		};

		$this->assertTrue( $this->context->should_convert(), 'A filter must be able to force conversion on a display request.' );
		$this->assertCount( 1, $seen );
		$this->assertFalse( $seen[0][0], 'The filter must receive the unfiltered decision.' );
		$this->assertIsString( $seen[0][1], 'The filter must receive a branch reason string.' );
		$this->assertNotSame( '', $seen[0][1] );

		$other = new ConversionContext();
		$GLOBALS['__mhmcs_test_filters']['mhmcs_should_convert'] = static function () {
			return false;
		};
		$GLOBALS['__mhmcs_test_logged_in'] = true;

		$this->assertFalse( $other->should_convert(), 'A filter must be able to force base on a converting request.' );
	}

	// ─── Memoization: one-way latch (§3.3) ───────────────────────────

	/**
	 * Round 2 / YB-1: the pre-`wp` "convert" answer must NOT be cached.
	 * It leaked into the same request's template render and re-created the
	 * root bug for every visitor with a cart.
	 *
	 * @return void
	 */
	public function test_pre_wp_answer_is_not_memoized(): void {
		$this->assertTrue( $this->context->should_convert(), 'Guard: pre-`wp` reads convert.' );

		$this->fire_wp();

		$this->assertFalse(
			$this->context->should_convert(),
			'The pre-`wp` answer must not be memoized into the render phase.'
		);
	}

	/**
	 * Round 3 / H-2: a "base" answer is never latched, because a cart
	 * shortcode can define WOOCOMMERCE_CART mid-render. Latching base would
	 * produce "show base, charge converted".
	 *
	 * @return void
	 */
	public function test_base_answer_is_never_latched(): void {
		$this->fire_wp();

		$this->assertFalse( $this->context->should_convert() );
		$this->assertFalse( $this->context->should_convert() );

		// Money context appears mid-request.
		$_GET['wc-ajax'] = 'checkout';

		$this->assertTrue(
			$this->context->should_convert(),
			'A base answer must be recomputed on every call.'
		);
	}

	/**
	 * The latch is one-way: once converted, the request stays converted.
	 *
	 * @return void
	 */
	public function test_convert_answer_is_latched(): void {
		$this->fire_wp();
		$_GET['wc-ajax'] = 'checkout';

		$this->assertTrue( $this->context->should_convert() );

		// Money signal disappears — the answer must not flip back.
		unset( $_GET['wc-ajax'] );

		$this->assertTrue(
			$this->context->should_convert(),
			'A convert answer must latch for the rest of the request.'
		);
	}

	// ─── Scoped forcing: the convert endpoint's entry point ──────────

	/**
	 * Inside the callback the request converts, even though the table would
	 * answer "base" for it.
	 *
	 * The REQUEST_URI is the endpoint's own, which is the case that matters:
	 * a real `POST /wp-json/mhmcs/v1/convert` hits decision 2 (REST, not
	 * Store API) and resolves to BASE. Forcing is the only reason the endpoint
	 * returns converted prices at all, so the guard assertion below is not
	 * decoration — without it this test would pass on a context that converts
	 * for some unrelated reason.
	 *
	 * @return void
	 */
	public function test_with_forced_conversion_converts_inside_the_callback(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/mhmcs/v1/convert';
		$this->fire_wp();

		$this->assertFalse(
			$this->context->should_convert(),
			'Guard: a REST request resolves to base (decision 2). If this ever became true on its own, the test below would prove nothing about forcing.'
		);

		$inside = null;

		$returned = $this->context->with_forced_conversion(
			function () use ( &$inside ) {
				$inside = $this->context->should_convert();

				return 'rendered';
			}
		);

		$this->assertTrue( $inside, 'Every price surface must see "convert" while the endpoint renders.' );
		$this->assertSame( 'rendered', $returned, 'The callback\'s return value is the endpoint\'s rendered payload and must be handed back.' );
	}

	/**
	 * 🔴 The force — and the latch it causes — must not outlive the callback.
	 *
	 * Decision 0 answers "convert" and the `wp` action has fired, so the very
	 * act of rendering inside the callback LATCHES the context. If that latch
	 * survived, a page render that dispatched this endpoint through
	 * rest_do_request() would carry on converting for the rest of the request:
	 * the remainder of the page would be printed converted AND marker-less,
	 * and then cached in one visitor's currency.
	 *
	 * @return void
	 */
	public function test_with_forced_conversion_leaves_no_latch_behind(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/mhmcs/v1/convert';
		$this->fire_wp();

		$this->context->with_forced_conversion(
			function () {
				// Reading the decision is what arms the latch.
				$this->assertTrue( $this->context->should_convert() );

				return null;
			}
		);

		$this->assertFalse(
			$this->context->should_convert(),
			'The endpoint\'s forced conversion must not latch the surrounding request into converting every remaining price.'
		);
	}

	/**
	 * A request that had ALREADY latched for its own reasons stays latched.
	 *
	 * The mirror image of the test above, and the reason the force is restored
	 * rather than simply switched off: clearing the latch unconditionally would
	 * let a cart page fall back to base prices half-way through rendering —
	 * "show base, charge converted", the failure §3.3 exists to prevent.
	 *
	 * @return void
	 */
	public function test_with_forced_conversion_preserves_an_existing_latch(): void {
		$this->fire_wp();
		$_GET['wc-ajax'] = 'checkout';

		$this->assertTrue( $this->context->should_convert(), 'Guard: the money context latches.' );

		unset( $_GET['wc-ajax'] );

		$this->context->with_forced_conversion(
			static function () {
				return null;
			}
		);

		$this->assertTrue(
			$this->context->should_convert(),
			'An already-latched request must come out of the endpoint still latched.'
		);
	}

	/**
	 * A callback that throws still releases the force.
	 *
	 * WooCommerce's price rendering can throw, and an exception escaping with
	 * the force left on would convert the rest of the request.
	 *
	 * @return void
	 */
	public function test_with_forced_conversion_releases_the_force_when_the_callback_throws(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/mhmcs/v1/convert';
		$this->fire_wp();

		try {
			$this->context->with_forced_conversion(
				static function () {
					throw new \RuntimeException( 'price rendering blew up' );
				}
			);

			$this->fail( 'The exception must propagate to the caller.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'price rendering blew up', $e->getMessage() );
		}

		$this->assertFalse(
			$this->context->should_convert(),
			'A throwing callback must not leave the request forced into conversion.'
		);
	}
}
