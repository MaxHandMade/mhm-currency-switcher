<?php
/**
 * Unit tests for the cache-compatibility diagnostic.
 *
 * The failure it watches for is the worst one this feature has, precisely
 * because nothing about it looks like a failure: a theme or plugin that defines
 * WOOCOMMERCE_CART site-wide (a common way to render a header mini-cart total)
 * makes the conversion context answer "money context" on EVERY page. The latch
 * is one-way, so from that point the whole site renders server-side converted —
 * cache compatibility is off, the original bug is back, and there is no error,
 * no warning and no visible difference for the shop owner.
 *
 * 🔴 The decision is a pure function taking the facts as arguments, and the
 * tests never `define()` anything. Defining WOOCOMMERCE_CART inside the test
 * process would leak into every test that ran afterwards — that exact trap has
 * already cost this project a silently skipped test.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use MhmCurrencySwitcher\Admin\Settings;
use MhmCurrencySwitcher\Core\CacheCompatDiagnostic;
use MhmCurrencySwitcher\Core\ConversionContext;
use PHPUnit\Framework\TestCase;

/**
 * Class CacheCompatDiagnosticTest
 *
 * @covers \MhmCurrencySwitcher\Core\CacheCompatDiagnostic
 */
class CacheCompatDiagnosticTest extends TestCase {

	/**
	 * Clean up options between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['__mhmcs_test_options'],
			$GLOBALS['__mhmcs_test_option_writes'],
			$GLOBALS['__mhmcs_test_is_cart'],
			$GLOBALS['__mhmcs_test_is_checkout'],
			$GLOBALS['__mhmcs_test_is_page'],
			$GLOBALS['__mhmcs_test_wc_page_ids'],
			$GLOBALS['__mhmcs_test_shortcodes'],
			$GLOBALS['__mhmcs_test_can'],
			$GLOBALS['__mhmcs_test_current_screen'],
			$GLOBALS['__mhmcs_test_user_meta'],
			$GLOBALS['__mhmcs_test_delete_metadata_calls'],
			$GLOBALS['__mhmcs_test_current_user_id'],
			$GLOBALS['__mhmcs_test_nonce_valid']
		);

		// Fix round 2 / Ruling I hardening: a test proves a client-supplied
		// $_POST['signature'] is ignored, by seeding the real superglobal.
		// Cleared here unconditionally (not inline in the test) so it is
		// removed even if an assertion above it fails first.
		unset( $_POST['signature'] );

		parent::tearDown();
	}

	/**
	 * The anomaly: the cart constant is defined on a page that is not the cart.
	 *
	 * @return void
	 */
	public function test_cart_constant_away_from_the_cart_is_an_anomaly(): void {
		$this->assertTrue(
			CacheCompatDiagnostic::is_anomalous( true, true, false )
		);
	}

	/**
	 * A real cart page is not an anomaly, however loudly it latches.
	 *
	 * 🔴 Without this the warning fires on every cart and checkout view — that
	 * is the normal, correct behaviour of the decision table, and a diagnostic
	 * that cries on correct behaviour gets switched off and then never
	 * reports the real thing.
	 *
	 * @return void
	 */
	public function test_the_real_cart_page_is_not_an_anomaly(): void {
		$this->assertFalse(
			CacheCompatDiagnostic::is_anomalous( true, true, true )
		);
	}

	/**
	 * No cart constant, nothing to report.
	 *
	 * @return void
	 */
	public function test_no_constant_is_not_an_anomaly(): void {
		$this->assertFalse(
			CacheCompatDiagnostic::is_anomalous( true, false, false )
		);
	}

	/**
	 * With the mode switched off there is nothing to be disabled.
	 *
	 * @return void
	 */
	public function test_nothing_is_reported_when_the_mode_is_off(): void {
		$this->assertFalse(
			CacheCompatDiagnostic::is_anomalous( false, true, false )
		);
	}

