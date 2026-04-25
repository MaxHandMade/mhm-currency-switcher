<?php
/**
 * Reverse-validation REST endpoint called by mhm-license-server during activation.
 *
 * @package MhmCurrencySwitcher\License
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\License;

use WP_REST_Request;
use WP_REST_Response;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public REST endpoint (v0.5.0+) that mhm-license-server v1.9.0+ calls
 * during activation to reverse-validate the client site.
 *
 * Flow:
 *   1. Server issues `wp_remote_get()` to
 *      /wp-json/mhm-currency-switcher-verify/v1/ping with
 *      `X-MHM-Challenge: {uuid}` header.
 *   2. We respond with `HMAC-SHA256(challenge, PING_SECRET)`.
 *   3. Server compares against its own HMAC; mismatch → activation rejected.
 *
 * Defeats fake-activation scripts: an attacker who does not control the
 * claimed site cannot answer the challenge.
 *
 * @since 0.5.0
 */
final class VerifyEndpoint {

	public const REST_NAMESPACE = 'mhm-currency-switcher-verify/v1';
	public const ROUTE          = '/ping';

	/**
	 * Wire the rest_api_init hook so the route registers on every request.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * Register the /ping route with the REST API.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_ping' ),
				// Public on purpose — the server needs to reach this without
				// authentication; challenge is single-use and server-HMAC'd.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle the ping challenge from the license server.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response JSON response with HMAC'd challenge answer or an error.
	 */
	public static function handle_ping( WP_REST_Request $request ): WP_REST_Response {
		$challenge = (string) $request->get_header( 'x-mhm-challenge' );

		if ( '' === $challenge ) {
			return new WP_REST_Response(
				array(
					'code'    => 'challenge_missing',
					'message' => __( 'X-MHM-Challenge header is required.', 'mhm-currency-switcher' ),
				),
				400
			);
		}

		// v0.5.2+ — Prefer PING_SECRET when defined (matches v0.5.1 deploys
		// where operator pinned a shared secret in wp-config). Otherwise fall
		// back to site_hash, which both server and client compute the same way
		// from home_url + site_url + WP version + PHP version. Lets new
		// customers activate without any wp-config edits.
		$secret = ClientSecrets::get_ping_secret();
		if ( '' === $secret ) {
			$secret = self::compute_site_hash();
		}

		return new WP_REST_Response(
			array(
				'challenge_response' => hash_hmac( 'sha256', $challenge, $secret ),
				'site_url'           => home_url(),
				'product_slug'       => 'mhm-currency-switcher',
				'version'            => defined( 'MHM_CS_VERSION' ) ? MHM_CS_VERSION : 'unknown',
			),
			200
		);
	}

	/**
	 * Mirror of LicenseManager::site_hash() — must compute the SAME value the
	 * client sent in the activate request body so the server and client
	 * derive matching HMAC keys when PING_SECRET is unset.
	 *
	 * @return string SHA-256 hash of site identity payload.
	 */
	private static function compute_site_hash(): string {
		$payload = array(
			'home' => home_url(),
			'site' => site_url(),
			'wp'   => get_bloginfo( 'version' ),
			'php'  => PHP_VERSION,
		);

		return hash( 'sha256', (string) wp_json_encode( $payload ) );
	}
}
