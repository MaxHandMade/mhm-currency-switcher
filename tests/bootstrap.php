<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package MhmCurrencySwitcher\Tests
 */

declare(strict_types=1);

// Load Composer autoloader.
$mhmcs_autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $mhmcs_autoloader ) ) {
	require_once $mhmcs_autoloader;
}

/*
 * Define ABSPATH when running outside WordPress so that source files
 * with the `defined( 'ABSPATH' ) || exit;` guard do not terminate
 * the process during autoloading.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

/*
 * Define plugin constants needed by frontend classes.
 */
if ( ! defined( 'MHMCS_URL' ) ) {
	define( 'MHMCS_URL', 'https://example.com/wp-content/plugins/mhm-currency-switcher/' );
}

if ( ! defined( 'MHMCS_VERSION' ) ) {
	define( 'MHMCS_VERSION', '0.2.0' );
}

/*
 * Minimal WordPress function stubs for unit tests that do not
 * require a full WordPress environment. Each stub mirrors the
 * real function signature closely enough for test assertions.
 */
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $data ) {
		return $data;
	}
}

if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		if ( ! isset( $GLOBALS['__mhmcs_test_options'] ) ) {
			return $default;
		}
		return array_key_exists( $option, $GLOBALS['__mhmcs_test_options'] )
			? $GLOBALS['__mhmcs_test_options'][ $option ]
			: $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		if ( ! isset( $GLOBALS['__mhmcs_test_options'] ) ) {
			$GLOBALS['__mhmcs_test_options'] = array();
		}

		// Write COUNTER, opt-in like the transient store: a test that cares how
		// often production writes initialises it, everyone else is unaffected.
		// Code that runs on every front-end page view has to be able to prove
		// it is not writing a row per request.
		if ( isset( $GLOBALS['__mhmcs_test_option_writes'] ) ) {
			++$GLOBALS['__mhmcs_test_option_writes'];
		}

		/*
		 * FAILURE injection, opt-in like the counter above. A store that
		 * answers "saved" when the row never landed is a defect class of its
		 * own, and the only way to test a caller's handling of it is to make a
		 * write genuinely fail: return false AND leave the stored value alone.
		 */
		if ( isset( $GLOBALS['__mhmcs_test_option_write_fails'] )
			&& in_array( $option, (array) $GLOBALS['__mhmcs_test_option_write_fails'], true ) ) {
			return false;
		}

		/*
		 * 🔴 WordPress returns FALSE when the new value equals the stored one —
		 * nothing was written because nothing needed to be. This stub used to
		 * return true unconditionally, which meant no test could ever see the
		 * trap: a caller that reads the bare return as "did it persist?" turns
		 * an idempotent save into a reported failure. Modelled here so the
		 * harness can tell a no-op apart from a failure, because production
		 * has to.
		 */
		if ( array_key_exists( $option, $GLOBALS['__mhmcs_test_options'] )
			&& $GLOBALS['__mhmcs_test_options'][ $option ] === $value ) {
			return false;
		}

		$GLOBALS['__mhmcs_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {}
}

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {
		return true;
	}
}

if ( ! function_exists( 'get_woocommerce_currency_symbols' ) ) {
	/*
	 * WooCommerce's STATIC code => symbol table, as HTML entities, exactly
	 * as the real function returns them. This is the honest source for a
	 * stored configuration default: unlike the singular helper below it is
	 * not rewritten per active currency.
	 */
	function get_woocommerce_currency_symbols() {
		return array(
			'USD' => '&#36;',
			'EUR' => '&euro;',
			'GBP' => '&pound;',
			'TRY' => '&#8378;',
		);
	}
}

if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
	/*
	 * 🔴 Deliberately POISONED, because that is what the real one does on a
	 * real site. It runs the `woocommerce_currency_symbol` filter, and any
	 * currency plugin — including this one — hooks that filter to answer
	 * with the ACTIVE currency's symbol. Measured on a live shop running
	 * YayCurrency alongside this plugin: it returned the Turkish Lira sign
	 * for USD, EUR, GBP and JPY alike. Asking it for a currency's symbol
	 * and storing the answer is therefore unsound, and the tests say so by
	 * making the stub lie the same way.
	 *
	 * $GLOBALS['__mhmcs_test_symbol_filter'] sets the poisoned answer; with
	 * it unset the stub behaves like unfiltered WooCommerce.
	 */
	function get_woocommerce_currency_symbol( $code = '' ) {
		if ( isset( $GLOBALS['__mhmcs_test_symbol_filter'] ) ) {
			return $GLOBALS['__mhmcs_test_symbol_filter'];
		}

		$symbols = get_woocommerce_currency_symbols();

		return isset( $symbols[ $code ] ) ? $symbols[ $code ] : $code;
	}
}

