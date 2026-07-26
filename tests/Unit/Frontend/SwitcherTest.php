<?php
/**
 * Unit tests for the Switcher shortcode renderer.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Frontend;

use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;
use MhmCurrencySwitcher\Frontend\Switcher;
use PHPUnit\Framework\TestCase;

/**
 * Class SwitcherTest
 *
 * Pure unit tests — no WordPress dependency.
 * Tests the shortcode rendering logic directly.
 *
 * Setup:
 *   Base: TRY
 *   USD: enabled, symbol=$
 *   EUR: enabled, symbol=€
 *
 * @covers \MhmCurrencySwitcher\Frontend\Switcher
 */
class SwitcherTest extends TestCase {

	/**
	 * Currency data store.
	 *
	 * @var CurrencyStore
	 */
	private CurrencyStore $store;

	/**
	 * Detection service.
	 *
	 * @var DetectionService
	 */
	private DetectionService $detection;

	/**
	 * Switcher instance under test.
	 *
	 * @var Switcher
	 */
	private Switcher $switcher;

	/**
	 * Set up store, detection, and switcher instances.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->store = new CurrencyStore();
		$this->store->set_data(
			'TRY',
			array(
				array(
					'code'    => 'USD',
					'enabled' => true,
					'rate'    => array(
						'type'  => 'manual',
						'value' => 0.03,
					),
					'fee'     => array(
						'type'  => 'fixed',
						'value' => 0,
					),
					'format'  => array(
						'symbol'       => '$',
						'position'     => 'left',
						'thousand_sep' => ',',
						'decimal_sep'  => '.',
						'decimals'     => 2,
					),
				),
				array(
					'code'    => 'EUR',
					'enabled' => true,
					'rate'    => array(
						'type'  => 'manual',
						'value' => 0.025,
					),
					'fee'     => array(
						'type'  => 'fixed',
						'value' => 0,
					),
					'format'  => array(
						'symbol'       => "\u{20AC}",
						'position'     => 'right',
						'thousand_sep' => '.',
						'decimal_sep'  => ',',
						'decimals'     => 2,
					),
				),
			)
		);

		$this->detection = new DetectionService( $this->store );
		$this->switcher  = new Switcher( $this->store, $this->detection );

		// Ensure clean state.
		unset( $_COOKIE[ DetectionService::COOKIE_NAME ] );
	}

	/**
	 * Clean up superglobals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_COOKIE[ DetectionService::COOKIE_NAME ] );

		parent::tearDown();
	}

	/**
	 * Build a Switcher over a single-currency (EUR-base, no alternates)
	 * store, following the same construction pattern as setUp(), with
	 * the given switcher display settings saved to mhmcs_settings.
	 *
	 * A single currency (nothing to switch to) is deliberate: it isolates
	 * the button's own label/flag/data-current markup — which the
	 * show_* toggles control — from the dropdown <li> options' mandatory
	 * data-currency attribute, which frontend JS (assets/js/switcher.js)
	 * reads to know which currency was picked and therefore must always
	 * carry the real ISO code regardless of display settings.
	 *
	 * @param array<string, mixed> $display_settings Switcher display settings.
	 * @return Switcher
	 */
	private function create_switcher( array $display_settings ): Switcher {
		$store = new CurrencyStore();
		$store->set_data( 'EUR', array() );

		$detection = new DetectionService( $store );

		update_option( 'mhmcs_settings', array( 'switcher' => $display_settings ) );

		return new Switcher( $store, $detection );
	}

	// ---------------------------------------------------------------
	// Tests
	// ---------------------------------------------------------------

	/**
	 * Test that render_shortcode returns HTML containing the switcher class.
	 *
	 * @return void
	 */
	public function test_shortcode_renders_html(): void {
		$html = $this->switcher->render_shortcode();

		$this->assertStringContainsString( 'mhm-cs-switcher', $html );
		$this->assertStringContainsString( 'mhm-cs-selected', $html );
		$this->assertStringContainsString( 'mhm-cs-dropdown', $html );
	}

	/**
	 * Test that the output includes USD and EUR data attributes.
	 *
	 * @return void
	 */
	public function test_shortcode_includes_enabled_currencies(): void {
		$html = $this->switcher->render_shortcode();

		$this->assertStringContainsString( 'data-currency="USD"', $html );
		$this->assertStringContainsString( 'data-currency="EUR"', $html );
	}

	/**
	 * Test that the current currency has the mhm-cs-active class.
	 *
	 * @return void
	 */
	public function test_shortcode_marks_current_active(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$html = $this->switcher->render_shortcode();

		// USD option should have the active class.
		$this->assertMatchesRegularExpression(
			'/data-currency="USD"[^>]*class="mhm-cs-option\s+mhm-cs-active"/',
			$html
		);

		// TRY option should NOT have the active class.
		$this->assertMatchesRegularExpression(
			'/data-currency="TRY"[^>]*class="mhm-cs-option"/',
			$html
		);
	}

	/**
	 * Test that the base currency TRY appears in the dropdown.
	 *
	 * @return void
	 */
	public function test_shortcode_includes_base_currency(): void {
		$html = $this->switcher->render_shortcode();

		$this->assertStringContainsString( 'data-currency="TRY"', $html );
	}

	/**
	 * Turning the flag off must remove flag images from the output.
	 *
	 * @return void
	 */
	public function test_render_omits_flags_when_disabled(): void {
		$switcher = $this->create_switcher( array( 'show_flag' => false ) );

		$this->assertStringNotContainsString( 'mhm-cs-flag', $switcher->render_shortcode() );
	}

	/**
	 * Turning the code off must remove the ISO code from the label.
	 *
	 * @return void
	 */
	public function test_render_omits_code_when_disabled(): void {
		$switcher = $this->create_switcher( array( 'show_code' => false ) );

		$this->assertStringNotContainsString( 'EUR', $switcher->render_shortcode() );
	}

	/**
	 * The saved size must reach the wrapper CSS class.
	 *
	 * @return void
	 */
	public function test_render_applies_saved_size(): void {
		$switcher = $this->create_switcher( array( 'size' => 'large' ) );

		$this->assertStringContainsString( 'mhm-cs-size--large', $switcher->render_shortcode() );
	}

	/**
	 * Defaults must preserve today's appearance: flag + symbol + code,
	 * no currency name.
	 *
	 * @return void
	 */
	public function test_render_defaults_match_current_appearance(): void {
		$html = $this->create_switcher( array() )->render_shortcode();

		$this->assertStringContainsString( 'mhm-cs-flag', $html );
		$this->assertStringContainsString( 'EUR', $html );
		$this->assertStringContainsString( 'mhm-cs-size--medium', $html );
	}
}
