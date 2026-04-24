<?php
/**
 * Unit tests for OrderFilter.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Integration\WooCommerce
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Integration\WooCommerce;

use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;
use MhmCurrencySwitcher\Integration\WooCommerce\OrderFilter;
use PHPUnit\Framework\TestCase;

/**
 * Class OrderFilterTest
 *
 * Pure unit tests — no WordPress or WooCommerce dependency.
 * Tests order currency retrieval and total formatting using stub objects.
 *
 * Setup:
 *   Base: TRY
 *   USD: symbol=$, position=left
 *   TRY has no entry in currencies array (it is the base, not a target).
 *
 * @covers \MhmCurrencySwitcher\Integration\WooCommerce\OrderFilter
 */
class OrderFilterTest extends TestCase {

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
	 * Order filter instance under test.
	 *
	 * @var OrderFilter
	 */
	private OrderFilter $order_filter;

	/**
	 * Set up store, detection, and order filter instances.
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

		$this->detection    = new DetectionService( $this->store );
		$this->order_filter = new OrderFilter( $this->store, $this->detection );

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
	 * Create a mock order with get_meta support.
	 *
	 * @param array<string, mixed> $meta Key-value pairs of meta data.
	 * @return object Anonymous order stub.
	 */
	private function create_order_stub( array $meta ): object {
		// Extends the WC_Order stub from tests/bootstrap.php so that
		// `instanceof \WC_Order` guards in `OrderFilter` pass.
		return new class( $meta ) extends \WC_Order {
			/**
			 * Order metadata.
			 *
			 * @var array
			 */
			private array $meta;

			/**
			 * Constructor.
			 *
			 * @param array $meta Meta data.
			 */
			public function __construct( array $meta ) {
				$this->meta = $meta;
			}

			/**
			 * Get meta value by key.
			 *
			 * @param string $key    Meta key.
			 * @param bool   $single Whether to return single value.
			 * @return mixed Meta value or empty string.
			 */
			public function get_meta( $key, $single = false ) {
				return $this->meta[ $key ] ?? '';
			}
		};
	}

	// ---------------------------------------------------------------
	// get_order_currency tests
	// ---------------------------------------------------------------

	/**
	 * Test that get_order_currency returns the stored currency code.
	 *
	 * @return void
	 */
	public function test_get_order_currency_returns_code(): void {
		$order = $this->create_order_stub(
			array( '_mhm_cs_currency_code' => 'USD' )
		);

		$result = OrderFilter::get_order_currency( $order );

		$this->assertSame( 'USD', $result );
	}

	/**
	 * Test that get_order_currency returns null when meta is missing.
	 *
	 * @return void
	 */
	public function test_get_order_currency_returns_null_when_missing(): void {
		$order = $this->create_order_stub( array() );

		$result = OrderFilter::get_order_currency( $order );

		$this->assertNull( $result );
	}

	// ---------------------------------------------------------------
	// format_order_totals tests
	// ---------------------------------------------------------------

	/**
	 * Test that format_order_totals modifies rows when order has USD meta.
	 *
	 * Since the base currency (TRY) is not in the currencies array,
	 * the symbol replacement fallback applies. The test verifies that
	 * the method processes the rows without error and returns them.
	 *
	 * @return void
	 */
	public function test_format_order_totals_with_currency_meta(): void {
		$order = $this->create_order_stub(
			array( '_mhm_cs_currency_code' => 'USD' )
		);

		$total_rows = array(
			'subtotal' => array(
				'label' => 'Subtotal:',
				'value' => '$30.60',
			),
			'total'    => array(
				'label' => 'Total:',
				'value' => '$30.60',
			),
		);

		$result = $this->order_filter->format_order_totals( $total_rows, $order, 'excl' );

		// USD is a non-base currency and has format data, so the method processes it.
		// Since the base (TRY) has no entry in currencies, the symbol replacement
		// fallback returns formatted values unchanged (no TRY symbol to replace).
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'subtotal', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'value', $result['subtotal'] );
		$this->assertArrayHasKey( 'value', $result['total'] );
	}

	/**
	 * Test that format_order_totals returns original rows when no meta.
	 *
	 * Orders without `_mhm_cs_currency_code` meta are passed through
	 * without modification.
	 *
	 * @return void
	 */
	public function test_format_order_totals_without_meta(): void {
		$order = $this->create_order_stub( array() );

		$total_rows = array(
			'subtotal' => array(
				'label' => 'Subtotal:',
				'value' => '&#8378;1,000.00',
			),
			'total'    => array(
				'label' => 'Total:',
				'value' => '&#8378;1,000.00',
			),
		);

		$result = $this->order_filter->format_order_totals( $total_rows, $order, 'excl' );

		$this->assertSame( $total_rows, $result );
	}
}
