<?php
/**
 * Integration tests: an amount WooCommerce labels with a specific currency must
 * be FORMATTED for that currency, not just given its symbol.
 *
 * The symbol half of this was fixed already: `FormatFilter::get_currency_symbol()`
 * now returns the original when WooCommerce asks about a currency that is not the
 * visitor's. The number half was not, and it could not be fixed the same way —
 * WooCommerce passes no currency to `wc_get_price_decimal_separator()`,
 * `wc_get_price_thousand_separator()`, `wc_get_price_decimals()` or
 * `pre_option_woocommerce_currency_pos`, so those filters have nothing to
 * discriminate on and kept applying the visitor's format to every amount on the
 * page.
 *
 * On a shop whose base format differs from the visitor's, a customer reading
 * their own order history saw the right symbol on a number written in someone
 * else's convention: a 89.66 EUR order rendered as "90 €" for a visitor whose
 * currency is configured with zero decimals, or "89#66" where a separator
 * differs. The symbol says EUR and the digits say something else.
 *
 * `wc_price()` hands its fully-resolved argument array to the `wc_price_args`
 * filter, and that array carries BOTH the resolved format AND `currency` —
 * verified in WooCommerce 10.5.2, `wc-formatting-functions.php:596-611`. That is
 * the one place where the currency and the format meet, so that is where this is
 * corrected.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use WP_REST_Request;

/**
 * Class OrderCurrencyFormatTest
 */
class OrderCurrencyFormatTest extends MhmcsIntegrationTestCase {

	/**
	 * Base currency for this suite.
	 *
	 * @var string
	 */
	private const BASE = 'USD';

	/**
	 * NOTE ON THE CHOSEN GLYPHS: `~` and `_`, not `#`. The first version used
	 * `#` as the visitor's decimal separator and the base-currency assertion
	 * failed on a CORRECT render — because WooCommerce writes the dollar sign
	 * as the entity `&#36;`, and `assertStringNotContainsString( '#', ... )`
	 * matched the entity rather than the separator. The fix was right and the
	 * test was wrong. Any separator used as a leakage marker here must be a
	 * character that cannot appear inside an HTML entity.
	 *
	 * A third configured currency with a deliberately unmistakable format:
	 * three decimals and separators no real locale uses. Any of these glyphs
	 * appearing on an amount that does not belong to this currency is leakage,
	 * and no plausible default could produce them by accident.
	 *
	 * @var string
	 */
	private const ODD = 'GBP';

