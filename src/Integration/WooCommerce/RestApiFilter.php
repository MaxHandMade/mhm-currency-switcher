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

/**
 * RestApiFilter — WC REST API currency parameter support.
 *
 * When a `?currency=XXX` query parameter is present on a WC product
 * REST API request, converts price, regular_price, and sale_price
 * fields in the response to the requested currency.
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
	 * Convert product price fields when a valid currency parameter is present.
	 *
	 * Reads `?currency=XXX` from the request. If the currency code is valid
	 * (exists as an enabled currency in the store and is not the base currency),
	 * converts price, regular_price, and sale_price fields in the response data.
	 * Also adds a `currency_code` field to the response.
	 *
	 * @param mixed $response WP_REST_Response instance.
	 * @param mixed $product  WC_Product instance.
	 * @param mixed $request  WP_REST_Request instance.
	 * @return mixed Modified response, or original when no conversion needed.
	 */
	public function maybe_convert_product_response( $response, $product, $request ) {
		$currency_param = $request->get_param( 'currency' );

		if ( empty( $currency_param ) || ! is_string( $currency_param ) ) {
			return $response;
		}

		$code = strtoupper( trim( $currency_param ) );

		// Validate: must be exactly 3 uppercase letters.
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $code ) ) {
			return $response;
		}

		// If it is the base currency, no conversion needed.
		if ( $code === $this->store->get_base_currency() ) {
			return $response;
		}

		// Must be a known and enabled currency.
		$currency = $this->store->get_currency( $code );

		if ( null === $currency || empty( $currency['enabled'] ) ) {
			return $response;
		}

		$data = $response->get_data();

		/*
		 * Convert price fields from the product's raw ("edit" context)
		 * values, not from $data. The response was built by the WC REST
		 * controller calling getters like $product->get_price() in their
		 * default "view" context, which already runs PriceFilter -- if the
		 * requesting visitor also carries a currency cookie, $data[$field]
		 * would already be converted once. Re-converting that value here
		 * would stack a second conversion on top of the first. "edit"
		 * context bypasses display filters entirely, so it is always the
		 * true base-currency amount regardless of the visitor's own
		 * currency state.
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

			$data[ $field ] = (string) $this->converter->convert_with_rounding(
				(float) $raw_value,
				$code
			);
		}

		// Add the currency code to the response.
		$data['currency_code'] = $code;

		$response->set_data( $data );

		return $response;
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
