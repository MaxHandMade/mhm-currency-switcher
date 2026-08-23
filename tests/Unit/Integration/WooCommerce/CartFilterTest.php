<?php
/**
 * Unit tests for CartFilter.
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
		$context           = new ConversionContext();
		$this->detection   = new DetectionService( $this->store, $context );
		$this->cart_filter = new CartFilter( $this->converter, $this->store, $this->detection, $context );

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

		$this->assertSame( 'USD', $meta['_mhmcs_currency_code'] );
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

		$this->assertEqualsWithDelta( 0.0306, $meta['_mhmcs_exchange_rate'], 0.0001 );
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

		$this->assertSame( 'TRY', $meta['_mhmcs_base_currency'] );
	}

	/**
	 * An order double that records what was written to it.
	 *
	 * @param array $meta Receives the written meta by reference.
	 * @return object
	 */
	private function create_order_double( array &$meta ): object {
		return new class( $meta ) {
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
	}

	/**
	 * 🔴 A base-currency order records a rate of 1, not 0.
	 *
	 * This handler is hooked on `woocommerce_checkout_create_order`
	 * unconditionally, so it runs for every order — including the ordinary
	 * ones placed in the shop's own currency. It writes whatever
	 * `Converter::get_rate()` returns, and the base currency is not a row in
	 * the currency list, so that call returns 0.0.
	 *
	 * The result is not an edge case: every base-currency order in every shop
	 * running this plugin carries `_mhmcs_exchange_rate = 0`. Anything reading
	 * that meta to reconstruct what the customer was charged — a report, an
	 * accounting export, a refund calculation — divides by it or multiplies by
	 * it and gets nothing back.
	 *
	 * 1 is the rate at which the base currency converts to itself, which is
	 * exactly what this order was priced at.
	 *
	 * @return void
	 */
	public function test_save_order_meta_records_a_rate_of_one_for_a_base_currency_order(): void {
		// No cookie: an ordinary order in the shop's own currency.
		$meta  = array();
		$order = $this->create_order_double( $meta );

		$this->cart_filter->save_order_meta( $order, array() );

		$this->assertSame(
			'TRY',
			$meta['_mhmcs_currency_code'],
			'Guard: this is a base-currency order.'
		);

		$this->assertEqualsWithDelta(
			1.0,
			$meta['_mhmcs_exchange_rate'],
			0.0001,
			'The recorded rate must belong to the recorded currency; the base converts to itself at 1.'
		);
	}
}