	/**
	 * 🔴 "Is this really the cart?" must not be answered by is_cart().
	 *
	 * WooCommerce's is_cart() is `filter || defined( 'WOOCOMMERCE_CART' ) ||
	 * CartCheckoutUtils::is_cart_page()`. Asking it whether this is genuinely a
	 * cart view means asking a function that the very constant under
	 * investigation already satisfies — so the anomaly answered "no, it is a
	 * cart page" every single time and the diagnostic could NEVER fire. The
	 * first version of this class did exactly that; the unit tests could not
	 * see it, because they hand the two facts in as independent arguments, and
	 * only a probe in a real page render exposed it.
	 *
	 * The stub below says is_cart() is true while nothing else does. The answer
	 * must still be false.
	 *
	 * @return void
	 */
	public function test_the_cart_check_does_not_come_from_is_cart(): void {
		$GLOBALS['__mhmcs_test_is_cart']     = true;
		$GLOBALS['__mhmcs_test_is_checkout'] = true;

		$this->assertFalse( CacheCompatDiagnostic::is_real_cart_view() );
	}

	/**
	 * The WooCommerce-assigned cart page is a real cart view.
	 *
	 * @return void
	 */
	public function test_the_assigned_cart_page_is_a_real_cart_view(): void {
		$GLOBALS['__mhmcs_test_wc_page_ids'] = array( 'cart' => 42 );
		$GLOBALS['__mhmcs_test_is_page']     = 42;

		$this->assertTrue( CacheCompatDiagnostic::is_real_cart_view() );
	}

	/**
	 * A page carrying the cart shortcode is a real cart view too.
	 *
	 * Shops do put the cart somewhere other than the page WooCommerce assigned.
	 * That page legitimately defines the constant, so warning about it would be
	 * a false alarm.
	 *
	 * @return void
	 */
	public function test_a_page_with_the_cart_shortcode_is_a_real_cart_view(): void {
		$GLOBALS['__mhmcs_test_shortcodes'] = array( 'woocommerce_cart' => true );

		$this->assertTrue( CacheCompatDiagnostic::is_real_cart_view() );
	}

	/**
	 * An ordinary page is not a cart view.
	 *
	 * @return void
	 */
	public function test_an_ordinary_page_is_not_a_cart_view(): void {
		$GLOBALS['__mhmcs_test_wc_page_ids'] = array( 'cart' => 42 );
		$GLOBALS['__mhmcs_test_is_page']     = 7;

		$this->assertFalse( CacheCompatDiagnostic::is_real_cart_view() );
	}

	/**
	 * A recorded anomaly is remembered so the admin screen can show it.
	 *
	 * @return void
	 */
	public function test_an_anomaly_is_recorded(): void {
		CacheCompatDiagnostic::record( true, '/shop/' );

		$this->assertSame(
			'/shop/',
			$GLOBALS['__mhmcs_test_options'][ CacheCompatDiagnostic::OPTION ] ?? null
		);
	}

	/**
	 * A clean render clears an earlier report.
	 *
	 * The shop owner fixes their theme and the warning has to go away by
	 * itself; a notice that can only be dismissed says nothing about whether
	 * the problem is still there.
	 *
	 * @return void
	 */
	public function test_a_clean_render_clears_an_earlier_report(): void {
		CacheCompatDiagnostic::record( true, '/shop/' );
		CacheCompatDiagnostic::record( false, '/shop/' );

		$this->assertSame(
			'',
			$GLOBALS['__mhmcs_test_options'][ CacheCompatDiagnostic::OPTION ] ?? null
		);
	}

	/**
	 * Recording the same state twice does not write twice.
	 *
	 * This runs on every front-end page view. An unconditional update_option()
	 * there is a database write per request on a site whose whole point is
	 * being cached.
	 *
	 * @return void
	 */
	public function test_an_unchanged_state_is_not_written_again(): void {
		CacheCompatDiagnostic::record( true, '/shop/' );

		$GLOBALS['__mhmcs_test_option_writes'] = 0;

		CacheCompatDiagnostic::record( true, '/shop/' );

		$this->assertSame( 0, $GLOBALS['__mhmcs_test_option_writes'] );
	}

