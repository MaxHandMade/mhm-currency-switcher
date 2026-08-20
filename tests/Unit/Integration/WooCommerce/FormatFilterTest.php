<?php
/**
 * Unit tests for FormatFilter.
 *
 * The class docblock promises that "the symbol and the amount can never
 * disagree within one request". These tests hold it to that, in the one case
 * where it used to be untrue: a currency the shop offers but has no rate for.
 * The amount silently stayed in the base currency while the symbol, position
 * and separators all switched — so the visitor read a base-currency NUMBER
 * dressed as a foreign one. A wrong price with nothing on screen to suggest it.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Integration\WooCommerce
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Integration\WooCommerce;

use MhmCurrencySwitcher\Core\ConversionContext;
use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;
use MhmCurrencySwitcher\Integration\WooCommerce\FormatFilter;
use PHPUnit\Framework\TestCase;

/**
 * Class FormatFilterTest
 *
 * @covers \MhmCurrencySwitcher\Integration\WooCommerce\FormatFilter
 */
class FormatFilterTest extends TestCase {

	/**
	 * Filter under test.
	 *
	 * @var FormatFilter
	 */
	private FormatFilter $format_filter;

	/**
	 * Converter over the same store.
	 *
	 * @var Converter
	 */
	private Converter $converter;

	/**
	 * Base currency symbol WooCommerce would have used.
	 *
	 * @var string
	 */
	private const BASE_SYMBOL = "\u{20BA}";

	/**
	 * Set up a TRY shop offering one priced currency and one rateless one.
	 *
	 * No `wp` action is fired, which puts ConversionContext on its `pre_wp`
	 * branch — it answers "convert", exactly as the sibling filter tests rely
	 * on. The question under test is what happens AFTER that answer.
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
					'code'    => 'USD',
					'enabled' => true,
					'rate'    => array(
						'type'  => 'manual',
						'value' => 0.03,
					),
					'fee'     => array(
						'type'  => 'none',
						'value' => 0,
					),
					'format'  => array(
						'symbol'       => '$',
						'position'     => 'left',
						'thousand_sep' => ',',
						'decimal_sep'  => '.',
						'decimals'     => 2,
					),
				),

				// Offered by the shop, never given a rate — a currency added in
				// the admin and left before the first sync, or one the rate API
				// does not carry.
				array(
					'code'    => 'GBP',
					'enabled' => true,
					'rate'    => array(
						'type'  => 'manual',
						'value' => 0,
					),
					'fee'     => array(
						'type'  => 'fixed',
						'value' => 2,
					),
					'format'  => array(
						'symbol'       => "\u{00A3}",
						'position'     => 'right',
						'thousand_sep' => '.',
						'decimal_sep'  => ',',
						'decimals'     => 0,
					),
				),
			)
		);

		$context   = new ConversionContext();
		$detection = new DetectionService( $store, $context );

		$this->converter     = new Converter( $store );
		$this->format_filter = new FormatFilter( $store, $detection, $context, $this->converter );

		unset( $_COOKIE[ DetectionService::COOKIE_NAME ] );
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
	 * A priced currency takes over the whole format. Positive control: without
	 * this, "keeps the base format" could pass by never switching at all.
	 *
	 * @return void
	 */
	public function test_a_priced_currency_switches_the_format(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$this->assertSame( 'USD', $this->format_filter->get_currency_code( 'TRY' ) );

		// Asked with 'USD', because that is the sequence in production: WooCommerce
		// resolves an unspecified currency through get_woocommerce_currency(), the
		// line above has already answered that with the visitor's code, and only
		// then is the symbol filter called. Feeding the BASE symbol in keeps the
		// assertion strong — ₺ in, $ out can only happen if the override ran.
		//
		// This line used to pass 'TRY' and still expect '$'. That is the shape of
		// the order-screen defect written down as an expectation: an amount
		// WooCommerce has explicitly labelled TRY, wearing the visitor's sign. See
		// the sibling assertion in test_an_amount_in_another_currency_keeps_its_own_symbol.
		$this->assertSame( '$', $this->format_filter->get_currency_symbol( self::BASE_SYMBOL, 'USD' ) );
		$this->assertSame( 'left', $this->format_filter->get_currency_position( false ) );
		$this->assertSame( ',', $this->format_filter->get_thousand_separator( '.' ) );
		$this->assertSame( '.', $this->format_filter->get_decimal_separator( ',' ) );
		$this->assertSame( 2, $this->format_filter->get_decimals( 2 ) );
	}

	/**
	 * The other half of the same rule: an amount WooCommerce has labelled with
	 * a currency that is not the visitor's keeps its own symbol.
	 *
	 * This is how order screens render historical totals —
	 * `wc_price( $total, [ 'currency' => $order->get_currency() ] )` — so
	 * overriding here prints one currency's amounts under another's sign, on a
	 * page the customer reads as a receipt.
	 *
	 * @return void
	 */
	public function test_an_amount_labelled_with_another_currency_keeps_its_own_symbol(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'USD';

		$this->assertSame(
			self::BASE_SYMBOL,
			$this->format_filter->get_currency_symbol( self::BASE_SYMBOL, 'TRY' ),
			'A TRY amount was given the visitor currency symbol.'
		);

		$this->assertSame(
			"\u{00A3}",
			$this->format_filter->get_currency_symbol( "\u{00A3}", 'GBP' ),
			'A GBP amount was given the visitor currency symbol, even though GBP is configured with its own.'
		);
	}

	/**
	 * 🔴 A rateless currency changes nothing about the display.
	 *
	 * Every one of these used to switch while the amount stayed in TRY.
	 *
	 * @return void
	 */
	public function test_a_rateless_currency_keeps_the_base_format(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'GBP';

		$this->assertSame( 'TRY', $this->format_filter->get_currency_code( 'TRY' ) );
		$this->assertSame( self::BASE_SYMBOL, $this->format_filter->get_currency_symbol( self::BASE_SYMBOL, 'TRY' ) );
		$this->assertFalse( $this->format_filter->get_currency_position( false ) );
		$this->assertSame( '.', $this->format_filter->get_thousand_separator( '.' ) );
		$this->assertSame( ',', $this->format_filter->get_decimal_separator( ',' ) );
		$this->assertSame( 2, $this->format_filter->get_decimals( 2 ) );
	}

	/**
	 * The amount and the symbol agree — which is the actual invariant. Asserted
	 * together on purpose: either half alone can look right while the pair lies.
	 *
	 * @return void
	 */
	public function test_the_amount_and_the_symbol_agree_for_a_rateless_currency(): void {
		$_COOKIE[ DetectionService::COOKIE_NAME ] = 'GBP';

		$amount = $this->converter->convert( 1000.0, 'GBP' );

		$this->assertEqualsWithDelta( 1000.0, $amount, 0.001 );
		$this->assertSame( self::BASE_SYMBOL, $this->format_filter->get_currency_symbol( self::BASE_SYMBOL, 'TRY' ) );
	}
}
