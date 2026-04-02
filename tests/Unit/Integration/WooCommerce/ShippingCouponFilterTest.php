<?php
/**
 * Unit tests for ShippingFilter and CouponFilter.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Integration\WooCommerce
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Integration\WooCommerce;

use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;
use MhmCurrencySwitcher\Integration\WooCommerce\CouponFilter;
use MhmCurrencySwitcher\Integration\WooCommerce\ShippingFilter;
use PHPUnit\Framework\TestCase;

/**
 * Class ShippingCouponFilterTest
 *
 * Pure unit tests — no WordPress or WooCommerce dependency.
 * Tests shipping rate conversion and coupon amount conversion
 * using simple stub objects.
 *
 * Setup:
 *   Base: TRY
 *   USD: rate=0.03, fee=percentage 2% → effective rate 0.0306
 *
 * @covers \MhmCurrencySwitcher\Integration\WooCommerce\ShippingFilter
 * @covers \MhmCurrencySwitcher\Integration\WooCommerce\CouponFilter
 */
class ShippingCouponFilterTest extends TestCase {

	/**
	 * Currency data store.
	 *
	 * @var CurrencyStore
	 */
	private CurrencyStore $store;

	/**
	 * Price converter.
	 *
	 * @var Converter
	 */
	private Converter $converter;

	/**
	 * Detection service.
	 *
	 * @var DetectionService
	 */
	private DetectionService $detection;

	/**
	 * Shipping filter instance under test.
	 *
	 * @var ShippingFilter
	 */
	private ShippingFilter $shipping_filter;

	/**
	 * Coupon filter instance under test.
	 *
	 * @var CouponFilter
	 */
	private CouponFilter $coupon_filter;

	/**
	 * Set up store, converter, detection, and filter instances.
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
						'type'  => 'percentage',
						'value' => 2,
					),
					'rounding' => array(
						'type'     => 'disabled',
						'value'    => 0,
						'subtract' => 0,
					),
					'format'  => array(
						'symbol'       => '$',
						'position'     => 'left',
						'thousand_sep' => ',',
						'decimal_sep'  => '.',
						'decimals'     => 2,
					),
				),
			)
		);

		$this->converter       = new Converter( $this->store );
		$this->detection       = new DetectionService( $this->store );
		$this->shipping_filter = new ShippingFilter( $this->converter, $this->detection );
		$this->coupon_filter   = new CouponFilter( $this->converter, $this->detection );

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
	// ShippingFilter tests
	// ---------------------------------------------------------------

	/**
	 * Test that convert_shipping_rates converts costs for non-base currency.
	 *
	 * Shipping cost of 100 TRY with USD selected:
	 * effective rate = 0.03 * 1.02 = 0.0306
	 * converted = 100 * 0.0306 = 3.06
	 *
	 * @return void
	 */
	public function test_convert_shipping_rates_converts_cost(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$rate       = new \stdClass();
		$rate->cost = 100.0;

		$rates  = array( 'flat_rate:1' => $rate );
		$result = $this->shipping_filter->convert_shipping_rates( $rates, array() );

		$this->assertEqualsWithDelta( 3.06, $result['flat_rate:1']->cost, 0.01 );
	}

	/**
	 * Test that shipping rates remain unchanged for base currency.
	 *
	 * No cookie → TRY (base) → cost unchanged.
	 *
	 * @return void
	 */
	public function test_shipping_rates_unchanged_for_base(): void {
		// No cookie → base currency TRY.
		$rate       = new \stdClass();
		$rate->cost = 100.0;

		$rates  = array( 'flat_rate:1' => $rate );
		$result = $this->shipping_filter->convert_shipping_rates( $rates, array() );

		$this->assertSame( 100.0, $result['flat_rate:1']->cost );
	}

	// ---------------------------------------------------------------
	// CouponFilter tests
	// ---------------------------------------------------------------

	/**
	 * Test that fixed_cart coupon amount is converted.
	 *
	 * Coupon amount of 500 TRY with USD selected:
	 * converted = 500 * 0.0306 = 15.3
	 *
	 * @return void
	 */
	public function test_coupon_fixed_amount_converted(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$coupon = new class() {
			/**
			 * Get discount type.
			 *
			 * @return string
			 */
			public function get_discount_type(): string {
				return 'fixed_cart';
			}
		};

		$result = $this->coupon_filter->convert_coupon_amount( 500.0, $coupon );

		$this->assertEqualsWithDelta( 15.3, $result, 0.01 );
	}

	/**
	 * Test that percentage coupon amount is NOT converted.
	 *
	 * Percentage discounts are currency-agnostic — 10% is 10% in any currency.
	 *
	 * @return void
	 */
	public function test_coupon_percentage_not_converted(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$coupon = new class() {
			/**
			 * Get discount type.
			 *
			 * @return string
			 */
			public function get_discount_type(): string {
				return 'percent';
			}
		};

		$result = $this->coupon_filter->convert_coupon_amount( 10.0, $coupon );

		$this->assertSame( 10.0, $result );
	}

	/**
	 * Test that coupon min/max thresholds are converted.
	 *
	 * Min amount of 1000 TRY with USD selected:
	 * converted = 1000 * 0.0306 = 30.6
	 *
	 * @return void
	 */
	public function test_coupon_min_max_converted(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$coupon = new class() {
			/**
			 * Get discount type.
			 *
			 * @return string
			 */
			public function get_discount_type(): string {
				return 'percent';
			}
		};

		$result = $this->coupon_filter->convert_min_max_amount( 1000.0, $coupon );

		$this->assertEqualsWithDelta( 30.6, $result, 0.01 );
	}
}
