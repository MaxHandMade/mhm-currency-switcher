<?php
/**
 * Unit tests for License\Mode.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\License
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\License;

use MhmCurrencySwitcher\License\LicenseManager;
use MhmCurrencySwitcher\License\Mode;
use PHPUnit\Framework\TestCase;

/**
 * Class ModeTest
 *
 * @covers \MhmCurrencySwitcher\License\Mode
 * @covers \MhmCurrencySwitcher\License\LicenseManager
 */
class ModeTest extends TestCase {

	/**
	 * Reset the LicenseManager singleton before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		LicenseManager::reset();
	}

	/**
	 * Test that is_lite returns true by default (no license).
	 *
	 * @return void
	 */
	public function test_is_lite_by_default(): void {
		$this->assertTrue( Mode::is_lite() );
	}

	/**
	 * Test that is_pro returns false by default (no license).
	 *
	 * @return void
	 */
	public function test_is_pro_returns_false_by_default(): void {
		$this->assertFalse( Mode::is_pro() );
	}

	/**
	 * Test that get_currency_limit returns 2 in Lite mode.
	 *
	 * @return void
	 */
	public function test_get_currency_limit_lite(): void {
		$this->assertSame( 2, Mode::get_currency_limit() );
	}

	/**
	 * Test that all feature gates return false in Lite mode.
	 *
	 * @return void
	 */
	public function test_feature_gates_return_false_lite(): void {
		$this->assertFalse( Mode::can_use_geolocation() );
		$this->assertFalse( Mode::can_use_fixed_prices() );
		$this->assertFalse( Mode::can_use_payment_restrictions() );
		$this->assertFalse( Mode::can_use_auto_rate_update() );
		$this->assertFalse( Mode::can_use_multilingual() );
		$this->assertFalse( Mode::can_use_rest_api_filter() );
	}

	/**
	 * Test that MHM_CS_DEV_PRO constant enables Pro mode.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_dev_pro_constant_override(): void {
		if ( ! defined( 'MHM_CS_DEV_PRO' ) ) {
			define( 'MHM_CS_DEV_PRO', true );
		}

		LicenseManager::reset();

		$this->assertTrue( Mode::is_pro() );
		$this->assertFalse( Mode::is_lite() );
		$this->assertSame( PHP_INT_MAX, Mode::get_currency_limit() );
		$this->assertTrue( Mode::can_use_geolocation() );
	}
}
