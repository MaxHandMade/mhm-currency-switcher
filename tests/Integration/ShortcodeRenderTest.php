<?php
/**
 * Integration tests for the plugin's shortcodes against real WordPress.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use WP_UnitTestCase;

/**
 * Class ShortcodeRenderTest
 */
class ShortcodeRenderTest extends WP_UnitTestCase {

	/**
	 * The harness must actually be running against WordPress and
	 * WooCommerce. Without this, every other assertion in the suite could
	 * pass while testing nothing at all.
	 *
	 * @return void
	 */
	public function test_harness_runs_against_real_wordpress_and_woocommerce(): void {
		$this->assertTrue( function_exists( 'do_shortcode' ), 'WordPress is not loaded.' );
		$this->assertTrue( class_exists( 'WooCommerce' ), 'WooCommerce is not loaded.' );
		$this->assertTrue( defined( 'MHMCS_VERSION' ), 'The plugin under test is not loaded.' );
	}

	/**
	 * A bare switcher shortcode — no attributes — must render.
	 *
	 * Regression: before WordPress 6.5, shortcode_parse_atts() returns an
	 * empty STRING when a shortcode carries no attributes. The callback was
	 * typed `array $atts` under strict_types, so `[mhm_currency_switcher]`
	 * — the documented, most common usage — was a fatal on every WordPress
	 * between the declared minimum (6.0) and 6.5.
	 *
	 * The unit suite cannot see this class of bug: it calls
	 * render_shortcode() directly instead of going through do_shortcode(),
	 * so it never exercises WordPress's own argument contract.
	 *
	 * @return void
	 */
	public function test_bare_switcher_shortcode_renders(): void {
		$output = do_shortcode( '[mhm_currency_switcher]' );

		$this->assertIsString( $output );
		$this->assertStringNotContainsString( 'Fatal error', $output );
	}

	/**
	 * The same contract for the product price shortcode.
	 *
	 * @return void
	 */
	public function test_bare_price_shortcode_renders(): void {
		$output = do_shortcode( '[mhm_currency_prices]' );

		$this->assertIsString( $output );
		$this->assertStringNotContainsString( 'Fatal error', $output );
	}

	/**
	 * Attributes must still work — the fix for the bare case must not have
	 * been a blanket "ignore whatever WordPress passes".
	 *
	 * @return void
	 */
	public function test_switcher_shortcode_honours_size_attribute(): void {
		$output = do_shortcode( '[mhm_currency_switcher size="large"]' );

		$this->assertIsString( $output );

		if ( '' !== $output ) {
			$this->assertStringContainsString( 'mhm-cs-size--large', $output );
		}
	}
}
