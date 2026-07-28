<?php
/**
 * Rounding reaches every amount that makes up the cart total.
 *
 * The shop owner sets one rounding rule per currency. It was applied to product
 * prices and to nothing else, so a cart whose line items were tidy round numbers
 * still came to a total with unrounded cents in it — the shipping, the fees and
 * any fixed discount had all been converted straight through.
 *
 * The one deliberate exception is a coupon's minimum/maximum spend, and it is
 * asserted here too so that leaving it out stays a decision rather than becoming
 * the next report of this same defect.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Integration\WooCommerce
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Integration\WooCommerce;

use MhmCurrencySwitcher\Core\ConversionContext;
use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;
use MhmCurrencySwitcher\Integration\WooCommerce\CartFilter;
use MhmCurrencySwitcher\Integration\WooCommerce\CouponFilter;
use MhmCurrencySwitcher\Integration\WooCommerce\ShippingFilter;
use PHPUnit\Framework\TestCase;

/**
 * Class RoundingConsistencyTest
 *
 * Base TRY, USD at a flat rate of 0.03 with no fee, rounded to the nearest 1.
 * Every amount below is 123 TRY, which converts to 3.69 — a value the rounding
 * rule visibly moves, to 4.0. An amount that rounded to itself would let an
 * unrounded surface pass.
 *
 * @covers \MhmCurrencySwitcher\Integration\WooCommerce\ShippingFilter
 * @covers \MhmCurrencySwitcher\Integration\WooCommerce\CouponFilter
 * @covers \MhmCurrencySwitcher\Integration\WooCommerce\CartFilter
 */
class RoundingConsistencyTest extends TestCase {

	private const AMOUNT_IN_BASE = 123.0;
	private const CONVERTED      = 3.69;
	private const ROUNDED        = 4.0;

	/**
	 * Shipping filter under test.
	 *
	 * @var ShippingFilter
	 */
	private ShippingFilter $shipping_filter;

	/**
	 * Coupon filter under test.
	 *
	 * @var CouponFilter
	 */
	private CouponFilter $coupon_filter;

	/**
	 * Cart filter under test.
	 *
	 * @var CartFilter
	 */
	private CartFilter $cart_filter;

	/**
	 * Set up the filters over a rounding-enabled USD.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$store = new CurrencyStore();
		$store->set_data(
			'TRY',
			array(
				array(
					'code'     => 'USD',
					'enabled'  => true,
					'rate'     => array(
						'type'  => 'manual',
						'value' => 0.03,
					),
					'fee'      => array(
						'type'  => 'none',
						'value' => 0,
					),
					'rounding' => array(
						'type'     => 'nearest',
						'value'    => 1.0,
						'subtract' => 0,
					),
					'format'   => array(
						'symbol'       => '$',
						'position'     => 'left',
						'thousand_sep' => ',',
						'decimal_sep'  => '.',
						'decimals'     => 2,
					),
				),
			)
		);

		$context   = new ConversionContext();
		$converter = new Converter( $store );
		$detection = new DetectionService( $store, $context );

		$this->shipping_filter = new ShippingFilter( $converter, $detection, $context );
		$this->coupon_filter   = new CouponFilter( $converter, $detection, $context );
		$this->cart_filter     = new CartFilter( $converter, $store, $detection, $context );

		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';
	}

	/**
	 * Clean up superglobals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_COOKIE[ DetectionService::COOKIE_NAME ] );

		unset(
			$GLOBALS['__mhmcs_test_is_admin'],
			$GLOBALS['__mhmcs_test_doing_ajax'],
			$GLOBALS['__mhmcs_test_referer'],
			$GLOBALS['__mhmcs_test_did_actions']
		);

		parent::tearDown();
	}

	/**
	 * A coupon stub reporting the given discount type.
	 *
	 * @param string $type Discount type.
	 * @return object Coupon stub.
	 */
	private function coupon( string $type ): object {
		return new class( $type ) {
			/**
			 * Discount type.
			 *
			 * @var string
			 */
			private string $type;

			/**
			 * Constructor.
			 *
			 * @param string $type Discount type.
			 */
			public function __construct( string $type ) {
				$this->type = $type;
			}

			/**
			 * Report the discount type.
			 *
			 * @return string
			 */
			public function get_discount_type(): string {
				return $this->type;
			}
		};
	}

	/**
	 * A stand-in for WC_Shipping_Rate that keeps its magic properties.
	 *
	 * 🔴 Not a stdClass, and the difference is the whole reason this exists.
	 * WC_Shipping_Rate keeps everything in a protected $data array behind
	 * __get/__set, so `$rate->taxes[ $id ] = $x` is an indirect modification of
	 * an overloaded property — PHP performs it on a temporary copy and the
	 * object never changes. A stdClass has real properties, so it accepts that
	 * write happily: the first version of this test passed against code that
	 * did nothing at all in production, and only a browser round caught it.
	 *
	 * @param float                  $cost  Rate cost.
	 * @param array<int|string,float> $taxes Tax lines, or an empty array.
	 * @return object Rate stand-in.
	 */
	private function shipping_rate( float $cost, array $taxes = array() ): object {
		return new class( $cost, $taxes ) {
			/**
			 * Rate data, reachable only through the magic accessors.
			 *
			 * @var array<string, mixed>
			 */
			protected array $data;

			/**
			 * Constructor.
			 *
			 * @param float                  $cost  Rate cost.
			 * @param array<int|string,float> $taxes Tax lines.
			 */
			public function __construct( float $cost, array $taxes ) {
				$this->data = array(
					'cost'  => $cost,
					'taxes' => $taxes,
				);
			}

			/**
			 * Read a rate property.
			 *
			 * @param string $key Property name.
			 * @return mixed
			 */
			public function __get( $key ) {
				return $this->data[ $key ] ?? null;
			}

			/**
			 * Write a rate property.
			 *
			 * @param string $key   Property name.
			 * @param mixed  $value New value.
			 * @return void
			 */
			public function __set( $key, $value ) {
				$this->data[ $key ] = $value;
			}

			/**
			 * Whether a rate property is set.
			 *
			 * @param string $key Property name.
			 * @return bool
			 */
			public function __isset( $key ) {
				return isset( $this->data[ $key ] );
			}

			/**
			 * Replace the tax lines, as WC_Shipping_Rate does.
			 *
			 * @param array<int|string,float> $taxes Tax lines.
			 * @return void
			 */
			public function set_taxes( $taxes ) {
				$this->data['taxes'] = $taxes;
			}
		};
	}