if ( ! function_exists( 'wc_get_price_decimal_separator' ) ) {
	function wc_get_price_decimal_separator() {
		return '.';
	}
}

if ( ! function_exists( 'wc_get_price_thousand_separator' ) ) {
	function wc_get_price_thousand_separator() {
		return ',';
	}
}

if ( ! function_exists( 'wc_get_price_decimals' ) ) {
	function wc_get_price_decimals() {
		return 2;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	// add_filter() above is a no-op, so nothing this removes was ever
	// actually registered; this only needs to exist and not fatal.
	function remove_filter( $hook_name, $callback, $priority = 10 ) {
		return true;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text, $remove_breaks = false ) {
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		$text = strip_tags( $text );

		if ( $remove_breaks ) {
			$text = (string) preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}

		return trim( $text );
	}
}

if ( ! function_exists( 'wc_price' ) ) {
	/*
	 * Minimal formatter, not a WooCommerce reimplementation. Real wc_price()
	 * is driven by the woocommerce_currency_symbol and wc_price_args filters
	 * that PreviewRenderer::render() overrides per row -- but add_filter()
	 * above is a no-op and apply_filters() only replays a single callback
	 * assigned directly to $GLOBALS['__mhmcs_test_filters'], so a closure
	 * registered via add_filter() never reaches an apply_filters() call.
	 * PreviewRenderer's per-row overrides therefore cannot be exercised here.
	 * This stub only needs to hand back a non-empty, plausible string so
	 * code paths that call PreviewRenderer::render() can run in the unit
	 * suite; exact formatting is covered by PreviewRendererParityTest
	 * against real WooCommerce in the integration suite.
	 */
	function wc_price( $price, $args = array() ) {
		$currency = ( is_array( $args ) && isset( $args['currency'] ) ) ? (string) $args['currency'] : '';
		$symbol   = get_woocommerce_currency_symbol( $currency );

		return $symbol . number_format(
			(float) $price,
			wc_get_price_decimals(),
			wc_get_price_decimal_separator(),
			wc_get_price_thousand_separator()
		);
	}
}

if ( ! function_exists( 'get_woocommerce_currencies' ) ) {
	/*
	 * Minimal code => name map covering the currencies used across the
	 * unit test fixtures. Real WooCommerce provides the full ISO 4217
	 * list; this stub only needs to satisfy Switcher::build_options_list().
	 */
	function get_woocommerce_currencies() {
		return array(
			'USD' => 'US Dollar',
			'EUR' => 'Euro',
			'GBP' => 'Pound Sterling',
			'TRY' => 'Turkish Lira',
		);
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/*
	 * Post-meta stub used by ProductWidget/ProductPricing unit tests that
	 * exercise the global $product fallback path. Tests seed values via
	 * $GLOBALS['__mhmcs_test_post_meta'][ $post_id ][ $key ]; only the
	 * $single=true call shape used by production code is supported.
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		if ( isset( $GLOBALS['__mhmcs_test_post_meta'][ $post_id ][ $key ] ) ) {
			$value = $GLOBALS['__mhmcs_test_post_meta'][ $post_id ][ $key ];
			return $single ? $value : array( $value );
		}
		return $single ? '' : array();
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return false;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = array(), $override = false ) {}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		unset( $GLOBALS['__mhmcs_test_transients'][ $transient ] );

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		if ( isset( $GLOBALS['__mhmcs_test_options'] ) ) {
			unset( $GLOBALS['__mhmcs_test_options'][ $option ] );
		}
		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = array() ) {
		return 0;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		return false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
		return true;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/*
	 * Fails by default — no unit test may reach the network. A test that needs
	 * a SUCCESSFUL fetch (to exercise what happens after rates arrive) queues
	 * one response in $GLOBALS['__mhmcs_test_http_get_response'], the same
	 * opt-in shape wp_remote_request() below already uses. Everyone who does
	 * not set it keeps the old behaviour exactly.
	 */
	function wp_remote_get( $url, $args = array() ) {
		if ( isset( $GLOBALS['__mhmcs_test_http_get_response'] ) ) {
			$resp = $GLOBALS['__mhmcs_test_http_get_response'];
			unset( $GLOBALS['__mhmcs_test_http_get_response'] );
			return $resp;
		}

		return new \WP_Error( 'http_request_failed', 'Unit test stub — no HTTP.' );
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		return array();
	}
}

if ( ! function_exists( 'wp_remote_request' ) ) {
	/*
	 * Stateful HTTP stub used by Phase C tests. Tests set
	 * $GLOBALS['__mhmcs_test_http_response'] to control the return value,
	 * and can inspect $GLOBALS['__mhmcs_test_http_last'] for request
	 * assertions (url + args). Resets itself after each call.
	 */
	function wp_remote_request( $url, $args = array() ) {
		$GLOBALS['__mhmcs_test_http_last'] = array(
			'url'  => (string) $url,
			'args' => is_array( $args ) ? $args : array(),
		);

		if ( isset( $GLOBALS['__mhmcs_test_http_response'] ) ) {
			$resp                                    = $GLOBALS['__mhmcs_test_http_response'];
			$GLOBALS['__mhmcs_test_http_response'] = null;
			return $resp;
		}

		return new \WP_Error( 'http_request_failed', 'Unit test stub — no response queued.' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '', $scheme = null ) {
		return 'https://example.test' . $path;
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '', $scheme = null ) {
		return 'https://example.test' . $path;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '', $filter = 'raw' ) {
		if ( 'version' === $show ) {
			return '6.4.0';
		}
		return '';
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}


if ( ! function_exists( 'get_transient' ) ) {
	/*
	 * Transient stubs, opt-in on purpose. They stay the original "always a
	 * miss" no-ops until a test initialises
	 * $GLOBALS['__mhmcs_test_transients'] as an array, at which point they
	 * behave like a real store for that test. Making them stateful for
	 * everybody would silently change what every existing test exercises --
	 * code that treats a cache miss as its normal path would start taking
	 * the hit branch instead.
	 */
	function get_transient( $transient ) {
		if ( ! isset( $GLOBALS['__mhmcs_test_transients'] ) || ! is_array( $GLOBALS['__mhmcs_test_transients'] ) ) {
			return false;
		}

		return $GLOBALS['__mhmcs_test_transients'][ $transient ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		if ( isset( $GLOBALS['__mhmcs_test_transients'] ) && is_array( $GLOBALS['__mhmcs_test_transients'] ) ) {
			$GLOBALS['__mhmcs_test_transients'][ $transient ] = $value;
		}

		return true;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		if ( is_array( $response ) && isset( $response['body'] ) ) {
			return $response['body'];
		}
		return '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		if ( is_array( $response ) && isset( $response['response']['code'] ) ) {
			return $response['response']['code'];
		}
		return 200;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof \WP_Error;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	/*
	 * Action-count stub. Tests set counts via
	 * $GLOBALS['__mhmcs_test_did_actions'][ $hook_name ]; unseeded hooks
	 * return 0, which is the pre-`wp` state ConversionContext relies on.
	 */
	function did_action( $hook_name ) {
		if ( isset( $GLOBALS['__mhmcs_test_did_actions'][ $hook_name ] ) ) {
			return (int) $GLOBALS['__mhmcs_test_did_actions'][ $hook_name ];
		}
		return 0;
	}
}

/*
 * ---------------------------------------------------------------------
 * Request-context stubs (ConversionContext, design spec §3.1/§3.2).
 *
 * All of them follow the established $GLOBALS['__mhmcs_test_*']
 * convention: absent global == "not that context".
 * ---------------------------------------------------------------------
 */
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return ! empty( $GLOBALS['__mhmcs_test_is_admin'] );
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax() {
		return ! empty( $GLOBALS['__mhmcs_test_doing_ajax'] );
	}
}

if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron() {
		return ! empty( $GLOBALS['__mhmcs_test_doing_cron'] );
	}
}

if ( ! function_exists( 'wp_get_referer' ) ) {
	/*
	 * Mirrors WordPress: returns false when the referer is unavailable,
	 * which is exactly the "indeterminate referer" sub-case of decision 1.
	 */
	function wp_get_referer() {
		if ( isset( $GLOBALS['__mhmcs_test_referer'] ) ) {
			return $GLOBALS['__mhmcs_test_referer'];
		}
		return false;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '', $scheme = 'admin' ) {
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return ! empty( $GLOBALS['__mhmcs_test_logged_in'] );
	}
}

if ( ! function_exists( 'is_cart' ) ) {
	function is_cart() {
		return ! empty( $GLOBALS['__mhmcs_test_is_cart'] );
	}
}

if ( ! function_exists( 'is_404' ) ) {
	function is_404() {
		return ! empty( $GLOBALS['__mhmcs_test_is_404'] );
	}
}

if ( ! function_exists( 'is_page' ) ) {
	function is_page( $page = '' ) {
		return ! empty( $GLOBALS['__mhmcs_test_is_page'] )
			&& (int) $GLOBALS['__mhmcs_test_is_page'] === (int) $page;
	}
}

if ( ! function_exists( 'wc_get_page_id' ) ) {
	function wc_get_page_id( $page ) {
		return isset( $GLOBALS['__mhmcs_test_wc_page_ids'][ $page ] )
			? (int) $GLOBALS['__mhmcs_test_wc_page_ids'][ $page ]
			: -1;
	}
}

if ( ! function_exists( 'wc_post_content_has_shortcode' ) ) {
	function wc_post_content_has_shortcode( $tag = '' ) {
		return ! empty( $GLOBALS['__mhmcs_test_shortcodes'][ $tag ] );
	}
}

if ( ! function_exists( 'is_checkout' ) ) {
	function is_checkout() {
		return ! empty( $GLOBALS['__mhmcs_test_is_checkout'] );
	}
}

if ( ! function_exists( 'is_account_page' ) ) {
	function is_account_page() {
		return ! empty( $GLOBALS['__mhmcs_test_is_account_page'] );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/*
	 * Filter stub. Tests register a single callback per hook via
	 * $GLOBALS['__mhmcs_test_filters'][ $hook_name ]; unfiltered hooks
	 * return the value untouched.
	 */
	function apply_filters( $hook_name, $value ) {
		$args = array_slice( func_get_args(), 1 );

		if ( isset( $GLOBALS['__mhmcs_test_filters'][ $hook_name ] )
			&& is_callable( $GLOBALS['__mhmcs_test_filters'][ $hook_name ] ) ) {
			return call_user_func_array( $GLOBALS['__mhmcs_test_filters'][ $hook_name ], $args );
		}

		return $value;
	}
}

if ( ! class_exists( 'WooCommerce' ) ) {
	/*
	 * Minimal WooCommerce stub. `is_rest_api_request()` reproduces the real
	 * REQUEST_URI-based implementation (WooCommerce::is_rest_api_request()),
	 * because the design spec pins that exact primitive: an internal
	 * rest_do_request() during a page render must NOT look like a REST
	 * request, and REQUEST_URI is what makes that true.
	 *
	 * `$cart` is present and null so that production guards of the shape
	 * `function_exists( 'WC' ) && WC()->cart` stay false in unit tests.
	 */
	class WooCommerce {
		public $cart = null;

		public function is_rest_api_request() {
			if ( empty( $_SERVER['REQUEST_URI'] ) ) {
				return false;
			}

			return false !== strpos( (string) $_SERVER['REQUEST_URI'], 'wp-json/' );
		}
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		static $instance = null;

		if ( null === $instance ) {
			$instance = new \WooCommerce();
		}

		return $instance;
	}
}

if ( ! function_exists( 'get_query_var' ) ) {
	/*
	 * Query-var stub. Tests set values via
	 * $GLOBALS['__mhmcs_test_query_vars'][ $var ]; absent vars return
	 * the default ('' by default), matching WordPress behaviour.
	 */
	function get_query_var( $var, $default = '' ) {
		if ( isset( $GLOBALS['__mhmcs_test_query_vars'] )
			&& array_key_exists( $var, $GLOBALS['__mhmcs_test_query_vars'] ) ) {
			return $GLOBALS['__mhmcs_test_query_vars'][ $var ];
		}
		return $default;
	}
}

/*
 * Minimal WP_REST_Server constants stub.
 */
if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE  = 'GET';
		const CREATABLE = 'POST';
	}
}

/*
 * Minimal WP_REST_Response stub.
 */
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data;
		private $status;

		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}
	}
}

