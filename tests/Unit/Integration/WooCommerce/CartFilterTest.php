<?php
/**
 * Unit tests for CartFilter.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Integration\WooCommerce
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Integration\WooCommerce;

use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;
use MhmCurrencySwitcher\Integration\WooCommerce\CartFilter;
use PHPUnit\Framework\TestCase;

/**
 * Class CartFilterTest
 *
 * Pure unit tests — no WordPress or WooCommerce dependency.
 * Tests fee conversion and order meta storage using stub objects.
 *
 * Setup:
 *   Base: TRY
 *   USD: rate=0.03, fee=percentage 2% → effective rate 0.0306
 *
 * @covers \MhmCurrencySwitcher\Integration\WooCommerce\CartFilter
 */
class CartFilterTest extends TestCase {

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
	 * Cart filter instance under test.
	 *
	 * @var CartFilter
	 */
	private CartFilter $cart_filter;

	/**
	 * Set up store, converter, detection, and cart filter instances.
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

		$this->converter   = new Converter( $this->store );
		$this->detection   = new DetectionService( $this->store );
		$this->cart_filter = new CartFilter( $this->converter, $this->store, $this->detection );

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
	 * Test that recalculate_fees converts fee amounts for non-base currency.
	 *
	 * Fee of 100 TRY with USD selected:
	 * effective rate = 0.03 * 1.02 = 0.0306
	 * converted = 100 * 0.0306 = 3.06
	 *
	 * @return void
	 */
	public function test_recalculate_fees_converts_amount(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$fee         = new \stdClass();
		$fee->amount = 100.0;

		$cart = new class( array( $fee ) ) {
			/**
			 * Array of fee objects.
			 *
			 * @var array
			 */
			private array $fees;

			/**
			 * Constructor.
			 *
			 * @param array $fees Fee objects.
			 */
			public function __construct( array $fees ) {
				$this->fees = $fees;
			}

			/**
			 * Get fees.
			 *
			 * @return array
			 */
			public function get_fees(): array {
				return $this->fees;
			}
		};

		$this->cart_filter->recalculate_fees( $cart );

		$this->assertEqualsWithDelta( 3.06, $fee->amount, 0.01 );
	}

	/**
	 * Test that recalculate_fees skips conversion for base currency.
	 *
	 * No cookie → TRY (base) → fee amount unchanged.
	 *
	 * @return void
	 */
	public function test_recalculate_fees_skips_base_currency(): void {
		// No cookie → base currency TRY.
		$fee         = new \stdClass();
		$fee->amount = 100.0;

		$cart = new class( array( $fee ) ) {
			/**
			 * Array of fee objects.
			 *
			 * @var array
			 */
			private array $fees;

			/**
			 * Constructor.
			 *
			 * @param array $fees Fee objects.
			 */
			public function __construct( array $fees ) {
				$this->fees = $fees;
			}

			/**
			 * Get fees.
			 *
			 * @return array
			 */
			public function get_fees(): array {
				return $this->fees;
			}
		};

		$this->cart_filter->recalculate_fees( $cart );

		$this->assertSame( 100.0, $fee->amount );
	}

	/**
	 * Test that save_order_meta stores the currency code.
	 *
	 * @return void
	 */
	public function test_save_order_meta_stores_currency_code(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$meta  = array();
		$order = new class( $meta ) {
			/**
			 * Stored metadata.
			 *
			 * @var array
			 */
			public array $meta;

			/**
			 * Constructor.
			 *
			 * @param array $meta Initial metadata.
			 */
			public function __construct( array &$meta ) {
				$this->meta = &$meta;
			}

			/**
			 * Update meta data.
			 *
			 * @param string $key   Meta key.
			 * @param mixed  $value Meta value.
			 * @return void
			 */
			public function update_meta_data( string $key, $value ): void {
				$this->meta[ $key ] = $value;
			}
		};

		$this->cart_filter->save_order_meta( $order, array() );

		$this->assertSame( 'USD', $meta['_mhm_cs_currency_code'] );
	}

	/**
	 * Test that save_order_meta stores the exchange rate.
	 *
	 * USD effective rate = 0.03 * 1.02 = 0.0306
	 *
	 * @return void
	 */
	public function test_save_order_meta_stores_exchange_rate(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$meta  = array();
		$order = new class( $meta ) {
			/**
			 * Stored metadata.
			 *
			 * @var array
			 */
			public array $meta;

			/**
			 * Constructor.
			 *
			 * @param array $meta Initial metadata.
			 */
			public function __construct( array &$meta ) {
				$this->meta = &$meta;
			}

			/**
			 * Update meta data.
			 *
			 * @param string $key   Meta key.
			 * @param mixed  $value Meta value.
			 * @return void
			 */
			public function update_meta_data( string $key, $value ): void {
				$this->meta[ $key ] = $value;
			}
		};

		$this->cart_filter->save_order_meta( $order, array() );

		$this->assertEqualsWithDelta( 0.0306, $meta['_mhm_cs_exchange_rate'], 0.0001 );
	}

	/**
	 * Test that save_order_meta stores the base currency.
	 *
	 * @return void
	 */
	public function test_save_order_meta_stores_base_currency(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$meta  = array();
		$order = new class( $meta ) {
			/**
			 * Stored metadata.
			 *
			 * @var array
			 */
			public array $meta;

			/**
			 * Constructor.
			 *
			 * @param array $meta Initial metadata.
			 */
			public function __construct( array &$meta ) {
				$this->meta = &$meta;
			}

			/**
			 * Update meta data.
			 *
			 * @param string $key   Meta key.
			 * @param mixed  $value Meta value.
			 * @return void
			 */
			public function update_meta_data( string $key, $value ): void {
				$this->meta[ $key ] = $value;
			}
		};

		$this->cart_filter->save_order_meta( $order, array() );

		$this->assertSame( 'TRY', $meta['_mhm_cs_base_currency'] );
	}
}
