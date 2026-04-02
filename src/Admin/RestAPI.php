<?php
/**
 * Admin REST API controller.
 *
 * Registers REST routes for the currency switcher admin panel
 * and a public rates endpoint for third-party consumers.
 *
 * @package MhmCurrencySwitcher\Admin
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\RateProvider;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestAPI — admin settings, currencies, and rates REST endpoints.
 *
 * @since 0.4.0
 */
final class RestAPI {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const NAMESPACE_V1 = 'mhm-currency/v1';

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const SETTINGS_KEY = 'mhm_currency_switcher_settings';

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
	 * Exchange rate provider.
	 *
	 * @var RateProvider
	 */
	private RateProvider $rate_provider;

	/**
	 * Constructor.
	 *
	 * @param CurrencyStore $store         Currency data store.
	 * @param Converter     $converter     Price conversion engine.
	 * @param RateProvider  $rate_provider Exchange rate fetcher.
	 */
	public function __construct( CurrencyStore $store, Converter $converter, RateProvider $rate_provider ) {
		$this->store         = $store;
		$this->converter     = $converter;
		$this->rate_provider = $rate_provider;
	}

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// GET /settings.
		register_rest_route(
			self::NAMESPACE_V1,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		// GET/POST /currencies.
		register_rest_route(
			self::NAMESPACE_V1,
			'/currencies',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_currencies' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_currencies' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		// POST /rates/sync.
		register_rest_route(
			self::NAMESPACE_V1,
			'/rates/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync_rates' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// GET /rates/preview.
		register_rest_route(
			self::NAMESPACE_V1,
			'/rates/preview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_rates_preview' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// GET /rates (public).
		register_rest_route(
			self::NAMESPACE_V1,
			'/rates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_public_rates' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Check if the current user can manage WooCommerce.
	 *
	 * @return bool True when the user has manage_woocommerce capability.
	 */
	public function check_admin_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * GET /settings — return plugin settings.
	 *
	 * @return WP_REST_Response Settings data.
	 */
	public function get_settings(): WP_REST_Response {
		$settings = get_option( self::SETTINGS_KEY, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings['is_pro'] = class_exists( '\MhmCurrencySwitcher\License\Mode' )
			? \MhmCurrencySwitcher\License\Mode::is_pro()
			: false;

		return new WP_REST_Response( $settings, 200 );
	}

	/**
	 * POST /settings — save plugin settings.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response Success response.
	 */
	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			return new WP_REST_Response(
				array( 'message' => 'Invalid settings data.' ),
				400
			);
		}

		// Sanitise known keys.
		$sanitized = array();

		if ( isset( $params['provider'] ) ) {
			$sanitized['provider'] = sanitize_text_field( $params['provider'] );
		}

		if ( isset( $params['cache_duration'] ) ) {
			$sanitized['cache_duration'] = absint( $params['cache_duration'] );
		}

		if ( isset( $params['auto_detect'] ) ) {
			$sanitized['auto_detect'] = (bool) $params['auto_detect'];
		}

		if ( isset( $params['round_prices'] ) ) {
			$sanitized['round_prices'] = (bool) $params['round_prices'];
		}

		if ( isset( $params['product_widget'] ) && is_array( $params['product_widget'] ) ) {
			$sanitized['product_widget'] = $params['product_widget'];
		}

		// Merge with existing settings.
		$existing = get_option( self::SETTINGS_KEY, array() );

		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$merged = array_merge( $existing, $sanitized );

		update_option( self::SETTINGS_KEY, $merged );

		return new WP_REST_Response(
			array(
				'success'  => true,
				'settings' => $merged,
			),
			200
		);
	}

	/**
	 * GET /currencies — return all configured currencies.
	 *
	 * @return WP_REST_Response Currency data.
	 */
	public function get_currencies(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'base_currency' => $this->store->get_base_currency(),
				'currencies'    => $this->store->get_currencies(),
			),
			200
		);
	}

	/**
	 * POST /currencies — save currencies with free-tier limit enforcement.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response Success response.
	 */
	public function save_currencies( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) || ! isset( $params['currencies'] ) || ! is_array( $params['currencies'] ) ) {
			return new WP_REST_Response(
				array( 'message' => 'Invalid currencies data.' ),
				400
			);
		}

		$currencies = $params['currencies'];

		// Enforce the free-tier currency limit.
		$currencies = $this->store->enforce_limit( $currencies );

		$base = $params['base_currency'] ?? $this->store->get_base_currency();

		$this->store->set_data( $base, $currencies );
		$this->store->save();

		return new WP_REST_Response(
			array(
				'success'    => true,
				'currencies' => $currencies,
			),
			200
		);
	}

	/**
	 * POST /rates/sync — trigger exchange rate synchronisation.
	 *
	 * @return WP_REST_Response Updated rates.
	 */
	public function sync_rates(): WP_REST_Response {
		$base  = $this->store->get_base_currency();
		$rates = $this->rate_provider->fetch_rates( $base );

		if ( empty( $rates ) ) {
			return new WP_REST_Response(
				array( 'message' => 'Failed to fetch exchange rates.' ),
				500
			);
		}

		// Update rates in store currencies.
		$currencies = $this->store->get_currencies();
		$updated    = array();

		foreach ( $currencies as $currency ) {
			$code = $currency['code'] ?? '';

			if ( '' !== $code && isset( $rates[ $code ] ) ) {
				$currency['rate']['value'] = $rates[ $code ];
			}

			$updated[] = $currency;
		}

		$this->store->set_data( $base, $updated );
		$this->store->save();

		return new WP_REST_Response(
			array(
				'success' => true,
				'rates'   => $rates,
			),
			200
		);
	}

	/**
	 * GET /rates/preview — preview rates for admin.
	 *
	 * Returns raw and effective (with fee) rates for all currencies.
	 *
	 * @return WP_REST_Response Rate preview data.
	 */
	public function get_rates_preview(): WP_REST_Response {
		$currencies = $this->store->get_currencies();
		$preview    = array();

		foreach ( $currencies as $currency ) {
			$code = $currency['code'] ?? '';

			if ( '' === $code ) {
				continue;
			}

			$preview[] = array(
				'code'           => $code,
				'raw_rate'       => $this->converter->get_raw_rate( $code ),
				'effective_rate' => $this->converter->get_rate( $code ),
			);
		}

		return new WP_REST_Response(
			array(
				'base_currency' => $this->store->get_base_currency(),
				'rates'         => $preview,
			),
			200
		);
	}

	/**
	 * GET /rates (public) — return base currency and enabled rates.
	 *
	 * @return WP_REST_Response Public rate data.
	 */
	public function get_public_rates(): WP_REST_Response {
		$base    = $this->store->get_base_currency();
		$enabled = $this->store->get_enabled_currencies();
		$rates   = array();

		foreach ( $enabled as $currency ) {
			$code = $currency['code'] ?? '';

			if ( '' === $code ) {
				continue;
			}

			$rates[ $code ] = $this->converter->get_rate( $code );
		}

		return new WP_REST_Response(
			array(
				'base'  => $base,
				'rates' => $rates,
			),
			200
		);
	}
}
