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
	 * Create a stub WC_Product with context-aware price getters.
	 *
	 * RestApiFilter reads prices via "edit" context (the raw, unfiltered
	 * base-currency amount) rather than trusting the response data, so that
	 * it never stacks on top of a conversion PriceFilter already applied
	 * via the product's own "view" context getters. This stub always
	 * returns the same base-currency value regardless of context, which is
	 * enough to exercise that RestApiFilter reads from the product at all.
	 *
	 * @param string $price         Raw price.
	 * @param string $regular_price Raw regular price.
	 * @param string $sale_price    Raw sale price.
	 * @return object Anonymous product stub.
	 */
	private function create_product_stub( string $price, string $regular_price, string $sale_price, int $id = 42 ): object {
		return new class( $price, $regular_price, $sale_price, $id ) {
			/**
			 * Raw price.
			 *
			 * @var string
			 */
			private string $price;

			/**
			 * Raw regular price.
			 *
			 * @var string
			 */
			private string $regular_price;

			/**
			 * Raw sale price.
			 *
			 * @var string
			 */
			private string $sale_price;

			/**
			 * Constructor.
			 *
			 * @param string $price         Raw price.
			 * @param string $regular_price Raw regular price.
			 * @param string $sale_price    Raw sale price.
			 * @param int    $id            Product ID.
			 */
			public function __construct( string $price, string $regular_price, string $sale_price, int $id ) {
				$this->price         = $price;
				$this->regular_price = $regular_price;
				$this->sale_price    = $sale_price;
				$this->id            = $id;
			}

			/**
			 * Product ID.
			 *
			 * @var int
			 */
			private int $id;

			/**
			 * Get the product ID.
			 *
			 * @return int
			 */
			public function get_id(): int {
				return $this->id;
			}

			/**
			 * Get the price.
			 *
			 * @param string $context Getter context (unused by this stub).
			 * @return string
			 */
			public function get_price( string $context = 'view' ): string {
				return $this->price;
			}

			/**
			 * Get the regular price.
			 *
			 * @param string $context Getter context (unused by this stub).
			 * @return string
			 */
			public function get_regular_price( string $context = 'view' ): string {
				return $this->regular_price;
			}

			/**
			 * Get the sale price.
			 *
			 * @param string $context Getter context (unused by this stub).
			 * @return string
			 */
			public function get_sale_price( string $context = 'view' ): string {
				return $this->sale_price;
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
		$product = $this->create_product_stub( '1000', '1200', '1000' );

		$result = $this->filter->maybe_convert_product_response( $response, $product, $request );

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

	/**
	 * Regression test: RestApiFilter must convert from the product's raw
	 * ("edit" context) price, not from whatever value is already sitting in
	 * $data. $data can already be converted by the time this filter runs
	 * (e.g. PriceFilter's own visitor-currency conversion, applied earlier
	 * via the product's "view" context getters during response
	 * preparation). Converting an already-converted $data value again would
	 * stack two conversions on top of each other.
	 *
	 * Here $data deliberately carries an already-converted (and therefore
	 * WRONG for this purpose) value that does not match the product's raw
	 * price, so a test that reads from $data instead of the product would
	 * fail this assertion.
	 *
	 * @return void
	 */
	public function test_converts_from_raw_product_price_not_from_already_converted_response_data(): void {
		$response = $this->create_response_stub(
			array(
				'id'            => 42,
				// Deliberately NOT 1000 -- simulates PriceFilter having
				// already converted this field once before RestApiFilter runs.
				'price'         => '2000',
				'regular_price' => '2400',
				'sale_price'    => '2000',
			)
		);

		$request = $this->create_request_stub( 'USD' );
		$product = $this->create_product_stub( '1000', '1200', '1000' );

		$result = $this->filter->maybe_convert_product_response( $response, $product, $request );

		$data = $result->get_data();

		// 1000 TRY (the product's raw price) * effective rate 0.0306 = 30.6.
		// If RestApiFilter had converted $data['price'] (2000) instead, this
		// would be 61.2.
		$this->assertEqualsWithDelta(
			30.6,
			(float) $data['price'],
			0.01,
			'Must convert the product raw price (1000), not the already-converted response value (2000).'
		);
	}

	/**
	 * 🔴 A per-product fixed price wins over the exchange rate here too.
	 *
	 * The shop page has honoured `_mhmcs_fixed_prices` since the feature
	 * existed; this endpoint never looked at it and answered with the
	 * rate-calculated number instead. The same product then had two different
	 * prices depending on which surface asked — the storefront said 25, the
	 * API said 30.6, and anything syncing stock or feeds took the API's word.
	 *
	 * All three fields follow, exactly as PriceFilter does it: it applies the
	 * fixed price to `price`, `regular_price` and `sale_price` alike. Matching
	 * that is the point — the two surfaces have to agree, including where the
	 * behaviour is imperfect (a fixed price cannot express a sale; that is a
	 * documented limit, not something for this endpoint to decide differently).
	 *
	 * @return void
	 */
	public function test_fixed_price_overrides_conversion(): void {
		$GLOBALS['__mhmcs_test_post_meta'][77]['_mhmcs_fixed_prices'] = wp_json_encode( array( 'USD' => 25.0 ) );

		$response = $this->create_response_stub(
			array(
				'id'            => 77,
				'price'         => '1000',
				'regular_price' => '1200',
				'sale_price'    => '1000',
			)
		);

		$result = $this->filter->maybe_convert_product_response(
			$response,
			$this->create_product_stub( '1000', '1200', '1000', 77 ),
			$this->create_request_stub( 'USD' )
		);

		$data = $result->get_data();

		$this->assertEqualsWithDelta( 25.0, (float) $data['price'], 0.01 );
		$this->assertEqualsWithDelta( 25.0, (float) $data['regular_price'], 0.01 );
		$this->assertEqualsWithDelta( 25.0, (float) $data['sale_price'], 0.01 );
	}

	/**
	 * A fixed price set for another currency does not leak into this one.
	 *
	 * @return void
	 */
	public function test_fixed_price_for_another_currency_is_ignored(): void {
		$GLOBALS['__mhmcs_test_post_meta'][78]['_mhmcs_fixed_prices'] = wp_json_encode( array( 'EUR' => 25.0 ) );

		$response = $this->create_response_stub(
			array(
				'id'    => 78,
				'price' => '1000',
			)
		);

		$result = $this->filter->maybe_convert_product_response(
			$response,
			$this->create_product_stub( '1000', '1200', '1000', 78 ),
			$this->create_request_stub( 'USD' )
		);

		$data = $result->get_data();

		$this->assertEqualsWithDelta( 30.6, (float) $data['price'], 0.01 );
	}
}