/*
 * Minimal WP_REST_Request stub.
 */
if ( ! class_exists( 'WC_Order' ) ) {
	// Minimal stub — `OrderFilter::get_order_currency()` guards on
	// `instanceof \WC_Order`. Tests extend this to satisfy the check.
	class WC_Order {
		public function get_meta( $key, $single = false ) {
			return '';
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params  = array();
		private $headers = array();

		public function get_json_params() {
			return $this->params;
		}

		public function set_json_params( $params ) {
			$this->params = $params;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function set_header( $key, $value ) {
			$this->headers[ strtolower( $key ) ] = $value;
		}

		public function get_header( $key ) {
			return $this->headers[ strtolower( $key ) ] ?? '';
		}
	}
}

/*
 * Minimal WP_CLI stubs.
 */
if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static function add_command( $name, $callable, $args = array() ) {}
		public static function success( $message ) {}
		public static function error( $message, $exit = true ) {}
		public static function line( $message = '' ) {}
		public static function log( $message ) {}
		public static function warning( $message ) {}
	}
}

if ( ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
	// phpcs:ignore
	function mhmcs_stub_format_items( $format, $items, $fields ) {}
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( 'is_ssl' ) ) {
	/*
	 * Tests flip $GLOBALS['__mhmcs_test_is_ssl']; unseeded means plain HTTP.
	 */
	function is_ssl() {
		return ! empty( $GLOBALS['__mhmcs_test_is_ssl'] );
	}
}

