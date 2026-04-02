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

		// Convert price fields.
		$price_fields = array( 'price', 'regular_price', 'sale_price' );

		foreach ( $price_fields as $field ) {
			if ( isset( $data[ $field ] ) && '' !== $data[ $field ] ) {
				$data[ $field ] = (string) $this->converter->convert_with_rounding(
					(float) $data[ $field ],
					$code
				);
			}
		}

		// Add the currency code to the response.
		$data['currency_code'] = $code;

		$response->set_data( $data );

		return $response;
	}
}