	/**
	 * A clean render on a site that never reported anything writes nothing.
	 *
	 * @return void
	 */
	public function test_a_clean_render_on_a_healthy_site_writes_nothing(): void {
		$GLOBALS['__mhmcs_test_option_writes'] = 0;

		CacheCompatDiagnostic::record( false, '/shop/' );

		$this->assertSame( 0, $GLOBALS['__mhmcs_test_option_writes'] );
	}

	/**
	 * The second anomaly: a mini-cart on a cacheable page with no cart fragments.
	 *
	 * A mini-cart is rendered on every page, so it cannot be classified per
	 * request; on a cacheable render it is printed in the base currency and put
	 * right afterwards by WooCommerce's cart fragment refresh, which converts
	 * server-side. Themes and optimisation plugins dequeue `wc-cart-fragments`
	 * routinely — and when they do, the cached mini-cart total simply stays in
	 * the base currency while every other price on the page converts. Nothing
	 * errors, so this too has to be said out loud in the admin.
	 *
	 * @return void
	 */
	public function test_a_mini_cart_without_cart_fragments_is_an_anomaly(): void {
		$this->assertTrue(
			CacheCompatDiagnostic::is_fragments_anomalous( true, true, true, false )
		);
	}

	/**
	 * With cart fragments running there is nothing wrong.
	 *
	 * @return void
	 */
	public function test_a_mini_cart_with_cart_fragments_is_not_an_anomaly(): void {
		$this->assertFalse(
			CacheCompatDiagnostic::is_fragments_anomalous( true, true, true, true )
		);
	}

	/**
	 * 🔴 A site with no mini-cart at all must never be warned.
	 *
	 * Most shops do not render one, and plenty of them have `wc-cart-fragments`
	 * dequeued on purpose for the speed. Without this guard the warning fires on
	 * a correctly configured shop — the same false-alarm failure the cart
	 * constant check was written narrowly to avoid.
	 *
	 * @return void
	 */
	public function test_no_mini_cart_is_not_a_fragments_anomaly(): void {
		$this->assertFalse(
			CacheCompatDiagnostic::is_fragments_anomalous( true, true, false, false )
		);
	}

	/**
	 * A render that already converts server-side has no fragments problem.
	 *
	 * The cart page itself is the obvious case: it is money context, so the
	 * mini-cart on it was converted while rendering and owes nothing to a later
	 * fragment refresh.
	 *
	 * @return void
	 */
	public function test_a_converted_render_is_not_a_fragments_anomaly(): void {
		$this->assertFalse(
			CacheCompatDiagnostic::is_fragments_anomalous( true, false, true, false )
		);
	}

	/**
	 * With the mode switched off the mini-cart converts server-side anyway.
	 *
	 * @return void
	 */
	public function test_fragments_are_not_reported_when_the_mode_is_off(): void {
		$this->assertFalse(
			CacheCompatDiagnostic::is_fragments_anomalous( false, true, true, false )
		);
	}

	/**
	 * The two anomalies are remembered separately.
	 *
	 * They have different causes and different fixes, so one clearing must not
	 * clear the other: a shop can define the cart constant site-wide AND have
	 * dequeued cart fragments, and fixing the theme should not silence the
	 * second report.
	 *
	 * @return void
	 */
	public function test_the_fragments_anomaly_is_recorded_under_its_own_option(): void {
		CacheCompatDiagnostic::record( true, '/shop/' );
		CacheCompatDiagnostic::record( true, '/about/', CacheCompatDiagnostic::OPTION_FRAGMENTS );

		CacheCompatDiagnostic::record( false, '/shop/' );

		$this->assertSame(
			'',
			$GLOBALS['__mhmcs_test_options'][ CacheCompatDiagnostic::OPTION ] ?? null,
			'Clearing the cart-constant report must clear its own option.'
		);
		$this->assertSame(
			'/about/',
			$GLOBALS['__mhmcs_test_options'][ CacheCompatDiagnostic::OPTION_FRAGMENTS ] ?? null,
			'Clearing the cart-constant report must NOT clear the fragments report.'
		);
	}

