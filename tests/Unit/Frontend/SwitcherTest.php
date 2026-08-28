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
use MhmCurrencySwitcher\Core\GeolocationService;
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
	 * The conversion context handed to the Switcher built by
	 * create_switcher(), kept so a test can inspect or drive it.
	 *
	 * @var ConversionContext|null
	 */
	private ?ConversionContext $scenario_context = null;

	/**
	 * The detection service handed to the Switcher built by
	 * create_switcher(), kept so a test can wire geolocation into it.
	 *
	 * @var DetectionService|null
	 */
	private ?DetectionService $scenario_detection = null;

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

		$context         = new ConversionContext();
		$this->detection = new DetectionService( $this->store, $context );
		$this->switcher  = new Switcher( $this->store, $this->detection, $context );

		// Ensure clean state.
		unset( $_COOKIE[ DetectionService::COOKIE_NAME ] );
		$this->reset_request_state();
	}

	/**
	 * Clean up superglobals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_COOKIE[ DetectionService::COOKIE_NAME ] );
		$this->reset_request_state();

		parent::tearDown();
	}

	/**
	 * Put the request-context globals back to "nothing special about this
	 * request".
	 *
	 * The saved settings are cleared too. Only the tests below write
	 * `cache_compat`, and a value left behind would silently change which
	 * ConversionContext branch every later test file resolves to — which is
	 * exactly the kind of cross-test leak that makes a lock stop measuring.
	 *
	 * @return void
	 */
	private function reset_request_state(): void {
		unset(
			$GLOBALS['__mhmcs_test_did_actions'],
			$GLOBALS['__mhmcs_test_geo_country'],
			$GLOBALS['__mhmcs_test_geolocate_calls'],
			$GLOBALS['__mhmcs_test_options']['mhmcs_settings']
		);

		$this->scenario_context   = null;
		$this->scenario_detection = null;
	}

	/**
	 * Move the request past the `wp` action.
	 *
	 * Without this, ConversionContext resolves decision 7 (`pre_wp`) and
	 * answers "convert" — the safe-side guess for a read that happens before
	 * the conditional tags exist. Every real shortcode render happens during
	 * body output, i.e. after `wp`, so a test about render-time behaviour has
	 * to say so.
	 *
	 * @return void
	 */
	private function fire_wp(): void {
		$GLOBALS['__mhmcs_test_did_actions']['wp'] = 1;
	}

	/**
	 * Point the geolocation stub at Germany and switch detection on.
	 *
	 * @return void
	 */
	private function geolocate_to_germany(): void {
		$GLOBALS['__mhmcs_test_geo_country']      = 'DE';
		$GLOBALS['__mhmcs_test_geolocate_calls']  = 0;

		if ( null !== $this->scenario_detection ) {
			$this->scenario_detection->set_geolocation( new GeolocationService(), true );
		}
	}

	/**
	 * How many times the WC_Geolocation stub has been asked for a country.
	 *
	 * @return int
	 */
	private function geolocate_calls(): int {
		return isset( $GLOBALS['__mhmcs_test_geolocate_calls'] )
			? (int) $GLOBALS['__mhmcs_test_geolocate_calls']
			: 0;
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
	 * @param array<string, mixed> $extra_settings   Other mhmcs_settings keys
	 *                                               saved alongside them, e.g.
	 *                                               `cache_compat`.
	 * @return Switcher
	 */
	private function create_switcher( array $display_settings, array $extra_settings = array() ): Switcher {
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

		$context   = new ConversionContext();
		$detection = new DetectionService( $store, $context );

		update_option(
			'mhmcs_settings',
			array_merge( $extra_settings, array( 'switcher' => $display_settings ) )
		);

		$this->scenario_context   = $context;
		$this->scenario_detection = $detection;

		return new Switcher( $store, $detection, $context );
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

		$this->assertStringContainsString( 'mhmcs-switcher', $html );
		$this->assertStringContainsString( 'mhmcs-selected', $html );
		$this->assertStringContainsString( 'mhmcs-dropdown', $html );
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
	 * Test that the current currency has the mhmcs-active class.
	 *
	 * @return void
	 */
	public function test_shortcode_marks_current_active(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$html = $this->switcher->render_shortcode();

		// USD option should have the active class.
		$this->assertMatchesRegularExpression(
			'/data-currency="USD"[^>]*class="mhmcs-option\s+mhmcs-active"/',
			$html
		);

		// TRY option should NOT have the active class.
		$this->assertMatchesRegularExpression(
			'/data-currency="TRY"[^>]*class="mhmcs-option"/',
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

		$this->assertStringNotContainsString( 'mhmcs-flag', $html );

		preg_match( '#<button class="mhmcs-selected".*?</button>#s', $html, $button_match );
		$this->assertNotEmpty( $button_match, 'Selected button markup not found.' );
		$this->assertStringNotContainsString( 'mhmcs-flag', $button_match[0] );

		preg_match_all( '#<li role="option".*?</li>#s', $html, $li_matches );
		$this->assertNotEmpty( $li_matches[0], 'Dropdown <li> items not found.' );

		foreach ( $li_matches[0] as $li ) {
			$this->assertStringNotContainsString( 'mhmcs-flag', $li );
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

		preg_match( '#<span class="mhmcs-label">([^<]*)</span>#', $html, $label_match );
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

		$this->assertStringContainsString( 'mhmcs-size--large', $switcher->render_shortcode() );
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

		$this->assertStringContainsString( 'mhmcs-size--large', $html );
		$this->assertStringNotContainsString( 'mhmcs-size--medium', $html );
	}

	/**
	 * Defaults must preserve today's appearance: flag + symbol + code,
	 * no currency name.
	 *
	 * @return void
	 */
	public function test_render_defaults_match_current_appearance(): void {
		$html = $this->create_switcher( array() )->render_shortcode();

		$this->assertStringContainsString( 'mhmcs-flag', $html );
		$this->assertStringContainsString( 'EUR', $html );
		$this->assertStringContainsString( 'mhmcs-size--medium', $html );
	}

	/**
	 * Regression: before WordPress 6.5, shortcode_parse_atts() returns an
	 * empty string — not array() — when a shortcode is used with no
	 * attributes at all (e.g. bare `[mhmcs_currency_switcher]`). WordPress
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
		$this->assertStringContainsString( 'mhmcs-switcher', $html );
	}

	// ---------------------------------------------------------------
	// Neutral render (design spec §4, implementation plan Task 8)
	// ---------------------------------------------------------------

	/**
	 * On a render a page cache may store, the switcher must carry NO trace
	 * of this visitor's currency.
	 *
	 * Three separate leaks, and each of them is enough on its own to serve
	 * the first visitor's currency to everybody afterwards:
	 *
	 * - the `data-current` attribute on the wrapper,
	 * - the `mhmcs-active` class on the matching dropdown option,
	 * - the flag and label printed inside the selected button.
	 *
	 * The button is the one the plan did not name and the one a reader is
	 * most likely to miss: it is visitor-specific state in cacheable HTML
	 * just as much as the two attributes are. In this mode the button shows
	 * the BASE currency and assets/js/switcher.js syncs it from the cookie
	 * on load.
	 *
	 * @return void
	 */
	public function test_cacheable_render_is_neutral(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$switcher = $this->create_switcher( array() );
		$this->fire_wp();

		$html = $switcher->render_shortcode();

		$this->assertStringNotContainsString(
			'data-current',
			$html,
			'A cacheable render must not name the visitor\'s currency on the wrapper.'
		);
		$this->assertStringNotContainsString(
			'mhmcs-active',
			$html,
			'A cacheable render must not pre-select an option.'
		);

		preg_match( '#<button class="mhmcs-selected".*?</button>#s', $html, $button );
		$this->assertNotEmpty( $button, 'Selected button markup not found.' );
		$this->assertStringContainsString(
			'TRY',
			$button[0],
			'A neutral button must show the base currency.'
		);
		$this->assertStringNotContainsString(
			'USD',
			$button[0],
			'The visitor\'s currency leaked into the cacheable button.'
		);
	}

	/**
	 * Control for the test above, and a regression lock on the behaviour
	 * every existing site has today.
	 *
	 * When the server converts — here because cache compatibility is off —
	 * nothing about this render is cacheable, so the switcher keeps showing
	 * the visitor's currency exactly as it did in v1.0.0. Without this pair,
	 * "no data-current" would also pass if the attribute had simply been
	 * deleted from the renderer.
	 *
	 * @return void
	 */
	public function test_converting_render_keeps_the_visitor_state(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$switcher = $this->create_switcher( array(), array( 'cache_compat' => false ) );
		$this->fire_wp();

		$html = $switcher->render_shortcode();

		$this->assertStringContainsString( 'data-current="USD"', $html );
		$this->assertMatchesRegularExpression(
			'/data-currency="USD"[^>]*class="mhmcs-option\s+mhmcs-active"/',
			$html
		);
	}

	/**
	 * The neutral path must not ASK the detection service at all.
	 *
	 * Not "asks and ignores the answer": the lookup itself is the cost. With
	 * auto-detect on, every page carrying a switcher paid a geolocation
	 * lookup for a value the markup is then forbidden to print. The
	 * WC_Geolocation stub counts invocations, which is the only way to tell
	 * "never ran" from "ran and was overruled".
	 *
	 * @return void
	 */
	public function test_cacheable_render_does_not_consult_geolocation(): void {
		$switcher = $this->create_switcher( array() );
		$this->geolocate_to_germany();
		$this->fire_wp();

		$html = $switcher->render_shortcode();

		$this->assertSame(
			0,
			$this->geolocate_calls(),
			'A cacheable render must not run geolocation.'
		);
		$this->assertStringNotContainsString( 'data-current', $html );
	}

	/**
	 * Control for the test above: the very same setup DOES geolocate once
	 * when the server is converting. Without it, "0 lookups" could just mean
	 * the stub was never wired up.
	 *
	 * @return void
	 */
	public function test_converting_render_does_consult_geolocation(): void {
		$switcher = $this->create_switcher( array(), array( 'cache_compat' => false ) );
		$this->geolocate_to_germany();
		$this->fire_wp();

		$html = $switcher->render_shortcode();

		$this->assertSame( 1, $this->geolocate_calls() );
		$this->assertStringContainsString( 'data-current="EUR"', $html );
	}

	/**
	 * 🔴 A currency the plugin cannot convert into must not be offered.
	 *
	 * `build_options_list()` asked `get_enabled_currencies()` and stopped
	 * there. A currency that is enabled but has no usable rate — a rate of
	 * zero, which the panel stores without a word when the field is cleared,
	 * or a fee that cancels the rate out — stayed in the dropdown.
	 *
	 * Choosing it does nothing at all: `Converter::convert()` returns the base
	 * amount and `FormatFilter` keeps the base symbol, so every price on the
	 * page stays exactly as it was. The visitor clicks a currency, the page
	 * reloads, and nothing changes, with no explanation anywhere. Measured in
	 * a real browser on the development stack before this test was written.
	 *
	 * @return void
	 */
	public function test_a_currency_with_an_unusable_rate_is_not_offered(): void {
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
						'type'  => 'none',
						'value' => 0,
					),
					'format'  => array( 'symbol' => '$' ),
				),
				array(
					'code'    => 'EUR',
					'enabled' => true,
					'rate'    => array(
						'type'  => 'manual',
						'value' => 0,
					),
					'fee'     => array(
						'type'  => 'none',
						'value' => 0,
					),
					'format'  => array( 'symbol' => "\u{20AC}" ),
				),
			)
		);

		$context   = new ConversionContext();
		$detection = new DetectionService( $store, $context );
		$switcher  = new Switcher( $store, $detection, $context );

		$html = $switcher->render_shortcode();

		$this->assertStringNotContainsString(
			'data-currency="EUR"',
			$html,
			'A currency with no usable rate is still offered; choosing it changes nothing on the page and says nothing about why.'
		);

		// The control: the currency that CAN convert must still be offered,
		// and so must the base. Dropping everything is not the fix.
		$this->assertStringContainsString( 'data-currency="USD"', $html, 'The usable currency stopped being offered.' );
		$this->assertStringContainsString( 'data-currency="TRY"', $html, 'The base currency stopped being offered.' );
	}
}
