<?php
/**
 * Unit tests for RestApiFilter.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Integration\WooCommerce
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Integration\WooCommerce;

use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Integration\WooCommerce\RestApiFilter;
use PHPUnit\Framework\TestCase;

/**
 * Class RestApiFilterTest
 *
 * Pure unit tests — no WordPress or WooCommerce dependency.
 * Uses lightweight stubs for WP_REST_Request and WP_REST_Response.
 *
 * Setup:
 *   Base: TRY
 *   USD: rate=0.03, fee=percentage 2%, enabled
 *
 * @covers \MhmCurrencySwitcher\Integration\WooCommerce\RestApiFilter
 */
class RestApiFilterTest extends TestCase {

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
	 * REST API filter instance under test.
	 *
	 * @var RestApiFilter
	 */
	private RestApiFilter $filter;

	/**
	 * Set up store, converter, and filter instances.
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
					'code'     => 'USD',
					'enabled'  => true,
					'rate'     => array(
						'type'  => 'manual',
						'value' => 0.03,
					),
					'fee'      => array(
						'type'  => 'percentage',
						'value' => 2,
					),
					'rounding' => array(
						'type'     => 'disabled',
						'value'    => 0,
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

		$this->converter = new Converter( $this->store );
		$this->filter    = new RestApiFilter( $this->converter, $this->store );
	}

	/**
	 * Create a stub REST response with product data.
	 *
	 * @param array<string, mixed> $data Response data.
	 * @return object Anonymous response stub with get_data/set_data.
	 */
	private function create_response_stub( array $data ): object {
		return new class( $data ) {
			/**
			 * Response data.
			 *
			 * @var array
			 */
			private array $data;

			/**
			 * Constructor.
			 *
			 * @param array $data Response data.
			 */
			public function __construct( array $data ) {
				$this->data = $data;
			}

			/**
			 * Get response data.
			 *
			 * @return array
			 */
			public function get_data(): array {
				return $this->data;
			}

			/**
			 * Set response data.
			 *
			 * @param array $data New data.
			 * @return void
			 */
			public function set_data( array $data ): void {
				$this->data = $data;
			}
		};
	}

	/**
	 * Create a stub REST request with optional currency parameter.
	 *
	 * @param string|null $currency Currency parameter value, or null for none.
	 * @return object Anonymous request stub with get_param.
	 */
	private function create_request_stub( ?string $currency = null ): object {
		return new class( $currency ) {
			/**
			 * Currency parameter.
			 *
			 * @var string|null
			 */
			private ?string $currency;

			/**
			 * Constructor.
			 *
			 * @param string|null $currency Currency param.
			 */
			public function __construct( ?string $currency ) {
				$this->currency = $currency;
			}

			/**
			 * Get a request parameter.
			 *
			 * @param string $key Parameter name.
			 * @return mixed Parameter value or null.
			 */
			public function get_param( string $key ) {
				if ( 'currency' === $key ) {
					return $this->currency;
				}

				return null;
			}
		};
	}

	// ---------------------------------------------------------------
	// Tests
	// ---------------------------------------------------------------

	/**
	 * Test that product response is converted with a valid currency parameter.
	 *
	 * 1000 TRY → USD: effective rate = 0.03 * 1.02 = 0.0306
	 * converted = 1000 * 0.0306 = 30.6
	 *
	 * @return void
	 */
	public function test_product_response_converted_with_param(): void {
		$response = $this->create_response_stub(
			array(
				'id'            => 42,
				'price'         => '1000',
				'regular_price' => '1200',
				'sale_price'    => '1000',
			)
		);

		$request = $this->create_request_stub( 'USD' );

		$result = $this->filter->maybe_convert_product_response( $response, null, $request );

		$data = $result->get_data();

		$this->assertEqualsWithDelta( 30.6, (float) $data['price'], 0.01 );
		$this->assertEqualsWithDelta( 36.72, (float) $data['regular_price'], 0.01 );
		$this->assertEqualsWithDelta( 30.6, (float) $data['sale_price'], 0.01 );
		$this->assertSame( 'USD', $data['currency_code'] );
	}

	/**
	 * Test that product response is unchanged without a currency parameter.
	 *
	 * @return void
	 */
	public function test_product_response_unchanged_without_param(): void {
		$response = $this->create_response_stub(
			array(
				'id'            => 42,
				'price'         => '1000',
				'regular_price' => '1200',
				'sale_price'    => '1000',
			)
		);

		$request = $this->create_request_stub( null );

		$result = $this->filter->maybe_convert_product_response( $response, null, $request );

		$data = $result->get_data();

		$this->assertSame( '1000', $data['price'] );
		$this->assertSame( '1200', $data['regular_price'] );
		$this->assertSame( '1000', $data['sale_price'] );
		$this->assertArrayNotHasKey( 'currency_code', $data );
	}

	/**
	 * Test that an invalid currency parameter is ignored.
	 *
	 * @return void
	 */
	public function test_invalid_currency_param_ignored(): void {
		$response = $this->create_response_stub(
			array(
				'id'            => 42,
				'price'         => '1000',
				'regular_price' => '1200',
				'sale_price'    => '1000',
			)
		);

		$request = $this->create_request_stub( 'INVALID' );

		$result = $this->filter->maybe_convert_product_response( $response, null, $request );

		$data = $result->get_data();

		$this->assertSame( '1000', $data['price'] );
		$this->assertSame( '1200', $data['regular_price'] );
		$this->assertSame( '1000', $data['sale_price'] );
		$this->assertArrayNotHasKey( 'currency_code', $data );
	}
}