	/**
	 * A rendered mini-cart is noticed.
	 *
	 * WooCommerce's own `cart/mini-cart.php` template opens with
	 * `woocommerce_before_mini_cart`, so every route to a mini-cart — the
	 * classic widget, a theme's own markup, a block that renders the template —
	 * announces itself through that action. Nothing else in the page tells us.
	 *
	 * @return void
	 */
	public function test_a_rendered_mini_cart_is_noticed(): void {
		$diagnostic = new CacheCompatDiagnostic( new ConversionContext() );

		$this->assertFalse( $diagnostic->has_mini_cart() );

		$diagnostic->note_mini_cart();

		$this->assertTrue( $diagnostic->has_mini_cart() );
	}

	/**
	 * Capture whatever render_notice() prints for a fresh diagnostic.
	 *
	 * @return string
	 */
	private function rendered_notice(): string {
		$diagnostic = new CacheCompatDiagnostic( new ConversionContext() );

		ob_start();
		$diagnostic->render_notice();

		return (string) ob_get_clean();
	}

	/**
	 * Guideline 11: the cache-diagnostic notice needs manage_woocommerce.
	 *
	 * 🔴 Two-sided on purpose (see the docblock on this file). A negative
	 * case alone would pass against a notice that never renders at all —
	 * recording a real anomaly first is what proves the positive case has
	 * something to show.
	 *
	 * @return void
	 */
	public function test_the_cache_notice_needs_manage_woocommerce(): void {
		CacheCompatDiagnostic::record( true, '/shop/' );

		$GLOBALS['__mhmcs_test_current_screen'] = CacheCompatDiagnostic::SCREENS[0];

		$GLOBALS['__mhmcs_test_can'] = array( 'manage_woocommerce' => false );
		$this->assertSame( '', $this->rendered_notice(), 'A user without manage_woocommerce must see nothing.' );

		$GLOBALS['__mhmcs_test_can'] = array( 'manage_woocommerce' => true );
		$output                      = $this->rendered_notice();
		$this->assertNotSame( '', $output, 'A user WITH manage_woocommerce must still see the recorded anomaly.' );
		$this->assertStringContainsString( '/shop/', $output );
	}

	/**
	 * Guideline 11: scoped to the plugin's own admin pages plus
	 * WooCommerce's settings/status screens — and nowhere else.
	 *
	 * @return void
	 */
	public function test_the_cache_notice_is_scoped_to_its_own_screens(): void {
		CacheCompatDiagnostic::record( true, '/shop/' );

		$GLOBALS['__mhmcs_test_can'] = array( 'manage_woocommerce' => true );

		foreach ( CacheCompatDiagnostic::SCREENS as $screen ) {
			$GLOBALS['__mhmcs_test_current_screen'] = $screen;

			$this->assertNotSame(
				'',
				$this->rendered_notice(),
				"Expected the cache-compat notice to render on the '{$screen}' screen."
			);
		}

		$GLOBALS['__mhmcs_test_current_screen'] = 'edit-post';

		$this->assertSame(
			'',
			$this->rendered_notice(),
			"The cache-compat notice must not render on an unrelated screen ('edit-post')."
		);
	}

	/**
	 * A null current screen (before admin_init, or nothing set one up) must
	 * not render the notice, and must not fatal getting there.
	 *
	 * @return void
	 */
	public function test_the_cache_notice_null_screen_does_not_render_or_fatal(): void {
		CacheCompatDiagnostic::record( true, '/shop/' );

		$GLOBALS['__mhmcs_test_can'] = array( 'manage_woocommerce' => true );
		unset( $GLOBALS['__mhmcs_test_current_screen'] );

		$this->assertSame( '', $this->rendered_notice() );
	}