	/**
	 * Configure the visitor's currency with a format nothing else shares.
	 *
	 * @return void
	 */
	private function configure_visitor_with_an_odd_format(): void {
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/mhmcs/v1/currencies' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'base_currency' => self::BASE,
					'currencies'    => array(
						array(
							'code'     => self::TARGET_CURRENCY,
							'enabled'  => true,
							'rate'     => array(
								'type'  => 'manual',
								'value' => self::TARGET_RATE,
							),
							'fee'      => array(
								'type'  => 'none',
								'value' => 0,
							),
							'rounding' => array(
								'type'     => 'disabled',
								'value'    => 0,
								'subtract' => 0,
							),
							'format'   => array(
								'symbol'       => self::TARGET_SYMBOL,
								'decimals'     => 3,
								'decimal_sep'  => '~',
								'thousand_sep' => '_',
								'position'     => 'left',
							),
						),
						array(
							'code'     => self::ODD,
							'enabled'  => true,
							'rate'     => array(
								'type'  => 'manual',
								'value' => 3.0,
							),
							'fee'      => array(
								'type'  => 'none',
								'value' => 0,
							),
							'rounding' => array(
								'type'     => 'disabled',
								'value'    => 0,
								'subtract' => 0,
							),
							'format'   => array(
								'symbol'       => 'ODDX',
								'decimals'     => 0,
								'decimal_sep'  => '.',
								'thousand_sep' => ',',
								'position'     => 'left',
							),
						),
					),
				)
			)
		);

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$this->fail( 'Could not configure the test currencies: ' . (string) wp_json_encode( $response->get_data() ) );
		}

		wp_set_current_user( 0 );

		$this->set_visitor_currency( self::TARGET_CURRENCY );
		$this->reset_detection_service();
		$this->reset_conversion_context();
	}

	/**
	 * The control, and it has to come first: where WooCommerce names no
	 * currency it is asking about the shop's current one, which for a converted
	 * visitor is theirs. That format must still be applied in full.
	 *
	 * Without this, a "fix" that stopped overriding the format at all would
	 * satisfy every other test in this file and silently undo the feature.
	 *
	 * @return void
	 */
	public function test_an_amount_with_no_named_currency_keeps_the_visitor_format(): void {
		$this->configure_visitor_with_an_odd_format();

		$rendered = wc_price( 1234.5 );

		$this->assertStringContainsString( '~', $rendered, 'The visitor decimal separator is no longer applied.' );
		$this->assertStringContainsString( '_', $rendered, 'The visitor thousand separator is no longer applied.' );
		$this->assertStringContainsString(
			'500',
			$rendered,
			'The visitor decimal count (3) is no longer applied: 1234.5 should render with three decimals.'
		);
	}

	/**
	 * The defect. An amount WooCommerce labels as another CONFIGURED currency
	 * must be written in that currency's format.
	 *
	 * @return void
	 */
	public function test_an_amount_in_another_configured_currency_uses_that_currency_format(): void {
		$this->configure_visitor_with_an_odd_format();

		$rendered = wc_price( 1234.5, array( 'currency' => self::ODD ) );

		$this->assertStringNotContainsString(
			'~',
			$rendered,
			'An amount labelled ' . self::ODD . ' was written with the visitor currency decimal separator.'
		);
		$this->assertStringNotContainsString(
			'_',
			$rendered,
			'An amount labelled ' . self::ODD . ' was written with the visitor currency thousand separator.'
		);

		$this->assertStringContainsString(
			'1,235',
			$rendered,
			self::ODD . ' is configured with zero decimals and a comma, so 1234.5 must render as "1,235".'
		);
	}

	/**
	 * The same rule for the shop's own base currency, which has no row of its
	 * own in the plugin's list. Its format is WooCommerce's own setting, and
	 * that is what an order taken in the base currency must be written in.
	 *
	 * @return void
	 */
	public function test_an_amount_in_the_base_currency_uses_the_shop_format(): void {
		$this->configure_visitor_with_an_odd_format();

		update_option( 'woocommerce_price_num_decimals', 2 );
		update_option( 'woocommerce_price_decimal_sep', '.' );
		update_option( 'woocommerce_price_thousand_sep', ',' );

		$rendered = wc_price( 1234.5, array( 'currency' => self::BASE ) );

		$this->assertStringNotContainsString(
			'~',
			$rendered,
			'A base-currency amount was written with the visitor currency decimal separator.'
		);
		$this->assertStringContainsString(
			'1,234.50',
			$rendered,
			'A base-currency amount must use the shop\'s own separators and decimal count.'
		);
	}

	/**
	 * The visitor's own currency, named explicitly. WooCommerce does this too —
	 * an order placed in the currency the visitor is still browsing in — and the
	 * answer must be the visitor's format, exactly as when nothing is named.
	 *
	 * This is the boundary the discriminator is built on, so it is asserted
	 * rather than assumed.
	 *
	 * @return void
	 */
	public function test_an_amount_named_as_the_visitor_currency_keeps_that_format(): void {
		$this->configure_visitor_with_an_odd_format();

		$rendered = wc_price( 1234.5, array( 'currency' => self::TARGET_CURRENCY ) );

		$this->assertStringContainsString( '~', $rendered, 'The visitor format was dropped for their own currency.' );
		$this->assertStringContainsString( '_', $rendered, 'The visitor format was dropped for their own currency.' );
	}
}
