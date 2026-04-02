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
	define( 'MHM_CS_VERSION', '0.1.0' );
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
