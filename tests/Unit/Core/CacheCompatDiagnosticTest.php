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
			$GLOBALS['__mhmcs_test_current_screen']
		);

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
}
