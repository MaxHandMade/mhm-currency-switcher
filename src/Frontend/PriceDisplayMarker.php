<?php
/**
 * Cache-mode price marker.
 *
 * @package MhmCurrencySwitcher\Frontend
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Frontend;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MhmCurrencySwitcher\Core\ConversionContext;
use WC_Product;

/**
 * Wraps display-context prices so the browser can convert them.
 *
 * In cache-compatibility mode a catalogue page is rendered — and cached — in
 * the shop's base currency, identically for every visitor. This class marks
 * each price in that page with the product it belongs to, so the client-side
 * converter can collect the markers, ask the convert endpoint for the visitor's
 * own currency in one request, and replace the amounts in place.
 *
 * The marker is emitted ONLY where the server did not convert, and only on a
 * front-end page render. Those are two separate conditions and both matter:
 *
 * - A marker on output the server ALREADY converted is not a cosmetic slip.
 *   The client would multiply by the exchange rate a second time and show the
 *   visitor a price that exists in no currency at all. ConversionContext's
 *   "convert" branches — money context, logged-in visitor, cache compatibility
 *   switched off — are therefore each excluded here, and each has its own
 *   regression test.
 * - "Did not convert" alone is not sufficient, because ConversionContext also
 *   answers "base" for admin screens, for wc/v3 REST reads and for cron and
 *   WP-CLI (branches 1-3 of its table). None of those produce a page the
 *   converter script will ever run against, and a marker in them would be pure
 *   damage: markup injected into the order editor, or into the `price_html`
 *   field of a wc/v3 API response that a third-party system parses.
 *
 * The second condition is enforced without restating the decision table. The
 * `wp` action fires only while WordPress is serving a front-end page request:
 * not in wp-admin, not in admin-ajax, not in REST, not in cron and not under
 * WP-CLI. So `did_action( 'wp' )` separates the one branch that renders a
 * cacheable page from the three that do not, and the class registers itself
 * only outside the admin on top of that.
 *
 * @since 1.1.0
 */
final class PriceDisplayMarker {

	/**
	 * CSS class the client-side converter looks for.
	 *
	 * @var string
	 */
	const CSS_CLASS = 'mhmcs-price';

	/**
	 * Attribute carrying the product (or variation) ID.
	 *
	 * @var string
	 */
	const ID_ATTRIBUTE = 'data-mhmcs-product';

	/**
	 * Shared conversion-context resolver.
	 *
	 * @var ConversionContext
	 */
	private ConversionContext $context;

	/**
	 * Constructor.
	 *
	 * @param ConversionContext $context The request's single context resolver,
	 *                                   the same instance every price surface
	 *                                   uses.
	 */
	public function __construct( ConversionContext $context ) {
		$this->context = $context;
	}

	/**
	 * Register the filter.
	 *
	 * Runs at the very end of the filter chain so the marker wraps the FINAL
	 * price HTML. Anything added after this point would sit outside the
	 * wrapper and simply not be converted, which is the safe direction, but a
	 * filter that ran between a lower priority and ours could otherwise be
	 * swallowed by the client's replacement.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'woocommerce_get_price_html', array( $this, 'wrap_price_html' ), PHP_INT_MAX, 2 );
	}

	/**
	 * Wrap a base-currency price in its marker.
	 *
	 * The incoming HTML is passed through byte for byte. It is WooCommerce's
	 * own generated markup — `wc_price()` escapes the amount and the currency
	 * symbol as it builds them, and every WooCommerce template echoes the
	 * result of get_price_html() unescaped for exactly that reason. Re-escaping
	 * it here would print the tags literally, and running it through
	 * wp_kses_post() would strip the `<bdi>` and `<del>`/`<ins>` structure the
	 * price depends on. The only thing this method contributes is the wrapper,
	 * whose single dynamic value — the product ID — goes through esc_attr().
	 *
	 * @param string $price_html Price HTML produced by WooCommerce.
	 * @param mixed  $product    WC_Product instance the price belongs to.
	 * @return mixed Marked-up price HTML string, or the input handed back
	 *               untouched — including the non-string an upstream filter
	 *               should never produce but might.
	 */
	public function wrap_price_html( $price_html, $product ) {
		if ( ! is_string( $price_html ) || '' === $price_html ) {
			// Nothing to mark. An empty marker would still be collected by the
			// client and could put a price where the shop shows none.
			return $price_html;
		}

		if ( ! $product instanceof WC_Product ) {
			return $price_html;
		}

		// Only a front-end page render can be cached and later corrected by the
		// converter script; see the class docblock for why this is not a
		// restatement of the decision table.
		if ( ! did_action( 'wp' ) ) {
			return $price_html;
		}

		// The server already converted this amount: marking it would have the
		// client convert it a second time.
		if ( $this->context->should_convert() ) {
			return $price_html;
		}

		return '<span class="' . self::CSS_CLASS . '" ' . self::ID_ATTRIBUTE . '="' . esc_attr( (string) $product->get_id() ) . '">'
			. $price_html
			. '</span>';
	}
}