	/**
	 * Exhaustive check of the pure predicate behind render_notice().
	 *
	 * @return void
	 */
	public function test_is_notice_visible_requires_both_capability_and_an_allowed_screen(): void {
		$this->assertFalse( CacheCompatDiagnostic::is_notice_visible( false, CacheCompatDiagnostic::SCREENS[0] ) );
		$this->assertFalse( CacheCompatDiagnostic::is_notice_visible( true, null ) );
		$this->assertFalse( CacheCompatDiagnostic::is_notice_visible( true, 'edit-post' ) );
		$this->assertFalse( CacheCompatDiagnostic::is_notice_visible( false, null ) );

		foreach ( CacheCompatDiagnostic::SCREENS as $screen ) {
			$this->assertTrue( CacheCompatDiagnostic::is_notice_visible( true, $screen ) );
		}
	}

	/**
	 * 🔴 SCREENS[0] must be Settings' page hook suffix as add_submenu_page()
	 * actually returns it at runtime — not a string typed twice.
	 *
	 * `Settings::$hook_suffix` is private, so this reads it through the
	 * public `get_hook_suffix()` accessor after really calling
	 * `add_menu_page()`, the same method WordPress calls on `admin_menu`.
	 * The bootstrap stub for `add_submenu_page()` computes its return value
	 * from the SAME parent slug and menu slug Settings::add_menu_page()
	 * passes it ("{parent}_page_{menu_slug}", WordPress's own convention for
	 * a plugin-owned submenu) — it does not know CacheCompatDiagnostic::
	 * SCREENS exists. So if Settings' page slug or parent menu ever changes,
	 * get_hook_suffix() changes with it and this assertion fails, instead of
	 * two independently-typed literals silently agreeing forever.
	 *
	 * @return void
	 */
	public function test_settings_hook_suffix_matches_a_scoped_screen_at_runtime(): void {
		$settings = new Settings();
		$settings->add_menu_page();

		$hook_suffix = $settings->get_hook_suffix();

		$this->assertNotSame( '', $hook_suffix, 'add_menu_page() must have set a real hook suffix.' );
		$this->assertContains(
			$hook_suffix,
			CacheCompatDiagnostic::SCREENS,
			"Settings' real runtime hook suffix ('{$hook_suffix}') must be one of the screens "
				. 'CacheCompatDiagnostic scopes its notice to, or the notice will never appear on the '
				. "plugin's own settings page."
		);
	}

	/*
	 * ─── Task 16: signature-based snooze ──────────────────────────────────
	 */

	/**
	 * A snooze recorded for the CURRENT signature hides the notice.
	 *
	 * @return void
	 */
	public function test_snooze_hides_the_notice_for_the_same_signature(): void {
		CacheCompatDiagnostic::record( true, '/sepet/' );

		$GLOBALS['__mhmcs_test_can']            = array( 'manage_woocommerce' => true );
		$GLOBALS['__mhmcs_test_current_screen'] = CacheCompatDiagnostic::SCREENS[0];
		$GLOBALS['__mhmcs_test_user_meta']      = array(
			1 => array( CacheCompatDiagnostic::SNOOZE_META => '/sepet/' ),
		);

		$this->assertSame(
			'',
			$this->rendered_notice(),
			'A snooze pinned to the signature currently stored must hide the notice.'
		);
	}

	/**
	 * 🔴 A CHANGED signature brings the notice back, even though the snooze
	 * that hid the old one is still sitting in user meta. This is the whole
	 * point of a signature-scoped snooze rather than a "dismissed forever"
	 * boolean: a new location is new information a shop owner has not seen.
	 *
	 * @return void
	 */
	public function test_a_changed_signature_brings_the_notice_back(): void {
		CacheCompatDiagnostic::record( true, '/sepet/' );

		$GLOBALS['__mhmcs_test_user_meta'] = array(
			1 => array( CacheCompatDiagnostic::SNOOZE_META => '/sepet/' ),
		);

		// The anomaly is seen again, but at a DIFFERENT path.
		CacheCompatDiagnostic::record( true, '/odeme/' );

		$GLOBALS['__mhmcs_test_can']            = array( 'manage_woocommerce' => true );
		$GLOBALS['__mhmcs_test_current_screen'] = CacheCompatDiagnostic::SCREENS[0];

		$output = $this->rendered_notice();

		$this->assertNotSame(
			'',
			$output,
			'A signature change must bring the notice back even though the OLD signature was snoozed.'
		);
		$this->assertStringContainsString( '/odeme/', $output );
	}

