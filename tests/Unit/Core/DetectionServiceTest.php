<?php
/**
 * Unit tests for DetectionService.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;
use PHPUnit\Framework\TestCase;

/**
 * Class DetectionServiceTest
 *
 * Pure unit tests — no WordPress dependency.
 * Manipulates the $_COOKIE superglobal and the get_query_var() stub
 * ($GLOBALS['__mhmcs_test_query_vars']) directly.
 *
 * Setup: CurrencyStore with TRY base, USD (enabled), EUR (enabled).
 *
 * @covers \MhmCurrencySwitcher\Core\DetectionService
 */
class DetectionServiceTest extends TestCase {

	/**
	 * Currency data store.
	 *
	 * @var CurrencyStore
	 */
	private CurrencyStore $store;

	/**
	 * Detection service instance under test.
	 *
	 * @var DetectionService
	 */
	private DetectionService $service;

	/**
	 * Set up the store and service with TRY base, USD + EUR enabled.
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
					'code'            => 'USD',
					'enabled'         => true,
					'sort_order'      => 0,
					'rate'            => array(
						'type'  => 'manual',
						'value' => 0.03,
					),
					'fee'             => array(
						'type'  => 'fixed',
						'value' => 0,
					),
					'rounding'        => array(
						'type'     => 'disabled',
						'value'    => 0,
						'subtract' => 0,
					),
					'format'          => array(
						'symbol'       => '$',
						'position'     => 'left',
						'thousand_sep' => ',',
						'decimal_sep'  => '.',
						'decimals'     => 2,
					),
					'payment_methods' => array( 'all' ),
					'countries'       => array(),
				),
				array(
					'code'            => 'EUR',
					'enabled'         => true,
					'sort_order'      => 1,
					'rate'            => array(
						'type'  => 'manual',
						'value' => 0.025,
					),
					'fee'             => array(
						'type'  => 'fixed',
						'value' => 0,
					),
					'rounding'        => array(
						'type'     => 'disabled',
						'value'    => 0,
						'subtract' => 0,
					),
					'format'          => array(
						'symbol'       => "\u{20AC}",
						'position'     => 'right',
						'thousand_sep' => '.',
						'decimal_sep'  => ',',
						'decimals'     => 2,
					),
					'payment_methods' => array( 'all' ),
					'countries'       => array(),
				),
			)
		);

		$this->service = new DetectionService( $this->store );

		// Ensure clean state.
		unset( $_COOKIE[ DetectionService::COOKIE_NAME ] );
		unset( $GLOBALS['__mhmcs_test_query_vars'][ DetectionService::URL_PARAM ] );
	}

	/**
	 * Clean up superglobals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_COOKIE[ DetectionService::COOKIE_NAME ] );
		unset( $GLOBALS['__mhmcs_test_query_vars'][ DetectionService::URL_PARAM ] );

		parent::tearDown();
	}

	/**
	 * Test that base currency is returned when no cookie and no URL param.
	 *
	 * @return void
	 */
	public function test_returns_base_when_no_cookie_no_param(): void {
		$this->assertSame( 'TRY', $this->service->get_current_currency() );
	}

	/**
	 * Test that currency is detected from cookie.
	 *
	 * @return void
	 */
	public function test_returns_currency_from_cookie(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$this->assertSame( 'USD', $this->service->get_current_currency() );
	}

	/**
	 * Test that currency is detected from URL parameter when enabled.
	 *
	 * @return void
	 */
	public function test_returns_currency_from_url_param(): void {
		$this->service->set_url_param_enabled( true );

		$GLOBALS['__mhmcs_test_query_vars'][ DetectionService::URL_PARAM ] = 'EUR';

		$this->assertSame( 'EUR', $this->service->get_current_currency() );
	}

	/**
	 * Test that cookie takes priority over URL parameter.
	 *
	 * @return void
	 */
	public function test_cookie_takes_priority_over_url_param(): void {
		$this->service->set_url_param_enabled( true );

		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$GLOBALS['__mhmcs_test_query_vars'][ DetectionService::URL_PARAM ] = 'EUR';

		$this->assertSame( 'USD', $this->service->get_current_currency() );
	}

	/**
	 * Test that an invalid currency code in the cookie is ignored.
	 *
	 * @return void
	 */
	public function test_ignores_invalid_currency_in_cookie(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'INVALID';

		$this->assertSame( 'TRY', $this->service->get_current_currency() );
	}

	/**
	 * Test that a disabled currency in the cookie is ignored.
	 *
	 * @return void
	 */
	public function test_ignores_disabled_currency(): void {
		// Re-create store with EUR disabled.
		$store = new CurrencyStore();
		$store->set_data(
			'TRY',
			array(
				array(
					'code'            => 'USD',
					'enabled'         => true,
					'sort_order'      => 0,
					'rate'            => array(
						'type'  => 'manual',
						'value' => 0.03,
					),
					'fee'             => array(
						'type'  => 'fixed',
						'value' => 0,
					),
					'rounding'        => array(
						'type'     => 'disabled',
						'value'    => 0,
						'subtract' => 0,
					),
					'format'          => array(
						'symbol'       => '$',
						'position'     => 'left',
						'thousand_sep' => ',',
						'decimal_sep'  => '.',
						'decimals'     => 2,
					),
					'payment_methods' => array( 'all' ),
					'countries'       => array(),
				),
				array(
					'code'            => 'EUR',
					'enabled'         => false,
					'sort_order'      => 1,
					'rate'            => array(
						'type'  => 'manual',
						'value' => 0.025,
					),
					'fee'             => array(
						'type'  => 'fixed',
						'value' => 0,
					),
					'rounding'        => array(
						'type'     => 'disabled',
						'value'    => 0,
						'subtract' => 0,
					),
					'format'          => array(
						'symbol'       => "\u{20AC}",
						'position'     => 'right',
						'thousand_sep' => '.',
						'decimal_sep'  => ',',
						'decimals'     => 2,
					),
					'payment_methods' => array( 'all' ),
					'countries'       => array(),
				),
			)
		);

		$service = new DetectionService( $store );

		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'EUR';

		$this->assertSame( 'TRY', $service->get_current_currency() );
	}

	/**
	 * Test that is_base_currency returns true when no cookie or param is set.
	 *
	 * @return void
	 */
	public function test_is_base_currency_returns_true(): void {
		$this->assertTrue( $this->service->is_base_currency() );
	}

	/**
	 * Test that add_query_var() appends the currency public query var.
	 *
	 * @return void
	 */
	public function test_add_query_var_appends_currency(): void {
		$vars = $this->service->add_query_var( array( 'existing' ) );

		$this->assertContains( DetectionService::URL_PARAM, $vars );
		$this->assertContains( 'existing', $vars );
	}

	/**
	 * Test that URL param is ignored when the query var is absent.
	 *
	 * @return void
	 */
	public function test_ignores_url_param_when_query_var_absent(): void {
		$this->service->set_url_param_enabled( true );

		// No query var set — should fall back to base currency.
		$this->assertSame( 'TRY', $this->service->get_current_currency() );
	}
}
