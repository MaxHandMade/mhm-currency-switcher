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

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) {
		echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
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
	/*
	 * Defaults to false for every capability — the exact behaviour every
	 * caller before Task 15 relied on, since no test set anything here. A
	 * test that needs the positive case for one capability sets
	 * $GLOBALS['__mhmcs_test_can'][ $capability ] = true; an unseeded
	 * capability, or an unseeded global entirely, stays false.
	 */
	function current_user_can( $capability ) {
		return ! empty( $GLOBALS['__mhmcs_test_can'][ $capability ] );
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

if ( ! function_exists( 'get_current_user_id' ) ) {
	/*
	 * Task 16 (snooze). Defaults to user 1 -- an ordinary logged-in admin --
	 * rather than 0 (WordPress's "no user"), because 0 would make every
	 * seeded user-meta row in $GLOBALS['__mhmcs_test_user_meta'][0] collide
	 * with delete_metadata()'s object_id=0 site-wide-delete argument below,
	 * which has nothing to do with an actual user id of 0.
	 */
	function get_current_user_id() {
		return isset( $GLOBALS['__mhmcs_test_current_user_id'] )
			? (int) $GLOBALS['__mhmcs_test_current_user_id']
			: 1;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	/*
	 * Task 16 (snooze). Mirrors get_post_meta()'s stub shape and the same
	 * $single=true-only contract every production call site here actually
	 * uses. Tests seed values via
	 * $GLOBALS['__mhmcs_test_user_meta'][ $user_id ][ $key ].
	 */
	function get_user_meta( $user_id, $key = '', $single = false ) {
		if ( isset( $GLOBALS['__mhmcs_test_user_meta'][ $user_id ][ $key ] ) ) {
			$value = $GLOBALS['__mhmcs_test_user_meta'][ $user_id ][ $key ];
			return $single ? $value : array( $value );
		}
		return $single ? '' : array();
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	/*
	 * Task 16 (snooze). Records into the same store get_user_meta() reads,
	 * so a test can write through one function and read back through the
	 * other exactly as production code does.
	 */
	function update_user_meta( $user_id, $key, $value, $prev_value = '' ) {
		$GLOBALS['__mhmcs_test_user_meta'][ $user_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_metadata' ) ) {
	/*
	 * Task 16 (snooze). Only the shape CacheCompatDiagnostic::record() calls
	 * is modelled: meta_type 'user', object_id 0 with $delete_all true, which
	 * is core's own site-wide delete-by-key contract (every user's row for
	 * that key, not one user's). A CALL COUNTER is recorded unconditionally
	 * so a test can prove the delete did NOT fire on an ordinary healthy
	 * request -- the same shape __mhmcs_test_option_writes already proves for
	 * update_option(), and the only way to fail Ruling II's "clear on the
	 * transition, not on every clean pass" honestly rather than by reading
	 * the source.
	 */
	function delete_metadata( $meta_type, $object_id, $meta_key, $meta_value = '', $delete_all = false ) {
		if ( isset( $GLOBALS['__mhmcs_test_delete_metadata_calls'] ) ) {
			++$GLOBALS['__mhmcs_test_delete_metadata_calls'];
		}

		if ( 'user' !== $meta_type || ! $delete_all || ! isset( $GLOBALS['__mhmcs_test_user_meta'] ) ) {
			return false;
		}

		foreach ( array_keys( $GLOBALS['__mhmcs_test_user_meta'] ) as $uid ) {
			unset( $GLOBALS['__mhmcs_test_user_meta'][ $uid ][ $meta_key ] );
		}

		return true;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/*
	 * Task 16 (snooze). Defaults to invalid/false for every nonce -- the
	 * state a request that carries none, or a forged one, is actually in. A
	 * test that needs the positive case sets
	 * $GLOBALS['__mhmcs_test_nonce_valid'] = true explicitly; core's own
	 * return type (a truthy 1/2 on success, boolean false on failure) is
	 * mirrored closely enough that `false !== wp_verify_nonce(...)` reads
	 * the same way here as it does against the real function.
	 */
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return ! empty( $GLOBALS['__mhmcs_test_nonce_valid'] ) ? 1 : false;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	/**
	 * Records its effect, because a no-op stub cannot show a reschedule.
	 *
	 * The previous version returned 0 and changed nothing, so every test that
	 * saved settings left the cron globals exactly as it found them — an
	 * implementation that cleared and re-armed the event on EVERY save looked
	 * identical to one that only did so when the interval changed. Same class
	 * as the WP_REST_Response stub that modelled no headers.
	 *
	 * Core contract: returns the number of events unscheduled.
	 */
	function wp_clear_scheduled_hook( $hook, $args = array() ) {
		if ( ! isset( $GLOBALS['__mhmcs_test_cron'][ $hook ] ) ) {
			return 0;
		}

		unset( $GLOBALS['__mhmcs_test_cron'][ $hook ] );
		unset( $GLOBALS['__mhmcs_test_cron_recurrence'][ $hook ] );

		return 1;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Core contract: int|false, and the int is a UTC timestamp.
	 *
	 * The `false` matters — a test that pins this to 0 would let an
	 * implementation using `is_int()` or `> 0` pass while the real function's
	 * "no such event" answer took a different branch.
	 */
	function wp_next_scheduled( $hook, $args = array() ) {
		if ( ! isset( $GLOBALS['__mhmcs_test_cron'][ $hook ] ) ) {
			return false;
		}

		return $GLOBALS['__mhmcs_test_cron'][ $hook ];
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * Stand-in for the site-timezone formatter.
	 *
	 * Deliberately NOT date() — that formats in PHP's timezone and would make
	 * a test pass against an implementation that ignores the site's zone,
	 * which is the exact defect wp_date() exists to prevent. The marker in
	 * the return value lets a test assert the formatting went through here.
	 */
	function wp_date( $format, $timestamp = null, $timezone = null ) {
		$timestamp = null === $timestamp ? 0 : (int) $timestamp;

		return 'wpdate(' . $format . '@' . $timestamp . ')';
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	/**
	 * Records the armed event so wp_next_scheduled() can report it back.
	 *
	 * The recurrence is kept in its own global: "did the schedule move" and
	 * "does it now repeat hourly" are two different questions, and a test that
	 * can only ask the first would pass against an implementation that armed
	 * the event with the wrong interval.
	 */
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
		$GLOBALS['__mhmcs_test_cron'][ $hook ]            = (int) $timestamp;
		$GLOBALS['__mhmcs_test_cron_recurrence'][ $hook ] = (string) $recurrence;

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
		// Records what was asked for, not just what came back. Without this a
		// test can assert the RESULT of a fetch but never the ADDRESS, so a
		// filter that is supposed to redirect the request has nothing to
		// prove itself against.
		$GLOBALS['__mhmcs_test_http_get_urls'][] = $url;

		/*
		 * Per-host answers, for testing a FALLBACK CHAIN.
		 *
		 * The single queued response below can only model one call, so a chain
		 * of three sources could not be tested at all: whichever source was
		 * asked first consumed the answer and the rest fell to the error path.
		 * The map lets a test say "only the third host answers" and then read
		 * $GLOBALS['__mhmcs_test_http_get_urls'] to see that the first two were
		 * actually tried, and in what order.
		 *
		 * Keys are matched as substrings of the URL, so a test names a host
		 * rather than reproducing a full URL that the code is free to change.
		 */
		if ( isset( $GLOBALS['__mhmcs_test_http_get_map'] ) && is_array( $GLOBALS['__mhmcs_test_http_get_map'] ) ) {
			foreach ( $GLOBALS['__mhmcs_test_http_get_map'] as $needle => $response ) {
				if ( false !== strpos( $url, (string) $needle ) ) {
					return $response;
				}
			}

			return new \WP_Error( 'http_request_failed', 'Unit test stub — host not in map.' );
		}

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

		/*
		 * Fix round 2 (Task 16): now carries $data, keyed the same way core's
		 * own get_error_data() reads it -- REST error responses in this
		 * codebase pass array( 'status' => <int> ) here, and this stub needs
		 * to hand that back for a test to assert the HTTP status a WP_Error
		 * return will actually carry once WP core converts it via
		 * rest_ensure_response().
		 *
		 * @var mixed
		 */
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data( $code = '' ) {
			return $this->data;
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

if ( ! class_exists( 'WP_Screen' ) ) {
	/*
	 * Minimal stand-in for WordPress's WP_Screen. This plugin's admin
	 * notices only ever read ->id, so that is all the stub carries.
	 */
	class WP_Screen {

		/**
		 * Screen id, e.g. "plugins", "dashboard", "woocommerce_page_wc-settings".
		 *
		 * @var string
		 */
		public $id;

		/**
		 * @param string $id Screen id.
		 */
		public function __construct( $id ) {
			$this->id = $id;
		}
	}
}

if ( ! function_exists( 'get_current_screen' ) ) {
	/*
	 * Real WordPress returns WP_Screen|null — null before the screen has
	 * been set up (e.g. before admin_init), which admin-notice code must
	 * survive without fataling. Tests choose the id via
	 * $GLOBALS['__mhmcs_test_current_screen']; an unset global reproduces
	 * the null case.
	 */
	function get_current_screen() {
		if ( ! isset( $GLOBALS['__mhmcs_test_current_screen'] ) ) {
			return null;
		}

		return new WP_Screen( (string) $GLOBALS['__mhmcs_test_current_screen'] );
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	/*
	 * Mirrors WordPress's real hook-suffix convention for a plugin-owned
	 * submenu: "{parent_slug}_page_{menu_slug}" — the exact value real
	 * WordPress hands back for Settings::add_menu_page()'s
	 * add_submenu_page( 'woocommerce', ..., 'mhmcs-settings', ... )
	 * call, confirmed against a running install. Computed from the SAME
	 * arguments the real function receives, rather than a literal typed
	 * here, so a test comparing against this return value is comparing
	 * against the real call site's slugs, not a second guess at them.
	 */
	function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '' ) {
		return $parent_slug . '_page_' . $menu_slug;
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
		private $headers = array();

		/*
		 * Headers are modelled because the production code sets them and they
		 * carry meaning a shared cache acts on: this endpoint's body depends on
		 * a cookie, so `Cache-Control: no-store` is what stops one visitor's
		 * currency being served to the next. The stub used to drop the third
		 * constructor argument and had no header() at all, which meant no test
		 * could see a missing cache header — and the rate-limited branch was
		 * shipping without one.
		 */
		public function __construct( $data = null, $status = 200, $headers = array() ) {
			$this->data    = $data;
			$this->status  = $status;
			$this->headers = is_array( $headers ) ? $headers : array();
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}

		public function header( $key, $value, $replace = true ) {
			$this->headers[ $key ] = $value;
		}

		public function get_headers() {
			return $this->headers;
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