	/**
	 * record() empties the option when the anomaly resolves; if the snooze
	 * meta survived that, the SAME anomaly returning later would stay hidden
	 * forever behind a snooze nobody re-armed.
	 *
	 * @return void
	 */
	public function test_clearing_the_anomaly_also_clears_the_snooze(): void {
		CacheCompatDiagnostic::record( true, '/sepet/' );

		$GLOBALS['__mhmcs_test_user_meta'] = array(
			1 => array( CacheCompatDiagnostic::SNOOZE_META => '/sepet/' ),
		);

		CacheCompatDiagnostic::record( false, '/sepet/' );

		$this->assertSame(
			'',
			get_user_meta( 1, CacheCompatDiagnostic::SNOOZE_META, true ),
			'Clearing the anomaly must clear the snooze meta pinned to it.'
		);
	}

	/**
	 * 🔴 Ruling: clear the meta on the TRANSITION, not on every clean pass.
	 * record() runs on every front-end request, and delete_metadata() here is
	 * a SITE-WIDE delete across every user's row. A site that never had a
	 * problem must never issue it — otherwise a correct implementation and a
	 * wasteful one that issues this delete on every ordinary request are
	 * indistinguishable from their green tests alone.
	 *
	 * @return void
	 */
	public function test_a_clean_render_on_a_healthy_site_does_not_delete_snooze_meta(): void {
		$GLOBALS['__mhmcs_test_delete_metadata_calls'] = 0;

		CacheCompatDiagnostic::record( false, '/shop/' );

		$this->assertSame(
			0,
			$GLOBALS['__mhmcs_test_delete_metadata_calls'],
			'A clean render on a site that never had a problem must not touch the meta table at all.'
		);
	}

	/**
	 * The same guard, the other way it could be wrong: once the transition
	 * has already fired once, repeated clean renders afterwards must not
	 * keep firing it again.
	 *
	 * @return void
	 */
	public function test_repeated_clean_renders_do_not_delete_snooze_meta_after_the_first_clear(): void {
		CacheCompatDiagnostic::record( true, '/shop/' );
		CacheCompatDiagnostic::record( false, '/shop/' );

		$GLOBALS['__mhmcs_test_delete_metadata_calls'] = 0;

		CacheCompatDiagnostic::record( false, '/shop/' );

		$this->assertSame(
			0,
			$GLOBALS['__mhmcs_test_delete_metadata_calls'],
			'The delete belongs to the ONE transition; a site already clean must not repeat it.'
		);
	}

	/**
	 * Guideline 11 / new attack surface: a request without a nonce header at
	 * all is rejected, even when the capability check alone would pass and
	 * even when a nonce, had one been sent, would have verified.
	 *
	 * @return void
	 */
	public function test_the_snooze_endpoint_rejects_a_request_without_a_nonce(): void {
		$GLOBALS['__mhmcs_test_can']         = array( 'manage_woocommerce' => true );
		$GLOBALS['__mhmcs_test_nonce_valid'] = true;

		$diagnostic = new CacheCompatDiagnostic( new ConversionContext() );
		$request    = new \WP_REST_Request(); // No X-WP-Nonce header set at all.

		$this->assertFalse(
			$diagnostic->check_snooze_permission( $request ),
			'A request carrying no nonce header must be rejected regardless of capability.'
		);
	}

