<?php
/**
 * Namespaced function stubs for the MhmCurrencySwitcher\Core namespace.
 *
 * PHP resolves an UNQUALIFIED function call inside a namespace against that
 * namespace first and only then against the global one. DetectionService
 * calls `setcookie(...)` unqualified, so declaring the function here lets the
 * unit suite observe cookie writes without a real HTTP response — the only
 * way to prove "this code path writes no cookie", which is the whole point of
 * the request override (design spec §4) and of moving the geolocation cookie
 * write earlier (§8.3).
 *
 * This file is required only by tests/bootstrap.php (the UNIT bootstrap).
 * Integration tests boot through tests/bootstrap-integration.php and keep
 * PHP's real setcookie().
 *
 * @package MhmCurrencySwitcher\Tests
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Core;

if ( ! function_exists( __NAMESPACE__ . '\\setcookie' ) ) {
	/**
	 * Record a cookie write instead of sending it.
	 *
	 * Tests read $GLOBALS['__mhmcs_test_setcookie'], a list of
	 * array{name: string, value: string, options: array} entries in call order.
	 *
	 * @param string $name    Cookie name.
	 * @param string $value   Cookie value.
	 * @param array  $options Cookie options (expires/path/secure/httponly/samesite).
	 * @return bool Always true.
	 */
	function setcookie( $name, $value = '', $options = array() ) {
		if ( ! isset( $GLOBALS['__mhmcs_test_setcookie'] ) || ! is_array( $GLOBALS['__mhmcs_test_setcookie'] ) ) {
			$GLOBALS['__mhmcs_test_setcookie'] = array();
		}

		$GLOBALS['__mhmcs_test_setcookie'][] = array(
			'name'    => (string) $name,
			'value'   => (string) $value,
			'options' => is_array( $options ) ? $options : array(),
		);

		return true;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\headers_sent' ) ) {
	/**
	 * Report whether the response headers have already gone out.
	 *
	 * PHP's own headers_sent() answers TRUE for any CLI process that has
	 * produced output — which every PHPUnit run has, from the first progress
	 * dot onwards. Without this stub the "headers are still open" branch of
	 * set_currency() would be unreachable from the unit suite, so tests set
	 * $GLOBALS['__mhmcs_test_headers_sent'] instead; unseeded means open.
	 *
	 * @param string|null $filename Unused, mirrors the built-in signature.
	 * @param int|null    $line     Unused, mirrors the built-in signature.
	 * @return bool
	 */
	function headers_sent( &$filename = null, &$line = null ) {
		return ! empty( $GLOBALS['__mhmcs_test_headers_sent'] );
	}
}
