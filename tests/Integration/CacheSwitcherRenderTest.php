<?php
/**
 * Integration tests: the currency switcher's NEUTRAL render on a page a cache
 * may store (design spec §4, implementation plan Task 8).
 *
 * The unit suite exercises Switcher::render_shortcode() directly, handing it a
 * ConversionContext the test built. That proves the renderer's logic and
 * nothing about the wiring — whether Plugin.php actually gave the registered
 * shortcode the request's own shared context. That half is what has broken
 * before in this plugin, and `do_shortcode()` is the only thing that sees it.
 *
 * 🔴 FILE NAME IS LOAD-BEARING, for the same reason spelled out at the top of
 * CacheEnqueueTest and CachePriceMarkerTest: ConversionContextWiringTest
 * defines WOOCOMMERCE_CART, which no PHP process can undefine, so every file
 * sorting after it runs in a permanent money context where nothing is ever
 * rendered neutral. "CacheSwitcherRender" sorts ahead of it.
 *
 * This file exists at all because the first version of these assertions lived
 * in ShortcodeRenderTest, where they SKIPPED — a green suite that measured
 * nothing.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

/**
 * Class CacheSwitcherRenderTest
 */
class CacheSwitcherRenderTest extends MhmcsIntegrationTestCase {

	/**
	 * Plugin settings as found before the test, restored in tear_down().
	 *
	 * @var array<string, mixed>
	 */
	private $saved_settings = array();

	/**
	 * Start every test as a logged-out visitor part-way through rendering a
	 * front-end page — the one context in which a render is cacheable.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$settings             = get_option( 'mhmcs_settings', array() );
		$this->saved_settings = is_array( $settings ) ? $settings : array();

		wp_set_current_user( 0 );
		unset( $_GET['wc-ajax'] );
		$GLOBALS['wp_actions']['wp'] = 1;
	}

	/**
	 * Leave no setting, user or request phase behind.
	 *
	 * @return void
	 */
	public function tear_down() {
		update_option( 'mhmcs_settings', $this->saved_settings );

		wp_set_current_user( 0 );
		unset( $_GET['wc-ajax'], $GLOBALS['wp_actions']['wp'] );

		parent::tear_down();
	}

	/**
	 * The plugin's OWN registered shortcode must render neutral on a page a
	 * cache may store.
	 *
	 * `mhm-cs-active` is the assertion that matters most. A cached page whose
	 * dropdown has the first visitor's currency pre-selected shows that
	 * currency as "active" to every later visitor served the cached copy —
	 * one layer above the Set-Cookie leak that was fixed just before this.
	 *
	 * @return void
	 */
	public function test_registered_shortcode_renders_neutral_on_a_cacheable_page(): void {
		$this->assertMoneyConstantsUndefined();
		$this->set_cache_compat( true );

		$output = do_shortcode( '[mhmcs_currency_switcher]' );

		$this->assertStringContainsString(
			'mhm-cs-switcher',
			$output,
			'Precondition: the shortcode has to render something for the rest of this to mean anything.'
		);
		$this->assertStringNotContainsString( 'data-current', $output );
		$this->assertStringNotContainsString( 'mhm-cs-active', $output );
	}

	/**
	 * The visitor's cookie must not reach the cacheable markup — neither as
	 * an attribute nor through the selected button.
	 *
	 * The button is the leak the implementation plan did not name: it prints
	 * the current option's flag and label, which is visitor-specific state in
	 * cacheable HTML just as much as the two attributes are.
	 *
	 * @return void
	 */
	public function test_visitor_cookie_does_not_reach_the_cacheable_markup(): void {
		$this->assertMoneyConstantsUndefined();
		$this->set_cache_compat( true );

		$this->offer_eur_over_usd();
		$this->set_visitor_currency( 'EUR' );

		$output = do_shortcode( '[mhmcs_currency_switcher]' );

		$this->assertStringContainsString( 'data-currency="EUR"', $output, 'Precondition: EUR is an offered option.' );
		$this->assertStringNotContainsString( 'data-current', $output );
		$this->assertStringNotContainsString( 'mhm-cs-active', $output );

		$matched = preg_match( '#<button class="mhm-cs-selected".*?</button>#s', $output, $button );

		$this->assertSame( 1, $matched, 'Selected button markup not found.' );
		$this->assertStringContainsString( 'USD', $button[0], 'A neutral button shows the base currency.' );
		$this->assertStringNotContainsString( 'EUR', $button[0], 'The visitor\'s currency leaked into the cacheable button.' );
	}

