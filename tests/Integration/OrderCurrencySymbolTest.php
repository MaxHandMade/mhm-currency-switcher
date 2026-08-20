<?php
/**
 * Integration tests: WooCommerce asks the currency-symbol filter about a
 * SPECIFIC currency, and the answer must be about that currency.
 *
 * `FormatFilter::get_currency_symbol()` declared the `$currency` parameter,
 * documented it as "Currency code the symbol belongs to", and then never read
 * it — every call was answered with the visitor's current symbol. On the shop
 * pages that is right, because WooCommerce is asking about the visitor's
 * currency there. On an order it is not: WooCommerce renders order totals with
 * `wc_price( $amount, [ 'currency' => $order->get_currency() ] )`, so a
 * customer whose cookie now says EUR saw last month's USD order printed with
 * the EUR symbol — the amounts of one currency wearing the sign of another.
 *
 * `OrderFilter::format_order_totals()` cannot repair this: it looks for the
 * BASE symbol and swaps that, so an order in a third currency is not a case it
 * recognises.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

/**
 * Class OrderCurrencySymbolTest
 */
class OrderCurrencySymbolTest extends MhmcsIntegrationTestCase {

	/**
	 * Base currency for this suite.
	 *
	 * @var string
	 */
	private const BASE = 'USD';

	/**
	 * Put the visitor in the configured non-base currency, the state in which
	 * the plugin legitimately owns the display.
	 *
	 * @return void
	 */
	private function visitor_in_target_currency(): void {
		// set_up() has already configured TARGET_CURRENCY with TARGET_SYMBOL
		// against a USD base; re-declaring a partial copy of it here would
		// replace the stored list with one missing its fee and rounding blocks.
		$this->set_visitor_currency( self::TARGET_CURRENCY );
		$this->reset_detection_service();
		$this->reset_conversion_context();
	}

	/**
	 * The control. Where WooCommerce is not asking about any particular
	 * currency, it is asking about the shop's current one — which for a
	 * converted visitor is theirs, and the override must still apply.
	 *
	 * Without this assertion, a "fix" that simply stopped overriding the symbol
	 * would pass the test below and break every price on the site.
	 *
	 * @return void
	 */
	public function test_prices_with_no_explicit_currency_still_use_the_visitor_symbol(): void {
		$this->visitor_in_target_currency();

		$this->assertStringContainsString(
			self::TARGET_SYMBOL,
			wc_price( 10.0 ),
			'An ordinary converted price lost the visitor currency symbol; the override is no longer doing its job.'
		);
	}

	/**
	 * The defect. An amount WooCommerce explicitly labels as another currency
	 * must not be dressed in the visitor's symbol.
	 *
	 * @return void
	 */
	public function test_an_amount_in_another_currency_keeps_its_own_symbol(): void {
		$this->visitor_in_target_currency();

		$rendered = wc_price( 10.0, array( 'currency' => self::BASE ) );

		$this->assertStringNotContainsString(
			self::TARGET_SYMBOL,
			$rendered,
			'An amount WooCommerce asked to render in ' . self::BASE . ' was printed with the visitor currency symbol. On My account -> Orders this shows one currency\'s amounts under another currency\'s sign.'
		);

		$this->assertStringContainsString(
			'$',
			html_entity_decode( $rendered ),
			'The ' . self::BASE . ' amount did not keep the ' . self::BASE . ' symbol either.'
		);
	}

	/**
	 * The same question asked the way WooCommerce asks it, with no price
	 * formatting in between, so a failure points at the filter rather than at
	 * anything wc_price() does on the way.
	 *
	 * @return void
	 */
	public function test_the_symbol_filter_answers_about_the_currency_it_was_asked_about(): void {
		$this->visitor_in_target_currency();

		$this->assertSame(
			self::TARGET_SYMBOL,
			get_woocommerce_currency_symbol( self::TARGET_CURRENCY ),
			'Asked for the visitor currency symbol, the filter did not give it.'
		);

		$this->assertNotSame(
			self::TARGET_SYMBOL,
			get_woocommerce_currency_symbol( self::BASE ),
			'Asked for the ' . self::BASE . ' symbol, the filter answered with the visitor currency symbol instead.'
		);

		$this->assertNotSame(
			self::TARGET_SYMBOL,
			get_woocommerce_currency_symbol( 'JPY' ),
			'Asked for the symbol of a currency this shop does not even use, the filter still answered with the visitor currency symbol.'
		);
	}
}
