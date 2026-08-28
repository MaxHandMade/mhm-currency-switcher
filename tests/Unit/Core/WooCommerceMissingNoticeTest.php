<?php
/**
 * Unit tests for the WooCommerce-missing admin notice.
 *
 * WordPress.org's Guideline 11 requires every admin notice to be
 * dismissible, shown only to users who can act on it, and scoped to screens
 * where it is relevant. Before this task the notice had none of the three:
 * it printed unconditionally on every `admin_notices` firing, to every user
 * who could reach wp-admin, on every admin screen.
 *
 * 🔴 Every pair below is deliberately two-sided. A one-sided test — only the
 * negative case — passes just as well against a notice that never renders
 * at all; the positive case is what proves the negative case is hiding the
 * notice rather than the notice having no output to hide.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use MhmCurrencySwitcher\Core\WooCommerceMissingNotice;
use PHPUnit\Framework\TestCase;

/**
 * Class WooCommerceMissingNoticeTest
 *
 * @covers \MhmCurrencySwitcher\Core\WooCommerceMissingNotice
 */
class WooCommerceMissingNoticeTest extends TestCase {

	/**
	 * Clean up the request-context globals between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['__mhmcs_test_can'],
			$GLOBALS['__mhmcs_test_current_screen']
		);

		parent::tearDown();
	}

	/**
	 * Capture whatever WooCommerceMissingNotice::render() prints.
	 *
	 * @return string
	 */
	private function rendered(): string {
		ob_start();
		WooCommerceMissingNotice::render();

		return (string) ob_get_clean();
	}

	/**
	 * Named to match the brief's Step 1 sample exactly: negative half is a
	 * subscriber (no activate_plugins) seeing nothing, positive half is a
	 * user who has the capability still seeing it.
	 *
	 * @return void
	 */
	public function test_the_woocommerce_missing_notice_needs_activate_plugins(): void {
		$GLOBALS['__mhmcs_test_current_screen'] = 'plugins';

		$GLOBALS['__mhmcs_test_can'] = array( 'activate_plugins' => false );
		$this->assertSame( '', $this->rendered(), 'A user without activate_plugins (e.g. a subscriber) must see nothing.' );

		$GLOBALS['__mhmcs_test_can'] = array( 'activate_plugins' => true );
		$output                      = $this->rendered();
		$this->assertNotSame( '', $output, 'A user WITH activate_plugins must still see the notice — proving it can render at all.' );
		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'is-dismissible', $output );
		$this->assertStringContainsString( 'WooCommerce', $output );
	}

	/**
	 * Screen scope, also two-sided: every screen that exists when
	 * WooCommerce is missing shows it, and an unrelated screen does not.
	 *
	 * @return void
	 */
	public function test_the_notice_is_scoped_to_screens_that_exist_without_woocommerce(): void {
		$GLOBALS['__mhmcs_test_can'] = array( 'activate_plugins' => true );

		foreach ( array( 'plugins', 'dashboard', 'update-core' ) as $screen ) {
			$GLOBALS['__mhmcs_test_current_screen'] = $screen;

			$this->assertNotSame(
				'',
				$this->rendered(),
				"Expected the notice to render on the '{$screen}' screen."
			);
		}

		$GLOBALS['__mhmcs_test_current_screen'] = 'edit-post';

		$this->assertSame(
			'',
			$this->rendered(),
			"The notice must not render on an unrelated screen ('edit-post')."
		);
	}

	/**
	 * `get_current_screen()` returns null before the screen has been set up
	 * — before `admin_init`, or when nothing else in this test process set
	 * one. The notice must treat that as "not visible", not fatal reading
	 * `->id` off nothing.
	 *
	 * @return void
	 */
	public function test_a_null_current_screen_does_not_render_or_fatal(): void {
		$GLOBALS['__mhmcs_test_can'] = array( 'activate_plugins' => true );
		unset( $GLOBALS['__mhmcs_test_current_screen'] );

		$this->assertSame( '', $this->rendered() );
	}

	/**
	 * Exhaustive check of the pure predicate behind render(): every
	 * combination of capability and screen id it has to decide between.
	 *
	 * @return void
	 */
	public function test_is_visible_requires_both_capability_and_an_allowed_screen(): void {
		$this->assertFalse( WooCommerceMissingNotice::is_visible( false, 'plugins' ), 'No capability, allowed screen.' );
		$this->assertFalse( WooCommerceMissingNotice::is_visible( true, null ), 'Capability, no screen.' );
		$this->assertFalse( WooCommerceMissingNotice::is_visible( true, 'edit-post' ), 'Capability, unrelated screen.' );
		$this->assertFalse( WooCommerceMissingNotice::is_visible( false, null ), 'Neither capability nor screen.' );

		foreach ( array( 'plugins', 'dashboard', 'update-core' ) as $screen ) {
			$this->assertTrue( WooCommerceMissingNotice::is_visible( true, $screen ), "Capability, allowed screen '{$screen}'." );
		}
	}
}