	/**
	 * Control, and the regression lock for every site upgrading from v1.0.0:
	 * with cache compatibility off, nothing about the render is cacheable and
	 * the switcher shows the visitor's currency exactly as it always has.
	 *
	 * Without this pair, "no data-current" would also pass if the attribute
	 * had simply been deleted from the renderer.
	 *
	 * @return void
	 */
	public function test_switcher_still_shows_the_visitor_currency_when_mode_is_off(): void {
		$this->set_cache_compat( false );

		$this->offer_eur_over_usd();
		$this->set_visitor_currency( 'EUR' );

		$output = do_shortcode( '[mhmcs_currency_switcher]' );

		$this->assertStringContainsString( 'data-current="EUR"', $output );
		$this->assertMatchesRegularExpression(
			'/data-currency="EUR"[^>]*class="mhm-cs-option\s+mhm-cs-active"/',
			$output
		);
	}

	/**
	 * A money context is converted server-side and never cached, so the
	 * switcher keeps the visitor's currency there too — with cache
	 * compatibility ON. This is what proves the predicate is the conversion
	 * DECISION and not the `cache_compat` setting.
	 *
	 * @return void
	 */
	public function test_money_context_is_not_rendered_neutral(): void {
		$this->set_cache_compat( true );

		$this->offer_eur_over_usd();
		$this->set_visitor_currency( 'EUR' );

		$_GET['wc-ajax'] = 'get_refreshed_fragments';

		$output = do_shortcode( '[mhmcs_currency_switcher]' );

		$this->assertStringContainsString( 'data-current="EUR"', $output );
	}

	// ─── Helpers ─────────────────────────────────────────────────────

	/**
	 * Offer EUR alongside a USD base, then hand the request back the clean
	 * context a page render starts with.
	 *
	 * 🔴 The reset is not tidying, it is the point. configure_currency() saves
	 * through `POST mhmcs/v1/currencies` as an administrator, and a logged-in
	 * request is decision 6 — "convert" — which LATCHES, one-way, for the rest
	 * of the process. Every later render in this test would then answer
	 * "convert" and nothing would ever be neutral. The first version of these
	 * tests failed exactly this way.
	 *
	 * It is a harness artifact and not a production hazard, and the reason is
	 * worth writing down: in a real request the latch can only arm BEFORE the
	 * body is rendered, and Enqueue::enqueue_assets() asks the same question in
	 * wp_head — earlier still. So a latched request gets both the non-neutral
	 * markup and no converter script, which agree. The test is what can arm the
	 * latch late, because it configures fixtures after set_up().
	 *
	 * @return void
	 */
	private function offer_eur_over_usd(): void {
		$this->configure_currency(
			array(
				'code'    => 'EUR',
				'enabled' => true,
				'rate'    => array(
					'type'  => 'manual',
					'value' => 0.9,
				),
			),
			'USD'
		);

		$this->reset_conversion_context();
	}

	/**
	 * Save the cache-compatibility toggle without disturbing the rest of the
	 * settings.
	 *
	 * @param bool $enabled Whether cache compatibility mode is on.
	 * @return void
	 */
	private function set_cache_compat( bool $enabled ): void {
		$settings = get_option( 'mhmcs_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$settings['cache_compat'] = $enabled;

		update_option( 'mhmcs_settings', $settings );
	}

	/**
	 * Assert that no earlier test has defined WooCommerce's cart/checkout
	 * constants, which cannot be undefined.
	 *
	 * @return void
	 */
	private function assertMoneyConstantsUndefined(): void {
		$this->assertFalse(
			defined( 'WOOCOMMERCE_CART' ) || defined( 'WOOCOMMERCE_CHECKOUT' ),
			'This test asserts cacheable-render behaviour, so it must run before ConversionContextWiringTest defines WOOCOMMERCE_CART for the rest of the process. PHPUnit sorts the files it collects; check that this file name still sorts ahead of ConversionContextWiringTest.php.'
		);
	}
}