if ( ! class_exists( 'WC_Geolocation' ) ) {
	/*
	 * Minimal WC_Geolocation stub with an invocation COUNTER.
	 *
	 * The counter is the only honest way to assert "geolocation did not run":
	 * GeolocationService is final, so it cannot be subclassed or mocked, and
	 * asserting on the returned currency alone would not distinguish "did not
	 * run" from "ran and was overruled". Tests seed the answer via
	 * $GLOBALS['__mhmcs_test_geo_country'] and read the call count from
	 * $GLOBALS['__mhmcs_test_geolocate_calls'].
	 *
	 * Unseeded it returns an empty country, i.e. exactly what
	 * GeolocationService saw before this stub existed (no WC_Geolocation
	 * class at all): no detection.
	 */
	class WC_Geolocation {
		/*
		 * Mirrors the real WC_Geolocation::get_ip_address() in the one respect
		 * that matters to this plugin: it hands back a proxy header VERBATIM.
		 * WooCommerce 10.5.2 returns $_SERVER['HTTP_X_REAL_IP'] through
		 * sanitize_text_field() with no IP validation at all — only its
		 * X-Forwarded-For branch calls rest_is_ip_address(). So whatever a
		 * caller puts in that header is what comes back, IP or not.
		 *
		 * Until this method existed here, method_exists() was false throughout
		 * the unit suite and every rate-limit test silently exercised the
		 * REMOTE_ADDR fallback instead of the branch that actually runs in
		 * production. Tests seed the answer via $GLOBALS['__mhmcs_test_wc_ip'].
		 */
		public static function get_ip_address() {
			if ( isset( $GLOBALS['__mhmcs_test_wc_ip'] ) ) {
				return (string) $GLOBALS['__mhmcs_test_wc_ip'];
			}

			return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		}

		public static function geolocate_ip( $ip_address = '', $fallback = false, $api_fallback = true ) {
			if ( ! isset( $GLOBALS['__mhmcs_test_geolocate_calls'] ) ) {
				$GLOBALS['__mhmcs_test_geolocate_calls'] = 0;
			}

			++$GLOBALS['__mhmcs_test_geolocate_calls'];

			return array(
				'country' => isset( $GLOBALS['__mhmcs_test_geo_country'] )
					? (string) $GLOBALS['__mhmcs_test_geo_country']
					: '',
			);
		}
	}
}

/*
 * Namespaced stubs (see the file's own header for why they cannot live in
 * this global-namespace file).
 */
require_once __DIR__ . '/stubs/core-namespace-stubs.php';
