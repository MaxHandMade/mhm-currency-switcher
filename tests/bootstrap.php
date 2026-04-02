<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package MhmCurrencySwitcher\Tests
 */

declare(strict_types=1);

// Load Composer autoloader.
$mhm_cs_autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $mhm_cs_autoloader ) ) {
	require_once $mhm_cs_autoloader;
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
if ( ! defined( 'MHM_CS_URL' ) ) {
	define( 'MHM_CS_URL', 'https://example.com/wp-content/plugins/mhm-currency-switcher/' );
}

if ( ! defined( 'MHM_CS_VERSION' ) ) {
	define( 'MHM_CS_VERSION', '0.2.0' );
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
		return $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
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
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
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
	function wp_remote_get( $url, $args = array() ) {
		return new \WP_Error( 'http_request_failed', 'Unit test stub — no HTTP.' );
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		return array();
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		return false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
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
	function did_action( $hook_name ) {
		return 0;
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
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params = array();

		public function get_json_params() {
			return $this->params;
		}

		public function set_json_params( $params ) {
			$this->params = $params;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
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
	function mhm_cs_stub_format_items( $format, $items, $fields ) {}
}

/*
 * Determine if we should load the WordPress test environment.
 * For unit tests that don't need WP, we skip this entirely.
 */
$mhm_cs_wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( is_dir( $mhm_cs_wp_tests_dir ) ) {

	// Give access to tests_add_filter() function.
	require_once $mhm_cs_wp_tests_dir . '/includes/functions.php';

	/**
	 * Manually load WooCommerce and the plugin for integration tests.
	 */
	tests_add_filter(
		'muplugins_loaded',
		static function (): void {
			// Load WooCommerce if available.
			$wc_path = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
			if ( file_exists( $wc_path ) ) {
				require $wc_path;
			}

			// Load our plugin.
			require dirname( __DIR__ ) . '/mhm-currency-switcher.php';
		}
	);

	// Start up the WP testing environment.
	require $mhm_cs_wp_tests_dir . '/includes/bootstrap.php';
}
