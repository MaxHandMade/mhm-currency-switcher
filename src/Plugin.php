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
	 * 🔴 PHPStan reports this property as "never read, only written", and it is
	 * WRONG — but only from where it can see. The integration harness reaches
	 * the shared instance through `ReflectionProperty( Plugin::class,
	 * 'detection' )` (MhmcsIntegrationTestCase::shared_detection_service) so
	 * that it can rewind per-request state — the request override, the
	 * geolocation memo, the queued cookie — between tests. There is no other
	 * handle on that instance. Deleting the property on the analyser's advice
	 * takes 161 integration tests with it; that was measured, not guessed.
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

		$store     = new CurrencyStore();
		$converter = new Converter( $store );

		/*
		 * ONE detection service, threaded into every collaborator below.
		 * The convert endpoint pins a currency on it for the duration of a
		 * single response; a filter holding a DIFFERENT instance would price
		 * that response in the visitor's own currency instead of the one that
		 * was asked for. Never construct a second one.
		 */
		$detection     = new DetectionService( $store, $this->conversion_context, true );
		$rate_provider = new RateProvider();

		$this->detection = $detection;

		// Register the `currency` public query var (before the main query
		// is parsed) so URL-param detection reads via get_query_var().
		$detection->register();

		// Geolocation-based currency detection.
		//
		// Deliberately the opposite of cache_compat's "absent key means
		// enabled" rule. cache_compat guards a correctness question this
		// plugin already answers server-side either way — its safe default is
		// the one that matches what a shop just upgraded from v1.0.0 was
		// already getting. auto_detect instead changes what a visitor sees
		// based on a country resolved from their IP address, so a settings row
		// that never carried this key never asked for it. Missing here means
		// "never opted in", not "opted in and forgot to say so" — which since
		// 2.2.0 is also what default_settings() seeds on a fresh activation.
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

		$order_filter = new OrderFilter( $store );
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

			\WP_CLI::add_command( 'mhmcs', $commands );
		}

		// ─── Phase 9: Scheduled tasks ────────────────────────────────
		add_action(
			RateProvider::CRON_HOOK,
			static function () use ( $store, $rate_provider ) {
				self::run_scheduled_rate_sync( $store, $rate_provider );
			}
		);

		// Schedule cron based on settings interval.
		//
		// The accepted values are RestAPI::RATE_INTERVALS, not a second literal
		// list here: this scheduler and RestAPI's own (re)scheduling used to
		// carry independent copies, so an interval added to one was armed by
		// one and torn down by the other on the very next `init`.
		$settings = get_option( 'mhmcs_settings', array() );
		$interval = is_array( $settings ) ? ( $settings['rate_update_interval'] ?? 'manual' ) : 'manual';

		if ( 'manual' !== $interval && in_array( $interval, RestAPI::RATE_INTERVALS, true ) ) {
			if ( ! wp_next_scheduled( RateProvider::CRON_HOOK ) ) {
				wp_schedule_event( time(), $interval, RateProvider::CRON_HOOK );
			}
		} else {
			wp_clear_scheduled_hook( RateProvider::CRON_HOOK );
		}
	}

	/**
	 * The hourly/twice-daily/daily rate sync, as a method rather than a body
	 * buried in a closure.
	 *
	 * 🔴 It lived inside `add_action()`'s anonymous function, which made it the
	 * one sync path no test could reach — and it was the path that kept the
	 * defect after the two reachable ones were fixed. It is also the busiest:
	 * the panel button is pressed by hand, this runs on a schedule. A body no
	 * test can call is a body no test protects.
	 *
	 * @since 1.3.1
	 *
	 * @param CurrencyStore $store         Currency store to update.
	 * @param RateProvider  $rate_provider Provider to fetch rates with.
	 * @return bool True when rates were fetched, stored and the sync recorded.
	 */
	public static function run_scheduled_rate_sync( CurrencyStore $store, RateProvider $rate_provider ): bool {
		$base = $store->get_base_currency();

		// Explicit sync: the chosen interval IS the refresh policy, so the
		// transient must not silently flatten hourly and twicedaily into daily.
		$rates = $rate_provider->fetch_rates( $base, true );

		if ( empty( $rates ) ) {
			return false;
		}

		// Automatic rates only — a manual rate is the shop owner's number and
		// the cron must not quietly replace it.
		$applied = RateProvider::apply_rates( $store->get_currencies(), $rates );

		$store->set_visible_data( $base, $applied['currencies'] );

		// Stores first, stamps second, and only if the store worked. Nothing
		// reads this return today; it exists so a test can, because the value
		// of this method is that its failure is now observable at all.
		return RateProvider::commit_sync( $store, $base );
	}
}
