<?php
/**
 * WooCommerce price filter — converts product prices to active currency.
 *
 * Hooks into all WooCommerce product price filters to apply the
 * current visitor's currency conversion via the Converter engine.
 *
 * @package MhmCurrencySwitcher\Integration\WooCommerce
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Integration\WooCommerce;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MhmCurrencySwitcher\Core\ConversionContext;
use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;

/**
 * PriceFilter — WooCommerce product price conversion hooks.
 *
 * Registers filters at priority 100 on every WooCommerce price getter
 * so that product prices are converted from the base currency to the
 * visitor's selected currency.
 *
 * @since 0.1.0
 */
final class PriceFilter {

	/**
	 * Price conversion engine.
	 *
	 * @var Converter
	 */
	private Converter $converter;

	/**
	 * Currency detection service.
	 *
	 * @var DetectionService
	 */
	private DetectionService $detection;

	/**
	 * Currency data store.
	 *
	 * @var CurrencyStore
	 */
	private CurrencyStore $store;

	/**
	 * Shared conversion-context resolver.
	 *
	 * @var ConversionContext
	 */
	private ConversionContext $context;

	/**
	 * Constructor.
	 *
	 * @param Converter         $converter Price conversion engine.
	 * @param DetectionService  $detection Currency detection service.
	 * @param CurrencyStore     $store     Currency data store.
	 * @param ConversionContext $context   Shared conversion-context resolver.
	 */
	public function __construct( Converter $converter, DetectionService $detection, CurrencyStore $store, ConversionContext $context ) {
		$this->converter = $converter;
		$this->detection = $detection;
		$this->store     = $store;
		$this->context   = $context;
	}

	/**
	 * Register all WooCommerce price hooks at priority 100.
	 *
	 * @return void
	 */
	public function init(): void {
		// Simple and variable product prices.
		add_filter( 'woocommerce_product_get_price', array( $this, 'convert_price' ), 100, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( $this, 'convert_price' ), 100, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'convert_sale_price' ), 100, 2 );

