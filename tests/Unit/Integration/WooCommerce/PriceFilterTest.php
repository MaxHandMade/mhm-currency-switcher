<?php
/**
 * Unit tests for PriceFilter and FormatFilter.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Integration\WooCommerce
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Integration\WooCommerce;

use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;
use MhmCurrencySwitcher\Integration\WooCommerce\FormatFilter;
use MhmCurrencySwitcher\Integration\WooCommerce\PriceFilter;
use PHPUnit\Framework\TestCase;

/**
 * Class PriceFilterTest
 *
 * Pure unit tests — no WordPress or WooCommerce dependency.
 * Tests the conversion and formatting methods directly without
 * invoking add_filter or any WC functions.
 *
 * Setup:
 *   Base: TRY
 *   USD: rate=0.03, fee=percentage 2%, decimals=2, symbol=$, position=left,
 *        thousand_sep=',', decimal_sep='.'
 *   EUR: rate=0.025, fee=fixed 0.001, decimals=2, symbol=€, position=right,
 *        thousand_sep='.', decimal_sep=','
 *
 * @covers \MhmCurrencySwitcher\Integration\WooCommerce\PriceFilter
 * @covers \MhmCurrencySwitcher\Integration\WooCommerce\FormatFilter
 */
class PriceFilterTest extends TestCase {

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
	 * Price filter instance under test.
	 *
	 * @var PriceFilter
	 */
	private PriceFilter $price_filter;

	/**
	 * Format filter instance under test.
	 *
	 * @var FormatFilter
	 */
	private FormatFilter $format_filter;

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
					'code'            => 'USD',
					'enabled'         => true,
					'sort_order'      => 0,
					'rate'            => array(
						'type'  => 'manual',
						'value' => 0.03,
					),
					'fee'             => array(
						'type'  => 'percentage',
						'value' => 2,
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
						'value' => 0.001,
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

		$this->converter    = new Converter( $this->store );
		$this->detection    = new DetectionService( $this->store );
		$this->price_filter = new PriceFilter( $this->converter, $this->detection );
		$this->format_filter = new FormatFilter( $this->store, $this->detection );

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
	// PriceFilter tests
	// ---------------------------------------------------------------

	/**
	 * Test that convert_price applies conversion for non-base currency.
	 *
	 * 1000 TRY with USD selected:
	 * effective rate = 0.03 * 1.02 = 0.0306
	 * converted = 1000 * 0.0306 = 30.6
	 *
	 * @return void
	 */
	public function test_convert_price_applies_conversion(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$result = $this->price_filter->convert_price( 1000, null );

		$this->assertEqualsWithDelta( 30.6, $result, 0.01 );
	}

	/**
	 * Test that convert_price returns the original price when base currency.
	 *
	 * No cookie set → detection returns TRY (base) → no conversion.
	 *
	 * @return void
	 */
	public function test_convert_price_returns_original_when_base(): void {
		// No cookie → base currency TRY.
		$result = $this->price_filter->convert_price( 1000, null );

		$this->assertSame( 1000, $result );
	}

	/**
	 * Test that convert_price handles empty string prices.
	 *
	 * WooCommerce uses '' to indicate "no price set". Must return ''.
	 *
	 * @return void
	 */
	public function test_convert_price_handles_empty_string(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$result = $this->price_filter->convert_price( '', null );

		$this->assertSame( '', $result );
	}

	/**
	 * Test that convert_sale_price returns '' for empty sale price.
	 *
	 * Products without a sale price pass '' — must return '' not 0.
	 *
	 * @return void
	 */
	public function test_convert_sale_price_handles_empty(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$result = $this->price_filter->convert_sale_price( '', null );

		$this->assertSame( '', $result );
	}

	/**
	 * Test that add_currency_to_hash appends the currency code.
	 *
	 * @return void
	 */
	public function test_add_currency_to_hash(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$hash   = array( 'existing_hash_1', 'existing_hash_2' );
		$result = $this->price_filter->add_currency_to_hash( $hash, null, true );

		$this->assertCount( 3, $result );
		$this->assertSame( 'USD', $result[2] );
	}

	// ---------------------------------------------------------------
	// FormatFilter tests
	// ---------------------------------------------------------------

	/**
	 * Test that get_currency_code returns the active currency code.
	 *
	 * @return void
	 */
	public function test_get_currency_code(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$result = $this->format_filter->get_currency_code( 'TRY' );

		$this->assertSame( 'USD', $result );
	}

	/**
	 * Test that get_currency_symbol returns the active currency symbol.
	 *
	 * @return void
	 */
	public function test_get_currency_symbol(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$result = $this->format_filter->get_currency_symbol( "\u{20BA}", 'TRY' );

		$this->assertSame( '$', $result );
	}

	/**
	 * Test that get_decimals returns the correct decimal count.
	 *
	 * @return void
	 */
	public function test_get_decimals(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$result = $this->format_filter->get_decimals( 0 );

		$this->assertSame( 2, $result );
	}

	/**
	 * Test that format methods return original values when base currency.
	 *
	 * No cookie → TRY is base → all format methods pass through.
	 *
	 * @return void
	 */
	public function test_format_returns_original_when_base(): void {
		// No cookie → base currency TRY.
		$this->assertSame( '.', $this->format_filter->get_thousand_separator( '.' ) );
		$this->assertSame( ',', $this->format_filter->get_decimal_separator( ',' ) );
		$this->assertSame( 'left', $this->format_filter->get_currency_position( 'left' ) );
		$this->assertSame( 'TRY', $this->format_filter->get_currency_code( 'TRY' ) );
		$this->assertSame( "\u{20BA}", $this->format_filter->get_currency_symbol( "\u{20BA}", 'TRY' ) );
		$this->assertSame( 2, $this->format_filter->get_decimals( 2 ) );
	}
}