	/**
	 * A valid nonce is not enough without manage_woocommerce.
	 *
	 * @return void
	 */
	public function test_the_snooze_endpoint_rejects_a_user_without_manage_woocommerce(): void {
		$GLOBALS['__mhmcs_test_can']         = array( 'manage_woocommerce' => false );
		$GLOBALS['__mhmcs_test_nonce_valid'] = true;

		$diagnostic = new CacheCompatDiagnostic( new ConversionContext() );
		$request    = new \WP_REST_Request();
		$request->set_header( 'X-WP-Nonce', 'a-real-looking-nonce' );

		$this->assertFalse(
			$diagnostic->check_snooze_permission( $request ),
			'A valid nonce alone must not be enough without manage_woocommerce.'
		);
	}

	/**
	 * 🔴 Positive counterpart to BOTH negative tests above. Without this, an
	 * endpoint that rejects every request would also pass both negative
	 * cases — a nonce AND a capability that are both actually satisfied must
	 * be permitted.
	 *
	 * @return void
	 */
	public function test_the_snooze_endpoint_permits_a_request_with_both_a_nonce_and_the_capability(): void {
		$GLOBALS['__mhmcs_test_can']         = array( 'manage_woocommerce' => true );
		$GLOBALS['__mhmcs_test_nonce_valid'] = true;

		$diagnostic = new CacheCompatDiagnostic( new ConversionContext() );
		$request    = new \WP_REST_Request();
		$request->set_header( 'X-WP-Nonce', 'a-real-looking-nonce' );

		$this->assertTrue( $diagnostic->check_snooze_permission( $request ) );
	}

	/**
	 * Exhaustive check of the pure predicate behind check_snooze_permission().
	 *
	 * @return void
	 */
	public function test_is_snooze_permitted_requires_both_nonce_and_capability(): void {
		$this->assertFalse( CacheCompatDiagnostic::is_snooze_permitted( false, false ) );
		$this->assertFalse( CacheCompatDiagnostic::is_snooze_permitted( false, true ) );
		$this->assertFalse( CacheCompatDiagnostic::is_snooze_permitted( true, false ) );
		$this->assertTrue( CacheCompatDiagnostic::is_snooze_permitted( true, true ) );
	}

	/**
	 * 🔴 Ruling I, defended by a test that can actually fail. The reviewer
	 * mutated snooze() to prefer a $_POST['signature'] over the server-read
	 * option and the full suite stayed green — nothing was defending the
	 * property "the endpoint accepts no signature from the client" at all.
	 * This seeds the real $_POST superglobal with a value that does NOT
	 * match the recorded anomaly and asserts the meta written is the
	 * SERVER-READ signature, never the submitted one. Both routes share
	 * the private snooze() helper this exercises, so this one test covers
	 * both.
	 *
	 * @return void
	 */
	public function test_the_snooze_endpoint_ignores_a_client_supplied_signature(): void {
		CacheCompatDiagnostic::record( true, '/sepet/' );

		$_POST['signature'] = '/injected-by-the-client/';

		$diagnostic = new CacheCompatDiagnostic( new ConversionContext() );
		$response   = $diagnostic->snooze_cart_constant_anomaly();
		$data       = $response->get_data();

		$this->assertSame(
			'/sepet/',
			$data['signature'] ?? null,
			'The response must report the SERVER-READ signature, never a submitted one.'
		);
		$this->assertSame(
			'/sepet/',
			get_user_meta( get_current_user_id(), CacheCompatDiagnostic::SNOOZE_META, true ),
			"A client-supplied \$_POST['signature'] must never reach user meta -- only get_option()'s value may."
		);
	}

	/**
	 * 🔴 The endpoint accepts NO signature from the client. It writes exactly
	 * the signature CURRENTLY stored server-side — proven here by recording
	 * one value and never handing the callback anything at all.
	 *
	 * @return void
	 */
	public function test_the_snooze_endpoint_writes_the_current_signature_to_user_meta(): void {
		CacheCompatDiagnostic::record( true, '/sepet/' );

		$diagnostic = new CacheCompatDiagnostic( new ConversionContext() );
		$response   = $diagnostic->snooze_cart_constant_anomaly();
		$data       = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( '/sepet/', $data['signature'] );
		$this->assertSame(
			'/sepet/',
			get_user_meta( get_current_user_id(), CacheCompatDiagnostic::SNOOZE_META, true )
		);
	}