	/**
	 * Shipping cost is rounded.
	 *
	 * @return void
	 */
	public function test_shipping_cost_is_rounded(): void {
		$rate = $this->shipping_rate( self::AMOUNT_IN_BASE );

		$result = $this->shipping_filter->convert_shipping_rates(
			array( 'flat_rate:1' => $rate ),
			array()
		);

		$this->assertEqualsWithDelta( self::ROUNDED, $result['flat_rate:1']->cost, 0.001 );
	}

	/**
	 * 🔴 A shipping rate's tax lines are converted too.
	 *
	 * Only `cost` was ever touched, so the tax stayed a base-currency number
	 * sitting in a cart priced in another one — WooCommerce adds it to the
	 * total as-is, so the customer was charged base-currency tax on a converted
	 * shipping cost.
	 *
	 * Converted but NOT rounded, for the reason the spend threshold is not: a
	 * tax line is a derived amount, not a price anyone chose. Rounding each one
	 * on its own would leave the tax no longer matching the rate it came from.
	 *
	 * @return void
	 */
	public function test_shipping_taxes_are_converted(): void {
		$rate = $this->shipping_rate(
			self::AMOUNT_IN_BASE,
			array(
				1 => self::AMOUNT_IN_BASE,
				2 => 0.0,
			)
		);

		$result = $this->shipping_filter->convert_shipping_rates(
			array( 'flat_rate:1' => $rate ),
			array()
		);

		$taxes = $result['flat_rate:1']->taxes;

		$this->assertEqualsWithDelta( self::CONVERTED, (float) $taxes[1], 0.001 );
		$this->assertEqualsWithDelta( 0.0, (float) $taxes[2], 0.001 );
	}

	/**
	 * A rate carrying no taxes is left alone rather than given an empty array.
	 *
	 * @return void
	 */
	public function test_a_rate_without_taxes_is_untouched(): void {
		$rate = $this->shipping_rate( self::AMOUNT_IN_BASE );

		$result = $this->shipping_filter->convert_shipping_rates(
			array( 'flat_rate:1' => $rate ),
			array()
		);

		$this->assertSame( array(), $result['flat_rate:1']->taxes );
	}

	/**
	 * A cart fee is rounded.
	 *
	 * @return void
	 */
	public function test_cart_fee_is_rounded(): void {
		$fee         = new \stdClass();
		$fee->amount = self::AMOUNT_IN_BASE;

		$cart = new class( array( $fee ) ) {
			/**
			 * Fee objects.
			 *
			 * @var array<int, object>
			 */
			private array $fees;

			/**
			 * Constructor.
			 *
			 * @param array<int, object> $fees Fee objects.
			 */
			public function __construct( array $fees ) {
				$this->fees = $fees;
			}

			/**
			 * Report the cart's fees, as WC_Cart does.
			 *
			 * @return array<int, object>
			 */
			public function get_fees(): array {
				return $this->fees;
			}
		};

		$this->cart_filter->recalculate_fees( $cart );

		$this->assertEqualsWithDelta( self::ROUNDED, $fee->amount, 0.001 );
	}

	/**
	 * A fixed-cart discount is rounded.
	 *
	 * @return void
	 */
	public function test_fixed_coupon_amount_is_rounded(): void {
		$result = $this->coupon_filter->convert_coupon_amount(
			self::AMOUNT_IN_BASE,
			$this->coupon( 'fixed_cart' )
		);

		$this->assertEqualsWithDelta( self::ROUNDED, (float) $result, 0.001 );
	}

	/**
	 * 🔴 A coupon's spend threshold is NOT rounded — on purpose.
	 *
	 * The rounding rule exists to make the prices a customer is charged tidy.
	 * A minimum spend is not an amount anybody pays; it is the line at which the
	 * merchant's coupon starts applying. Rounding it moves that line — a coupon
	 * set to need 123 would start applying at 4.0 instead of 3.69 — which is a
	 * change to a business rule the merchant wrote, not to a displayed price.
	 * The exchange rate already moves it as little as it can; rounding would
	 * move it further for no benefit to anyone.
	 *
	 * @return void
	 */
	public function test_coupon_spend_threshold_is_converted_but_not_rounded(): void {
		$result = $this->coupon_filter->convert_min_max_amount(
			self::AMOUNT_IN_BASE,
			$this->coupon( 'fixed_cart' )
		);

		$this->assertEqualsWithDelta( self::CONVERTED, (float) $result, 0.001 );
	}
}
