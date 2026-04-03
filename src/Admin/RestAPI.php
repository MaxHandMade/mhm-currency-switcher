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
use MhmCurrencySwitcher\License\LicenseManager;
use MhmCurrencySwitcher\License\Mode;
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

		// POST /license/activate.
		register_rest_route(
			self::NAMESPACE_V1,
			'/license/activate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'activate_license' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// POST /license/deactivate.
		register_rest_route(
			self::NAMESPACE_V1,
			'/license/deactivate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'deactivate_license' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// GET /license/status.
		register_rest_route(
			self::NAMESPACE_V1,
			'/license/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_license_status' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
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
			$widget = array();

			if ( isset( $params['product_widget']['enabled'] ) ) {
				$widget['enabled'] = (bool) $params['product_widget']['enabled'];
			}

			if ( isset( $params['product_widget']['show_flags'] ) ) {
				$widget['show_flags'] = (bool) $params['product_widget']['show_flags'];
			}

			if ( isset( $params['product_widget']['currencies'] ) && is_array( $params['product_widget']['currencies'] ) ) {
				$widget['currencies'] = array_values(
					array_filter(
						array_map( 'sanitize_text_field', $params['product_widget']['currencies'] ),
						function ( string $code ): bool {
							return 1 === preg_match( '/^[A-Z]{3}$/', $code );
						}
					)
				);
			}

			$sanitized['product_widget'] = $widget;
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

		$base = $params['base_currency'] ?? $this->store->get_base_currency();

		// Validate currency codes and exclude base currency.
		$currencies = array_filter(
			$currencies,
			function ( $currency ) use ( $base ): bool {
				return is_array( $currency )
					&& isset( $currency['code'] )
					&& 1 === preg_match( '/^[A-Z]{3}$/', $currency['code'] )
					&& $currency['code'] !== $base;
			}
		);
		$currencies = array_values( $currencies );

		// Enforce the free-tier currency limit (Pro users are unlimited).
		if ( Mode::is_lite() ) {
			$currencies = $this->store->enforce_limit( $currencies );
		}

		// Fill missing format data from WooCommerce defaults.
		$currencies = array_map( array( $this, 'ensure_currency_format' ), $currencies );

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

	/**
	 * POST /license/activate — activate a license key.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response Activation result.
	 */
	public function activate_license( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		$key    = sanitize_text_field( $params['license_key'] ?? '' );

		if ( '' === $key ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'License key is required.',
				),
				400
			);
		}

		$manager = LicenseManager::instance();
		$result  = $manager->activate( $key );

		$code = ! empty( $result['success'] ) ? 200 : 400;

		// Append full license details on successful activation.
		if ( ! empty( $result['success'] ) ) {
			$result['license'] = $this->build_license_payload( $manager );
		}

		return new WP_REST_Response( $result, $code );
	}

	/**
	 * POST /license/deactivate — deactivate the current license.
	 *
	 * @return WP_REST_Response Deactivation result.
	 */
	public function deactivate_license(): WP_REST_Response {
		$manager = LicenseManager::instance();
		$success = $manager->deactivate();

		return new WP_REST_Response(
			array( 'success' => $success ),
			200
		);
	}

	/**
	 * GET /license/status — return current license details.
	 *
	 * @return WP_REST_Response License status payload.
	 */
	public function get_license_status(): WP_REST_Response {
		$manager = LicenseManager::instance();

		return new WP_REST_Response(
			$this->build_license_payload( $manager ),
			200
		);
	}

	/**
	 * Build a consistent license payload for REST responses.
	 *
	 * @param LicenseManager $manager License manager instance.
	 * @return array<string, mixed> License details for the frontend.
	 */
	private function build_license_payload( LicenseManager $manager ): array {
		$data = $manager->get_stored_data();

		if ( empty( $data ) ) {
			return array(
				'status'    => 'inactive',
				'plan'      => '',
				'expiresAt' => '',
				'lastCheck' => '',
				'maskedKey' => '',
			);
		}

		$key = $data['license_key'] ?? '';

		return array(
			'status'    => $data['status'] ?? 'inactive',
			'plan'      => $data['plan'] ?? '',
			'expiresAt' => $data['expires_at'] ?? '',
			'lastCheck' => ! empty( $data['last_check'] ) ? gmdate( 'c', (int) $data['last_check'] ) : '',
			'activated' => ! empty( $data['activated'] ) ? gmdate( 'c', (int) $data['activated'] ) : '',
			'maskedKey' => $this->mask_license_key( $key ),
		);
	}

	/**
	 * Mask a license key for display (show first 4 and last 4 chars).
	 *
	 * @param string $key Full license key.
	 * @return string Masked key, e.g. "MHM-****-****-****-AB12".
	 */
	private function mask_license_key( string $key ): string {
		if ( strlen( $key ) <= 8 ) {
			return $key;
		}

		$first = substr( $key, 0, 4 );
		$last  = substr( $key, -4 );

		return $first . str_repeat( '*', strlen( $key ) - 8 ) . $last;
	}

	/**
	 * Fill missing format properties from WooCommerce currency defaults.
	 *
	 * @param array<string, mixed> $currency Currency config array.
	 * @return array<string, mixed> Currency with populated format.
	 */
	private function ensure_currency_format( array $currency ): array {
		$code = $currency['code'] ?? '';

		if ( '' === $code ) {
			return $currency;
		}

		$format = $currency['format'] ?? array();

		if ( ! is_array( $format ) || empty( $format ) ) {
			$format = array();
		}

		if ( ! isset( $format['symbol'] ) || '' === $format['symbol'] ) {
			$format['symbol'] = function_exists( 'get_woocommerce_currency_symbol' )
				? get_woocommerce_currency_symbol( $code )
				: $code;
		}

		if ( ! isset( $format['decimals'] ) ) {
			$format['decimals'] = 2;
		}

		if ( ! isset( $format['decimal_sep'] ) ) {
			$format['decimal_sep'] = wc_get_price_decimal_separator();
		}

		if ( ! isset( $format['thousand_sep'] ) ) {
			$format['thousand_sep'] = wc_get_price_thousand_separator();
		}

		if ( ! isset( $format['position'] ) ) {
			$wc_pos = get_option( 'woocommerce_currency_pos', 'left' );
			$format['position'] = $wc_pos;
		}

		$currency['format'] = $format;

		return $currency;
	}
}