	/**
	 * Ruling I's edge case: if there is genuinely no recorded anomaly when
	 * the request arrives, the endpoint does nothing and says so — it must
	 * not manufacture a snooze for an empty signature.
	 *
	 * 🔴 Fix round 2 / Finding 2: this used to assert a 200 response with
	 * `success: false` in the body. The reviewer noted the JS caller
	 * (cache-notice-snooze.js) branches on `response.ok` alone and never
	 * parses the body, so a 200 here was a dishonest contract no client
	 * actually reads — a WP_Error carrying a 4xx status is what makes
	 * `response.ok` the correct signal. This is the only test that
	 * asserted the old 200/false shape; updated rather than left behind.
	 *
	 * @return void
	 */
	public function test_the_snooze_endpoint_does_nothing_when_there_is_no_recorded_anomaly(): void {
		$diagnostic = new CacheCompatDiagnostic( new ConversionContext() );
		$response   = $diagnostic->snooze_cart_constant_anomaly();

		$this->assertInstanceOf(
			\WP_Error::class,
			$response,
			'Nothing is recorded, so the endpoint must answer with an ERROR, not a 200 success:false.'
		);
		$this->assertSame(
			409,
			$response->get_error_data()['status'] ?? null,
			'The status must be a 4xx the client can branch on via response.ok alone.'
		);
		$this->assertArrayNotHasKey(
			CacheCompatDiagnostic::SNOOZE_META,
			$GLOBALS['__mhmcs_test_user_meta'][ get_current_user_id() ] ?? array(),
			'Nothing was recorded, so nothing may be written to user meta.'
		);
	}

	/**
	 * The two snoozes are independent, exactly like the two anomalies they
	 * belong to: snoozing the cart-constant notice must not silence the
	 * fragments notice.
	 *
	 * @return void
	 */
	public function test_the_fragments_snooze_is_independent_of_the_cart_constant_snooze(): void {
		CacheCompatDiagnostic::record( true, '/shop/' );
		CacheCompatDiagnostic::record( true, '/about/', CacheCompatDiagnostic::OPTION_FRAGMENTS );

		$GLOBALS['__mhmcs_test_user_meta'] = array(
			1 => array( CacheCompatDiagnostic::SNOOZE_META => '/shop/' ),
		);

		$GLOBALS['__mhmcs_test_can']            = array( 'manage_woocommerce' => true );
		$GLOBALS['__mhmcs_test_current_screen'] = CacheCompatDiagnostic::SCREENS[0];

		$output = $this->rendered_notice();

		$this->assertStringNotContainsString( '/shop/', $output, 'The snoozed cart-constant notice must stay hidden.' );
		$this->assertStringContainsString( '/about/', $output, 'The UN-snoozed fragments notice must still render.' );
	}

	/**
	 * uninstall.php cannot import the class and duplicates its meta-key
	 * literals; pin them together so a rename on one side fails loudly
	 * instead of leaving an orphaned row (or an un-purged one) behind.
	 *
	 * @return void
	 */
	public function test_uninstall_deletes_the_same_snooze_meta_keys_the_class_defines(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/uninstall.php' );

		$this->assertIsString( $source, 'uninstall.php must be readable.' );
		$this->assertStringContainsString(
			"delete_metadata( 'user', 0, '" . CacheCompatDiagnostic::SNOOZE_META . "', '', true )",
			$source,
			'uninstall.php must delete the exact same key CacheCompatDiagnostic::SNOOZE_META names.'
		);
		$this->assertStringContainsString(
			"delete_metadata( 'user', 0, '" . CacheCompatDiagnostic::SNOOZE_META_FRAGMENTS . "', '', true )",
			$source,
			'uninstall.php must delete the exact same key CacheCompatDiagnostic::SNOOZE_META_FRAGMENTS names.'
		);
	}
}
