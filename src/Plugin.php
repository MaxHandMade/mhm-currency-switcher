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
use MhmCurrencySwitcher\Core\CacheCompatDiagnostic;
use MhmCurrencySwitcher\Core\ConversionContext;
use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;
use MhmCurrencySwitcher\Core\GeolocationService;
use MhmCurrencySwitcher\Core\RateProvider;
use MhmCurrencySwitcher\Frontend\Enqueue;
use MhmCurrencySwitcher\Frontend\NavMenu;
use MhmCurrencySwitcher\Frontend\PriceDisplayMarker;
use MhmCurrencySwitcher\Frontend\ProductWidget;
use MhmCurrencySwitcher\Frontend\Switcher;
use MhmCurrencySwitcher\Integration\Elementor\ElementorIntegration;
use MhmCurrencySwitcher\Integration\WooCommerce\CartFilter;
use MhmCurrencySwitcher\Integration\WooCommerce\CouponFilter;
use MhmCurrencySwitcher\Integration\WooCommerce\FormatFilter;
use MhmCurrencySwitcher\Integration\WooCommerce\OrderFilter;
use MhmCurrencySwitcher\Integration\WooCommerce\PriceFilter;
use MhmCurrencySwitcher\Integration\WooCommerce\RestApiFilter;
use MhmCurrencySwitcher\Integration\WooCommerce\ProductPricing;
use MhmCurrencySwitcher\Integration\WooCommerce\ShippingFilter;
use MhmCurrencySwitcher\Rest\ConvertController;

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
	 * Shared conversion-context resolver.
	 *
	 * One instance per request, created here and injected into the price
	 * surfaces — deliberately not a singleton and not a global, so tests
	 * can construct their own.
	 *
	 * @var ConversionContext|null
	 */
	private ?ConversionContext $conversion_context = null;

	/**
	 * Shared currency-detection service.
	 *
	 * Held for the same reason as the context above. The convert endpoint and
	 * every price surface must resolve the visitor's currency from the SAME
	 * instance: the endpoint pins a currency on it for the duration of one
	 * response, and a filter reading a different instance would price that
	 * response in the visitor's own currency instead of the requested one.
	 *
	 * @var DetectionService|null
	 */
	private ?DetectionService $detection = null;

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
		// Translations load automatically for directory-hosted plugins
		// since WordPress 4.6 (the text domain matches the plugin slug),
		// so no load_plugin_textdomain() call is needed here.
		add_action( 'init', array( $this, 'initialize_services' ), 2 );
	}

	/**
	 * Initialize plugin services.
	 *
	 * Wires core, WC integration, frontend, admin,
	 * Elementor, WP-CLI, and compatibility modules.
	 *
	 * @return void
	 */
	public function initialize_services(): void {
		// ─── Phase 1: Core services ──────────────────────────────────

		/*
		 * The single conversion-context resolver for this request. Every
		 * price, format, coupon, shipping and cart-fee surface receives
		 * this same instance so they cannot disagree within one request.
		 *
		 * Created first because DetectionService takes it too: the cookie
		 * priming has to agree with the price surfaces about whether this
		 * render is the cacheable one, or it attaches a visitor's currency to
		 * a response the cache hands to everybody else.
		 */
		$this->conversion_context = new ConversionContext();

		$store         = new CurrencyStore();
		$converter     = new Converter( $store );
		$detection     = new DetectionService( $store, $this->conversion_context, true );
		$rate_provider = new RateProvider();

		$this->detection = $detection;

		// Register the `currency` public query var (before the main query
		// is parsed) so URL-param detection reads via get_query_var().
		$detection->register();

		// Geolocation-based currency detection.
		$geo_service = new GeolocationService();
		$settings    = get_option( 'mhmcs_settings', array() );
		$geo_enabled = is_array( $settings ) && ! empty( $settings['auto_detect'] );

		$detection->set_geolocation( $geo_service, $geo_enabled );

		// ─── Phase 2: WooCommerce integration ────────────────────────

		/*
		 * Each of these receives the SAME ConversionContext instance created
		 * above. That shared instance is the whole point: the price, format,
		 * cart-fee, shipping and coupon surfaces have to answer "is this
		 * request converting?" identically, or one request can print a
		 * converted symbol on a base amount, or display base prices on a page
		 * whose checkout charges the converted total.
		 */
		$price_filter = new PriceFilter( $converter, $detection, $store, $this->conversion_context );
		$price_filter->init();

		$format_filter = new FormatFilter( $store, $detection, $this->conversion_context, $converter );
		$format_filter->init();

		$cart_filter = new CartFilter( $converter, $store, $detection, $this->conversion_context );
		$cart_filter->init();

		$shipping_filter = new ShippingFilter( $converter, $detection, $this->conversion_context );
		$shipping_filter->init();

		$coupon_filter = new CouponFilter( $converter, $detection, $this->conversion_context );
		$coupon_filter->init();

		$order_filter = new OrderFilter( $store, $detection );
		$order_filter->init();

		// ─── Phase 3: WooCommerce REST API currency filter ───────────
		$rest_api_filter = new RestApiFilter( $converter, $store );
		$rest_api_filter->init();

		$product_pricing = new ProductPricing( $store );
		$product_pricing->init();

		// ─── Phase 4: Frontend + Nav Menu ────────────────────────────

		/*
		 * The switcher receives the SAME context as the price surfaces above.
		 * It is what decides whether the dropdown may print the visitor's
		 * currency: on a render a page cache can store it must not, or the
		 * first visitor's active currency is served to everybody afterwards.
		 * A second context instance here could answer differently from the
		 * markers on the very same page.
		 */
		$switcher = new Switcher( $store, $detection, $this->conversion_context );

		if ( ! is_admin() ) {
			$switcher->init();

			$product_widget = new ProductWidget( $store, $converter );
			$product_widget->init();

			/*
			 * Marks base-currency prices so the client can convert them, using
			 * the SAME context instance as the price filters above: the marker
			 * means "the server left this in the base currency", so it has to
			 * be answered by whatever actually made that decision, never by a
			 * second opinion.
			 *
			 * Registered inside this front-end branch on purpose. The context
			 * also answers "base" for the admin (its branch 1, the fix that
			 * keeps the order editor from storing converted prices), and a
			 * marker there would inject markup into admin screens and
			 * admin-ajax responses that no converter script will ever read.
			 *
			 * ProductWidget above is deliberately NOT marked (design spec §4):
			 * it prints a visitor-independent list of every enabled currency
			 * from the raw `_price` meta and never calls get_price_html(), so
			 * it is already cache-safe — and the client replacing a wrapper's
			 * innerHTML would collapse that whole list into one price. The
			 * Elementor PriceDisplayWidget delegates straight to this same
			 * render_shortcode(), so it is the same output and the same
			 * exclusion.
			 */
			$price_marker = new PriceDisplayMarker( $this->conversion_context );
			$price_marker->init();

			/*
			 * The asset loader receives the same context instance, and uses
			 * it for one thing: the converter script is enqueued only where
			 * the marker above was emitted. Handing it a second context
			 * would let a page carry markers no script converts, or a
			 * script that finds nothing to do.
			 *
			 * It also receives the switcher, purely to read the currency
			 * list it renders — symbols, flags and the set of codes the
			 * client is allowed to honour — instead of assembling a second
			 * copy of that list.
			 */
			$enqueue = new Enqueue( $store, $this->conversion_context, $switcher );
			$enqueue->init();
		}

		// Nav menu integration (admin metabox + frontend rendering).
		$nav_menu = new NavMenu( $switcher );
		$nav_menu->init();

		// ─── Phase 5: Admin + REST API ───────────────────────────────
		$rest_api = new RestAPI( $store, $converter, $rate_provider );
		$rest_api->init();

		/*
		 * The public convert endpoint, which the client-side converter calls
		 * with the markers it collected from a cached page.
		 *
		 * It receives the SAME context and detection instances as the price
		 * filters above, and that is the whole reason it works: it pins a
		 * currency on the detection service and forces the context, then reads
		 * price_html back out through those very filters. Handed its own
		 * instances it would force a context nobody consults and price the
		 * response in the visitor's own currency rather than the requested one.
		 *
		 * Registered outside the front-end branch because a REST request is
		 * not a page render; the marker above is what belongs to page renders.
		 */
		$convert_controller = new ConvertController( $this->conversion_context, $detection );
		$convert_controller->init();

		if ( is_admin() ) {
			$admin_settings = new Settings();
			$admin_settings->init();
		}

		// ─── Phase 7: Elementor (lazy-load if active) ────────────────

		/*
		 * Handed over before either branch below, so a registered integration
		 * can never be missing it. Elementor rebuilds every widget per element
		 * (`new $class( $data, $args )`), which leaves no constructor to inject
		 * into; this static holder is the seam. The widget used to build its
		 * own CurrencyStore, DetectionService and ConversionContext, which
		 * meant a second geolocation lookup per Elementor page AND a context
		 * with no latch state — one that could answer differently from the
		 * rest of the request and so print the visitor's currency into a
		 * cacheable render.
		 */
		ElementorIntegration::set_switcher( $switcher );

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

		/*
		 * Watches for the one way this whole feature switches itself off
		 * without saying so: a theme that defines the WooCommerce cart constant
		 * site-wide makes every page a money context, the latch closes on the
		 * first price, and the site quietly goes back to caching one visitor's
		 * currency for everybody else. Given the SAME shared context as the
		 * price surfaces, so it reports on the decision that was actually made
		 * rather than a second opinion about it.
		 *
		 * Wired OUTSIDE the `! is_admin()` block above on purpose: it registers
		 * the front-end check AND the admin notice, and inside that block the
		 * notice would never have been registered at all — the warning would
		 * have been written, stored, and shown to nobody.
		 */
		$diagnostic = new CacheCompatDiagnostic( $this->conversion_context );
		$diagnostic->init();

		// ─── Phase 8: WP-CLI commands ────────────────────────────────
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$commands = new Commands( $store, $converter, $rate_provider );

			\WP_CLI::add_command( 'mhm-cs', $commands ); // @phpstan-ignore-line -- WP_CLI stubs not available in CI.
		}

		// ─── Phase 9: Scheduled tasks ────────────────────────────────
		add_action(
			'mhmcs_update_rates',
			static function () use ( $store, $rate_provider ) {
				$base = $store->get_base_currency();

				// Explicit sync: the chosen interval IS the refresh policy, so the
				// transient must not silently flatten hourly and twicedaily into daily.
				$rates = $rate_provider->fetch_rates( $base, true );

				if ( empty( $rates ) ) {
					return;
				}

				// Automatic rates only — a manual rate is the shop owner's
				// number and the cron must not quietly replace it.
				$applied = RateProvider::apply_rates( $store->get_currencies(), $rates );

				$store->set_visible_data( $base, $applied['currencies'] );
				$store->save();
				RateProvider::record_sync( $base );
			}
		);

		// Schedule cron based on settings interval.
		$settings = get_option( 'mhmcs_settings', array() );
		$interval = is_array( $settings ) ? ( $settings['rate_update_interval'] ?? 'manual' ) : 'manual';

		if ( 'manual' !== $interval && in_array( $interval, array( 'hourly', 'twicedaily', 'daily' ), true ) ) {
			if ( ! wp_next_scheduled( 'mhmcs_update_rates' ) ) {
				wp_schedule_event( time(), $interval, 'mhmcs_update_rates' );
			}
		} else {
			wp_clear_scheduled_hook( 'mhmcs_update_rates' );
		}
	}
}
