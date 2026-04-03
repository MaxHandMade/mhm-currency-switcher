<?php
/**
 * Plugin singleton orchestrator.
 *
 * @package MhmCurrencySwitcher
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MhmCurrencySwitcher\Admin\RestAPI;
use MhmCurrencySwitcher\Admin\Settings;
use MhmCurrencySwitcher\CLI\Commands;
use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;
use MhmCurrencySwitcher\Core\GeolocationService;
use MhmCurrencySwitcher\Core\RateProvider;
use MhmCurrencySwitcher\Frontend\Enqueue;
use MhmCurrencySwitcher\Frontend\NavMenu;
use MhmCurrencySwitcher\Frontend\ProductWidget;
use MhmCurrencySwitcher\Frontend\Switcher;
use MhmCurrencySwitcher\Integration\Compatibles\MhmRentiva;
use MhmCurrencySwitcher\Integration\Elementor\ElementorIntegration;
use MhmCurrencySwitcher\Integration\WooCommerce\CartFilter;
use MhmCurrencySwitcher\Integration\WooCommerce\CouponFilter;
use MhmCurrencySwitcher\Integration\WooCommerce\FormatFilter;
use MhmCurrencySwitcher\Integration\WooCommerce\OrderFilter;
use MhmCurrencySwitcher\Integration\WooCommerce\PriceFilter;
use MhmCurrencySwitcher\Integration\WooCommerce\RestApiFilter;
use MhmCurrencySwitcher\Integration\WooCommerce\ProductPricing;
use MhmCurrencySwitcher\Integration\WooCommerce\ShippingFilter;
use MhmCurrencySwitcher\License\LicenseManager;
use MhmCurrencySwitcher\License\Mode;

/**
 * Main plugin class — singleton orchestrator.
 *
 * Boots all services and coordinates plugin lifecycle.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Private constructor to enforce singleton.
	 */
	private function __construct() {
		$this->register_hooks();
	}

	/**
	 * Bootstrap the plugin.
	 *
	 * Creates the singleton instance if not already created.
	 *
	 * @return self
	 */
	public static function bootstrap(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register core hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'init', array( $this, 'initialize_services' ), 2 );
	}

	/**
	 * Load plugin text domain.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'mhm-currency-switcher',
			false,
			dirname( MHM_CS_BASENAME ) . '/languages'
		);
	}

	/**
	 * Initialize plugin services.
	 *
	 * Wires core, WC integration, frontend, admin, license,
	 * Elementor, WP-CLI, and compatibility modules.
	 *
	 * @return void
	 */
	public function initialize_services(): void {
		// ─── Phase 1: Core services ──────────────────────────────────
		$store         = new CurrencyStore();
		$converter     = new Converter( $store );
		$detection     = new DetectionService( $store, true );
		$rate_provider = new RateProvider();

		// Geolocation (Pro only).
		if ( Mode::can_use_geolocation() ) {
			$geo_service = new GeolocationService();
			$settings    = get_option( 'mhm_currency_switcher_settings', array() );
			$geo_enabled = is_array( $settings ) && ! empty( $settings['auto_detect'] );

			$detection->set_geolocation( $geo_service, $geo_enabled );
		}

		// ─── Phase 2: WooCommerce integration ────────────────────────
		$price_filter = new PriceFilter( $converter, $detection );
		$price_filter->init();

		$format_filter = new FormatFilter( $store, $detection );
		$format_filter->init();

		$cart_filter = new CartFilter( $converter, $store, $detection );
		$cart_filter->init();

		$shipping_filter = new ShippingFilter( $converter, $detection );
		$shipping_filter->init();

		$coupon_filter = new CouponFilter( $converter, $detection );
		$coupon_filter->init();

		$order_filter = new OrderFilter( $store, $detection );
		$order_filter->init();

		// ─── Phase 3: Pro-only WC filters ────────────────────────────
		if ( Mode::can_use_rest_api_filter() ) {
			$rest_api_filter = new RestApiFilter( $converter, $store );
			$rest_api_filter->init();
		}

		$product_pricing = new ProductPricing( $store );
		$product_pricing->init();

		// ─── Phase 4: Frontend + Nav Menu ────────────────────────────
		$switcher = new Switcher( $store, $detection );

		if ( ! is_admin() ) {
			$switcher->init();

			$product_widget = new ProductWidget( $store, $converter );
			$product_widget->init();

			$enqueue = new Enqueue();
			$enqueue->init();
		}

		// Nav menu integration (admin metabox + frontend rendering).
		$nav_menu = new NavMenu( $switcher );
		$nav_menu->init();

		// ─── Phase 5: Admin + REST API ───────────────────────────────
		$rest_api = new RestAPI( $store, $converter, $rate_provider );
		$rest_api->init();

		if ( is_admin() ) {
			$admin_settings = new Settings();
			$admin_settings->init();
		}

		// ─── Phase 6: License management ─────────────────────────────
		$license_manager = LicenseManager::instance();
		$license_manager->register();

		// ─── Phase 7: Elementor (lazy-load if active) ────────────────
		if ( ElementorIntegration::is_active() ) {
			ElementorIntegration::init();
		} else {
			// Defer until Elementor loads.
			add_action(
				'elementor/loaded',
				static function () {
					ElementorIntegration::init();
				}
			);
		}

		// ─── Phase 8: WP-CLI commands ────────────────────────────────
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$commands = new Commands( $store, $converter, $rate_provider );

			\WP_CLI::add_command( 'mhm-cs', $commands );
		}

		// ─── Phase 9: Scheduled tasks (Pro only) ─────────────────────
		if ( Mode::can_use_auto_rate_update() ) {
			add_action(
				'mhm_cs_update_rates',
				static function () use ( $store, $rate_provider ) {
					$base  = $store->get_base_currency();
					$rates = $rate_provider->fetch_rates( $base );

					if ( empty( $rates ) ) {
						return;
					}

					$currencies = $store->get_currencies();

					foreach ( $currencies as &$currency ) {
						$code = $currency['code'] ?? '';

						if ( '' !== $code && isset( $rates[ $code ] ) ) {
							$currency['rate']['value'] = $rates[ $code ];
						}
					}
					unset( $currency );

					$store->set_data( $base, $currencies );
					$store->save();
				}
			);

			// Schedule cron based on settings interval.
			$settings = get_option( 'mhm_currency_switcher_settings', array() );
			$interval = is_array( $settings ) ? ( $settings['rate_update_interval'] ?? 'manual' ) : 'manual';

			if ( 'manual' !== $interval && in_array( $interval, array( 'hourly', 'twicedaily', 'daily' ), true ) ) {
				if ( ! wp_next_scheduled( 'mhm_cs_update_rates' ) ) {
					wp_schedule_event( time(), $interval, 'mhm_cs_update_rates' );
				}
			} else {
				wp_clear_scheduled_hook( 'mhm_cs_update_rates' );
			}
		}

		// ─── Phase 10: Compatibility modules (Pro only) ──────────────
		if ( Mode::is_pro() && MhmRentiva::is_active() ) {
			$rentiva_compat = new MhmRentiva();
			$rentiva_compat->init();
		}
	}
}
