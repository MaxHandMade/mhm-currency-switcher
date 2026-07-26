<?php
/**
 * WooCommerce REST API filter — converts product prices via ?currency= param.
 *
 * Hooks into the WooCommerce REST API product response to convert
 * price fields when a `currency` query parameter is provided.
 *
 * @package MhmCurrencySwitcher\Integration\WooCommerce
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Integration\WooCommerce;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;

/**
 * RestApiFilter — WC REST API currency parameter support.
 *
 * When a `?currency=XXX` query parameter is present on a WC product
 * REST API request, converts price, regular_price, and sale_price
 * fields in the response to the requested currency.
 *
 * When it is absent (or names a currency the store does not offer), the
 * same fields are pinned to the base currency. The response is therefore a
 * function of the request alone, never of the calling client's cookie.
 *
 * @since 0.3.0
 */
final class RestApiFilter {

	/**
	 * Price conversion engine.
	 *
	 * @var Converter
	 */
	private Converter $converter;

	/**
	 * Currency data store.
	 *
	 * @var CurrencyStore
	 */
	private CurrencyStore $store;

	/**
	 * Constructor.
	 *
	 * @param Converter     $converter Price conversion engine.
	 * @param CurrencyStore $store     Currency data store.
	 */
	public function __construct( Converter $converter, CurrencyStore $store ) {
		$this->converter = $converter;
		$this->store     = $store;
	}

	/**
	 * Register WooCommerce REST API hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'woocommerce_rest_prepare_product_object', array( $this, 'maybe_convert_product_response' ), 100, 3 );
	}

	/**
	 * Pin the product's price fields to the currency the request asked for.
	 *
	 * Reads `?currency=XXX` from the request. A valid code (an enabled,
	 * non-base currency in the store) converts price, regular_price and
	 * sale_price and adds a `currency_code` field. Anything else — including
	 * no parameter at all — pins those fields to the base currency and adds
	 * no `currency_code`; see resolve_requested_currency().
	 *
	 * @param mixed $response WP_REST_Response instance.
	 * @param mixed $product  WC_Product instance.
	 * @param mixed $request  WP_REST_Request instance.
	 * @return mixed Modified response, or original when no conversion needed.
	 */
	public function maybe_convert_product_response( $response, $product, $request ) {
		$code = $this->resolve_requested_currency( $request );

		$data = $response->get_data();

		/*
		 * Rebuild the price fields from the product's raw ("edit" context)
		 * values, not from $data. The response was built by the WC REST
		 * controller calling getters like $product->get_price() in their
		 * default "view" context, which already runs PriceFilter -- if the
		 * requesting visitor also carries a currency cookie, $data[$field]
		 * may already be converted. "edit" context bypasses display filters
		 * entirely, so it is always the true base-currency amount regardless
		 * of the visitor's own currency state. That is what makes both
		 * branches below deterministic: converting from it cannot stack a
		 * second conversion, and pinning to it cannot leak the caller's
		 * cookie into the answer.
		 */
		$price_fields = array(
			'price'         => $this->get_raw_price( $product, 'get_price' ),
			'regular_price' => $this->get_raw_price( $product, 'get_regular_price' ),
			'sale_price'    => $this->get_raw_price( $product, 'get_sale_price' ),
		);

		foreach ( $price_fields as $field => $raw_value ) {
			if ( ! isset( $data[ $field ] ) || '' === $data[ $field ] ) {
				continue;
			}

			if ( null === $raw_value || '' === $raw_value ) {
				continue;
			}

			$data[ $field ] = null === $code
				? $raw_value
				: (string) $this->converter->convert_with_rounding( (float) $raw_value, $code );
		}

		// Only a request that actually asked for a currency gets one reported.
		if ( null !== $code ) {
			$data['currency_code'] = $code;
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Resolve the currency this request asked for, if any.
	 *
	 * Returns null whenever the answer must be the base currency: no
	 * parameter, a malformed one, one naming a currency the store does not
	 * offer, or the base currency itself.
	 *
	 * That "no parameter means base" rule is a deliberate behaviour change
	 * to the public wc/v3 product output (design spec §8.4, owner's
	 * decision). Previously the response carried whatever PriceFilter's
	 * cookie- and geolocation-driven detection had produced, so what the API
	 * returned depended on the state of whoever was calling — for the
	 * server-to-server integrations wc/v3 exists for, that is unpredictable.
	 * A wc/v3 client now states the currency it wants or gets the base one.
	 *
	 * @param mixed $request WP_REST_Request instance.
	 * @return string|null Validated currency code, or null for base currency.
	 */
	private function resolve_requested_currency( $request ): ?string {
		$currency_param = $request->get_param( 'currency' );

		if ( empty( $currency_param ) || ! is_string( $currency_param ) ) {
			return null;
		}

		$code = DetectionService::sanitize_currency_code( $currency_param );

		if ( null === $code ) {
			return null;
		}

		// The base currency is a no-op, not a conversion.
		if ( $code === $this->store->get_base_currency() ) {
			return null;
		}

		// Must be a known and enabled currency.
		$currency = $this->store->get_currency( $code );

		if ( null === $currency || empty( $currency['enabled'] ) ) {
			return null;
		}

		return $code;
	}

	/**
	 * Read a raw, unfiltered ("edit" context) price getter from a product.
	 *
	 * "edit" context bypasses WooCommerce's display filters -- including
	 * PriceFilter's own visitor-currency conversion -- so the value
	 * returned here is always the true base-currency amount, regardless of
	 * whether the requesting visitor also carries a currency cookie. Using
	 * this instead of the already-prepared response data is what prevents
	 * this filter from stacking a second conversion on top of one
	 * PriceFilter already applied.
	 *
	 * @param mixed  $product WC_Product instance.
	 * @param string $method  Getter method name (e.g. 'get_price').
	 * @return string|null Raw price string, or null when unavailable.
	 */
	private function get_raw_price( $product, string $method ): ?string {
		if ( ! is_object( $product ) || ! method_exists( $product, $method ) ) {
			return null;
		}

		$value = $product->$method( 'edit' );

		return is_scalar( $value ) ? (string) $value : null;
	}
}
