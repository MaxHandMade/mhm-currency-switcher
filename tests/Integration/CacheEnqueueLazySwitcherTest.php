<?php
/**
 * Integration tests: switcher.js loads only where a switcher is rendered,
 * on pages where the client does not convert.
 *
 * 🔴 FILE NAME IS LOAD-BEARING, like CacheEnqueueTest's: it must sort ahead
 * of ConversionContextWiringTest, which defines WOOCOMMERCE_CART for the rest
 * of the process.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use MhmCurrencySwitcher\Frontend\Enqueue;
use MhmCurrencySwitcher\Frontend\Switcher;

/**
 * switcher.js used to be enqueued on every front-end page. Where the server
 * converts (cache compatibility off, a logged-in visitor, cart and checkout)
 * nothing needs it unless a switcher is on the page, so it now waits for one
 * to render. Its registration and configuration stay unconditional: the
 * converter depends on that handle wherever IT loads, and the rendered
 * switcher only has to say "I am here".
 *
 * Three orders are covered, because each one happens on real sites:
 * rendered AFTER the enqueue pass (a body widget or menu — the common case),
 * rendered BEFORE it (output buffered early by a theme or page builder), and
 * rendered after the footer scripts have printed (a switcher injected late in
 * wp_footer), where enqueueing would be too late and the script is printed in
 * place instead.
 *
 * Stylesheets are deliberately NOT lazy: a header switcher renders near the
 * top of the body, and a stylesheet enqueued there is printed in the footer,
 * so every currency would flash as an unstyled, expanded list until it loads.
 */
class CacheEnqueueLazySwitcherTest extends MhmcsIntegrationTestCase {

	/**
	 * Settings before the test.
	 *
	 * @var array<string, mixed>
	 */
	private $saved_settings = array();

	/**
	 * Logged-out visitor, render phase, fresh script registry, fresh latch.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$settings             = get_option( 'mhmcs_settings', array() );
		$this->saved_settings = is_array( $settings ) ? $settings : array();

		wp_set_current_user( 0 );
		$GLOBALS['wp_actions']['wp'] = 1;
		$GLOBALS['wp_scripts']       = null;
		$this->reset_render_flag();
	}

	/**
	 * Restore.
	 *
	 * @return void
	 */
	public function tear_down() {
		update_option( 'mhmcs_settings', $this->saved_settings );
		wp_set_current_user( 0 );
		unset( $GLOBALS['wp_actions']['wp'], $GLOBALS['wp_actions']['wp_print_footer_scripts'] );
		$GLOBALS['wp_scripts'] = null;
		$this->reset_render_flag();

		parent::tear_down();
	}

	/**
	 * Put the server in charge of conversion: cache compatibility off.
	 *
	 * @return void
	 */
	private function server_converts(): void {
		$settings                 = $this->saved_settings;
		$settings['cache_compat'] = false;
		update_option( 'mhmcs_settings', $settings );
	}

	/**
	 * Run the front-end enqueue pass.
	 *
	 * @return void
	 */
	private function run_enqueue(): void {
		do_action( 'wp_enqueue_scripts' );
	}

	/**
	 * Render a switcher the way a page does.
	 *
	 * @return string
	 */
	private function render_switcher(): string {
		$html = do_shortcode( '[mhmcs_currency_switcher]' );
		$this->assertStringContainsString( 'mhmcs-switcher', $html, 'Precondition: a switcher rendered.' );

		return $html;
	}

	/**
	 * Clear Switcher's "a switcher rendered on this request" flag. It is
	 * process-static by design (one request = one flag), so tests must reset
	 * it; reflection keeps a test-only reset out of the production API.
	 *
	 * @return void
	 */
	private function reset_render_flag(): void {
		$property = new \ReflectionProperty( Switcher::class, 'rendered' );
		$property->setAccessible( true );
		$property->setValue( null, false );
	}

	/**
	 * Whether switcher.js is in the queue.
	 *
	 * @return bool
	 */
	private function switcher_enqueued(): bool {
		return wp_script_is( Enqueue::SWITCHER_HANDLE, 'enqueued' );
	}

	/**
	 * Where the server converts and no switcher renders, switcher.js stays out
	 * — but registered, with its configuration, ready for one.
	 *
	 * @return void
	 */
	public function test_no_switcher_on_a_server_converted_page_loads_no_switcher_script(): void {
		$this->server_converts();
		$this->run_enqueue();

		$this->assertFalse( $this->switcher_enqueued() );
		$this->assertTrue( wp_script_is( Enqueue::SWITCHER_HANDLE, 'registered' ) );
		$this->assertNotEmpty( wp_scripts()->get_data( Enqueue::SWITCHER_HANDLE, 'data' ), 'The configuration must still be attached to the registered handle.' );
	}

	/**
	 * The common order: enqueue pass in wp_head, switcher in the body.
	 *
	 * @return void
	 */
	public function test_a_switcher_rendered_after_the_enqueue_pass_loads_its_script(): void {
		$this->server_converts();
		$this->run_enqueue();
		$this->assertFalse( $this->switcher_enqueued(), 'Precondition: not loaded before the switcher renders.' );

		$this->render_switcher();

		$this->assertTrue( $this->switcher_enqueued() );
	}

	/**
	 * A switcher rendered before wp_enqueue_scripts (early output buffering).
	 *
	 * @return void
	 */
	public function test_a_switcher_rendered_before_the_enqueue_pass_loads_its_script(): void {
		$this->server_converts();
		$this->render_switcher();

		$this->run_enqueue();

		$this->assertTrue( $this->switcher_enqueued() );
	}

	/**
	 * A switcher rendered after the footer scripts printed: too late to
	 * enqueue, so the script is printed with the switcher.
	 *
	 * @return void
	 */
	public function test_a_switcher_rendered_after_the_footer_scripts_prints_its_script_in_place(): void {
		$this->server_converts();
		$this->run_enqueue();
		$GLOBALS['wp_actions']['wp_print_footer_scripts'] = 1;

		ob_start();
		$this->render_switcher();
		$printed = (string) ob_get_clean();

		$this->assertStringContainsString( 'assets/js/switcher.js', $printed );
		$this->assertStringContainsString( 'mhmcsData', $printed, 'The configuration must be printed with it.' );
	}

	/**
	 * Where the client converts, switcher.js loads regardless: the converter
	 * depends on it. Unchanged behaviour, locked here next to the new one.
	 *
	 * @return void
	 */
	public function test_a_client_converting_page_loads_the_switcher_script_without_a_switcher(): void {
		$this->assertFalse( defined( 'WOOCOMMERCE_CART' ) || defined( 'WOOCOMMERCE_CHECKOUT' ), 'Must run before ConversionContextWiringTest; check the file name sorts first.' );

		$this->run_enqueue();

		$this->assertTrue( wp_script_is( Enqueue::CONVERTER_HANDLE, 'enqueued' ), 'Precondition: this is a client-converting page.' );
		$this->assertTrue( $this->switcher_enqueued() );
	}

	/**
	 * The stylesheets stay site-wide (see the class docblock).
	 *
	 * @return void
	 */
	public function test_stylesheets_are_not_lazy(): void {
		$this->server_converts();
		$this->run_enqueue();

		$this->assertTrue( wp_style_is( 'mhmcs-switcher', 'enqueued' ) );
	}
}
