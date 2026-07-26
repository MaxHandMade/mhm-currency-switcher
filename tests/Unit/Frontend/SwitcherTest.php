<?php
/**
 * Unit tests for the Switcher shortcode renderer.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Frontend;

use MhmCurrencySwitcher\Core\ConversionContext;
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

		$this->detection = new DetectionService( $this->store, new ConversionContext() );
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
	 * Build a Switcher over the suite's standard multi-currency store
	 * (base TRY plus enabled USD/EUR alternates — same shape as setUp()),
	 * with the given switcher display settings saved to mhmcs_settings.
	 *
	 * A multi-currency store is deliberate: it is the common production
	 * case, and it exercises both the button markup (label/flag/
	 * data-current, which the show_* toggles control) and the dropdown
	 * <li> options' mandatory data-currency attribute, which frontend JS
	 * (assets/js/switcher.js) reads to know which currency was picked and
	 * therefore must always carry the real ISO code regardless of display
	 * settings.
	 *
	 * @param array<string, mixed> $display_settings Switcher display settings.
	 * @return Switcher
	 */
	private function create_switcher( array $display_settings ): Switcher {
		$store = new CurrencyStore();
		$store->set_data(
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

		$detection = new DetectionService( $store, new ConversionContext() );

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
	 * Turning the flag off must remove flag images from the output —
	 * verified separately in the selected button and in each dropdown
	 * <li>, since those are two independent render paths.
	 *
	 * @return void
	 */
	public function test_render_omits_flags_when_disabled(): void {
		$switcher = $this->create_switcher( array( 'show_flag' => false ) );
		$html     = $switcher->render_shortcode();

		$this->assertStringNotContainsString( 'mhm-cs-flag', $html );

		preg_match( '#<button class="mhm-cs-selected".*?</button>#s', $html, $button_match );
		$this->assertNotEmpty( $button_match, 'Selected button markup not found.' );
		$this->assertStringNotContainsString( 'mhm-cs-flag', $button_match[0] );

		preg_match_all( '#<li role="option".*?</li>#s', $html, $li_matches );
		$this->assertNotEmpty( $li_matches[0], 'Dropdown <li> items not found.' );

		foreach ( $li_matches[0] as $li ) {
			$this->assertStringNotContainsString( 'mhm-cs-flag', $li );
		}
	}

	/**
	 * Turning the code off must hide it from the visible label, but the
	 * machine-readable data-currency attribute (read by
	 * assets/js/switcher.js to perform the switch) must still carry the
	 * real ISO code — the code is hidden from view, not from the markup
	 * the switcher depends on to function.
	 *
	 * @return void
	 */
	public function test_render_omits_code_when_disabled(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'EUR';

		$switcher = $this->create_switcher( array( 'show_code' => false ) );
		$html     = $switcher->render_shortcode();

		preg_match( '#<span class="mhm-cs-label">([^<]*)</span>#', $html, $label_match );
		$this->assertNotEmpty( $label_match, 'Button label markup not found.' );
		$this->assertStringNotContainsString( 'EUR', $label_match[1] );

		$this->assertStringContainsString( 'data-currency="EUR"', $html );
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
	 * An invalid (e.g. typo'd) shortcode `size` attribute is not a valid
	 * override, so it must fall through to the saved setting — same as
	 * omitting the attribute entirely — rather than forcing the
	 * hard-coded 'medium' default over an admin's configured size.
	 *
	 * @return void
	 */
	public function test_render_invalid_shortcode_size_falls_back_to_saved_setting(): void {
		$switcher = $this->create_switcher( array( 'size' => 'large' ) );

		$html = $switcher->render_shortcode( array( 'size' => 'huge' ) );

		$this->assertStringContainsString( 'mhm-cs-size--large', $html );
		$this->assertStringNotContainsString( 'mhm-cs-size--medium', $html );
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

	/**
	 * Regression: before WordPress 6.5, shortcode_parse_atts() returns an
	 * empty string — not array() — when a shortcode is used with no
	 * attributes at all (e.g. bare `[mhm_currency_switcher]`). WordPress
	 * core then calls the registered callback with that string. A native
	 * `array $atts` type hint under strict_types=1 turns this into a fatal
	 * TypeError on every 6.0-6.4 site, for the single most common usage of
	 * the shortcode. The callback must tolerate a non-array argument.
	 *
	 * @return void
	 */
	public function test_render_shortcode_accepts_non_array_atts_pre_wp65(): void {
		$html = $this->switcher->render_shortcode( '' );

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'mhm-cs-switcher', $html );
	}
}
