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
}
