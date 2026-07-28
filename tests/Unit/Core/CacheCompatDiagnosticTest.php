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

use MhmCurrencySwitcher\Core\CacheCompatDiagnostic;
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
			$GLOBALS['__mhmcs_test_shortcodes']
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
}