		// Variation-level prices.
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'convert_price' ), 100, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'convert_price' ), 100, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'convert_sale_price' ), 100, 2 );

		// Variable product price ranges (min/max across variations).
		add_filter( 'woocommerce_variation_prices_price', array( $this, 'convert_variation_price' ), 100, 3 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( $this, 'convert_variation_price' ), 100, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'convert_variation_price' ), 100, 3 );

		// Variation price cache hash — bust per currency.
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'add_currency_to_hash' ), 100, 3 );

		// Variation selection on a cacheable page — take the AJAX route.
		add_filter( 'woocommerce_ajax_variation_threshold', array( $this, 'force_ajax_variation_path' ), 100, 2 );
	}

	/**
	 * Convert a product price to the current currency.
	 *
	 * Checks for a per-product fixed price first.
	 * Falls back to automatic exchange rate conversion.
	 *
	 * The context is consulted per CALL, never at registration time: cart and
	 * checkout shortcodes declare a money context in the middle of rendering,
	 * so a decision taken when the hook was added would be stale by the time
	 * the price is read.
	 *
	 * @param string|float $price   Product price (may be '' or numeric string).
	 * @param mixed        $product WC_Product instance.
	 * @return string|float Converted price, or original when base currency.
	 */
	public function convert_price( $price, $product ) {
		if ( '' === $price ) {
			return '';
		}

		if ( ! $this->context->should_convert() ) {
			return $price;
		}

		if ( $this->detection->is_base_currency() ) {
			return $price;
		}

		$currency = $this->detection->get_current_currency();

		$fixed = $this->get_product_fixed_price( $product, $currency );
		if ( null !== $fixed ) {
			return $fixed;
		}

		return $this->converter->convert_with_rounding( (float) $price, $currency );
	}

	/**
	 * Convert a sale price to the current currency.
	 *
	 * Identical to convert_price except that an empty sale price
	 * returns '' (not 0) to indicate "no sale price set".
	 *
	 * @param string|float $price   Sale price (may be '' when no sale).
	 * @param mixed        $product WC_Product instance (unused but required by hook).
	 * @return string|float Converted sale price, or '' when not on sale.
	 */
	public function convert_sale_price( $price, $product ) {
		if ( '' === $price ) {
			return '';
		}

		if ( ! $this->context->should_convert() ) {
			return $price;
		}

		if ( $this->detection->is_base_currency() ) {
			return $price;
		}

		$currency = $this->detection->get_current_currency();

		$fixed = $this->get_product_fixed_price( $product, $currency );
		if ( null !== $fixed ) {
			return $fixed;
		}

		return $this->converter->convert_with_rounding( (float) $price, $currency );
	}

	/**
	 * Convert a variation price within a variable product.
	 *
	 * Checks for a per-variation fixed price first.
	 * Falls back to automatic exchange rate conversion.
	 *
	 * @param string|float $price     Variation price.
	 * @param mixed        $variation WC_Product_Variation instance.
	 * @param mixed        $product   Parent WC_Product_Variable instance.
	 * @return string|float Converted variation price.
	 */
	public function convert_variation_price( $price, $variation, $product ) {
		if ( '' === $price ) {
			return '';
		}

		if ( ! $this->context->should_convert() ) {
			return $price;
		}

		if ( $this->detection->is_base_currency() ) {
			return $price;
		}

		$currency = $this->detection->get_current_currency();

		$fixed = $this->get_product_fixed_price( $variation, $currency );
		if ( null !== $fixed ) {
			return $fixed;
		}

		return $this->converter->convert_with_rounding( (float) $price, $currency );
	}

	/**
	 * Get the fixed price for a product in a given currency.
	 *
	 * Returns null if no fixed price is set, allowing automatic
	 * conversion to proceed.
	 *
	 * @param mixed  $product  WC_Product instance.
	 * @param string $currency Currency code.
	 * @return float|null Fixed price, or null to use auto-conversion.
	 */
	private function get_product_fixed_price( $product, string $currency ): ?float {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return null;
		}

		return ProductPricing::get_fixed_price( $product->get_id(), $currency );
	}

	/**
	 * Make WooCommerce fetch variation prices over AJAX on a cacheable page.
	 *
	 * WooCommerce decides whether to embed its variations JSON with
	 * `count( $product->get_children() ) <= $threshold`, and prints
	 * `data-product_variations="false"` when it does not. Its own variation.js
	 * reads `price_html` out of that JSON and writes it into the DOM on every
	 * selection.
	 *
	 * On a render the server did NOT convert, that JSON holds base amounts —
	 * and although each `price_html` inside it does carry a marker, the marker
	 * is escaped inside an HTML attribute, so price-converter.js cannot see it
	 * with a DOM query and cannot convert it. The moment the visitor picks a
	 * variation, WooCommerce writes a base-currency price into a page that has
	 * already been converted, and nothing runs afterwards to correct it.
	 * Suppressing the JSON leaves exactly one route for the price —
	 * `wc-ajax=get_variation`, which is a money context (decision 5) and so
	 * converts server-side, keeping formatting and rounding in PHP.
	 *
	 * The predicate is ConversionContext::should_convert(), the same question
	 * every other price surface asks, and deliberately NOT a second reading of
	 * the `cache_compat` setting: this plugin has shipped two bugs from
	 * differently-phrased conditions drifting apart. It also happens to be the
	 * correct question. When the server DID convert — money context, logged-in
	 * visitor, cache compatibility off — the embedded JSON is already in the
	 * visitor's currency, so forcing AJAX would buy nothing and cost one
	 * request per selection.
	 *
	 * Latch safety: this runs during product-page render, after `wp`, and
	 * reading the decision can only latch it towards "convert". The dangerous
	 * direction would be forcing AJAX on a page the server actually converted,
	 * and that cannot happen here — `wp_enqueue_scripts` already asked the same
	 * question earlier in `wp_head`, so a request that converts has latched
	 * before this filter ever runs.
	 *
	 * Returning 0 rather than a negative number is deliberate. The comparison
	 * is `<=`, so 0 forces AJAX for every product with at least one variation —
	 * every product that has a price to leak — while a childless variable
	 * product still embeds its empty array. WooCommerce's template selects its
	 * "out of stock and unavailable" branch on
	 * `empty( $available_variations ) && false !== $available_variations`;
	 * printing `false` there would silently replace that message with an
	 * attribute form for a product nobody can buy, for no benefit, since a
	 * product with no variations has no price to expose.
	 *
	 * Registered at priority 100 like the rest of this class, so an explicit
	 * site-level override at a later priority still wins — the same escape
	 * hatch `mhmcs_should_convert` provides for the decision itself.
	 *
	 * @param mixed $threshold Maximum child count WooCommerce will embed.
	 * @param mixed $product   WC_Product_Variable instance (unused; the
	 *                         decision is per REQUEST, not per product).
	 * @return mixed Zero to force the AJAX path, or the incoming threshold
	 *               untouched when the server converts.
	 */
	public function force_ajax_variation_path( $threshold, $product = null ) {
		if ( $this->context->should_convert() ) {
			return $threshold;
		}

		return 0;
	}

	/**
	 * Append the conversion CONTEXT to the variation prices hash.
	 *
	 * WooCommerce caches variation price ranges in a transient keyed by this
	 * hash, so whatever the hash encodes is what separates one cached range
	 * from another.
	 *
	 * The currency code alone is not enough. On a cacheable display render the
	 * visitor's detected currency is still, say, EUR, but the amounts computed
	 * are the BASE ones — because convert_variation_price() asked the context
	 * and was told not to convert. Keyed by "EUR", those base amounts would be
	 * stored under the exact key every later converted read looks up: the
	 * convert endpoint, a wc-ajax call, the cart. Each of them would get a
	 * cache hit and read base amounts back, so variable products would show
	 * unconverted prices in every currency, permanently, with nothing logged
	 * anywhere.
	 *
	 * Hence two distinct key shapes:
	 *
	 *   not converting -> "<base>:display"  (e.g. "USD:display")
	 *   converting     -> "<target>"        (e.g. "EUR")
	 *
	 * The `:display` suffix cannot collide with an ISO 4217 code, so the two
	 * buckets can never be confused for one another.
	 *
	 * @param array<int, string> $hash        Hash components array.
	 * @param mixed              $product     WC_Product_Variable instance.
	 * @param bool               $for_display Whether prices are for display.
	 * @return array<int, string> Modified hash with the context appended.
	 */
	public function add_currency_to_hash( array $hash, $product, bool $for_display ): array {
		if ( ! $this->context->should_convert() ) {
			$hash[] = $this->store->get_base_currency() . ':display';

			return $hash;
		}

		$hash[] = $this->detection->get_current_currency();

		return $hash;
	}
}
