<?php
/**
 * License manager — activation, deactivation, and status checks.
 *
 * Communicates with the MHM License Server to validate and manage
 * the plugin license, with daily cron verification.
 *
 * @package MhmCurrencySwitcher\License
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\License;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LicenseManager — singleton license handler.
 *
 * @since 0.4.0
 */
final class LicenseManager {

	/**
	 * Option key for stored license data.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'mhm_currency_switcher_license';

	/**
	 * Cron hook name for daily license verification.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'mhm_cs_license_daily';

	/**
	 * License server API base URL (production default).
	 *
	 * @var string
	 */
	// v0.6.4 — production licence server lives on wpalemi.com. The earlier
	// maxhandmade.com default was a leftover from the v0.4.x WC-fulfilment
	// architecture; mhmrentiva.com worked for /licenses/validate (the old
	// host still serves a partial endpoint set) but POST /licenses/activate
	// returned 404 ("no route matched"), so a customer entering a CS key
	// hit a dead end. The override hierarchy (MHM_CS_LICENSE_API_BASE
	// constant → env var → default) is unchanged.
	const API_BASE = 'https://wpalemi.com/wp-json/mhm-license/v1';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Cached license data from wp_options.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $license_data = null;

	/**
	 * Private constructor to enforce singleton.
	 */
	private function __construct() {}

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Reset the singleton instance (for testing).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Register hooks and schedule daily cron.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'daily_verification' ) );
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );

		// v0.6.4+ — Self-heal corrupt license rows left by the v0.6.0–v0.6.2
		// activate flow. That flow's `'status' => $result['status'] ?? 'active'`
		// fallback wrote `status: 'active'` even when the server WP_Error
		// response carried no `status` field, so the v0.6.3 self-heal that
		// keyed on `status !== 'active'` could not see the corrupt rows.
		// The signature now is "license_key set but activation_id missing":
		// the server only returns activation_id on a successful activate,
		// so a row with an empty/missing activation_id is unambiguously a
		// rejected attempt that the old client had no business persisting.
		// Belt-and-suspenders: also clear when status itself is non-active
		// (covers any future code path that records the real server status).
		$stored = $this->get_license_data();
		if (
			! empty( $stored['license_key'] )
			&& (
				empty( $stored['activation_id'] )
				|| ( isset( $stored['status'] ) && 'active' !== $stored['status'] )
			)
		) {
			delete_option( self::OPTION_KEY );
			$this->license_data = null;
		}

		// v0.6.0+ — verify every 6 hours instead of daily so a license
		// revoked from the server admin propagates to the customer site
		// within ~6h instead of up to 24h. Existing 'daily' schedules
		// from prior versions are detected and rotated to 'every6hours'.
		$existing = wp_next_scheduled( self::CRON_HOOK );
		if ( false === $existing ) {
			wp_schedule_event( time() + 3600, 'every6hours', self::CRON_HOOK );
		} else {
			$event = function_exists( 'wp_get_scheduled_event' )
				? wp_get_scheduled_event( self::CRON_HOOK )
				: null;
			if ( $event && isset( $event->schedule ) && 'every6hours' !== $event->schedule ) {
				wp_unschedule_event( $existing, self::CRON_HOOK );
				wp_schedule_event( time() + 3600, 'every6hours', self::CRON_HOOK );
			}
		}
	}

	/**
	 * Register the every6hours cron schedule used by license verification.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing cron schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['every6hours'] ) ) {
			$schedules['every6hours'] = array(
				'interval' => 21600, // 6 hours in seconds — literal so PHPStan in the PHP 7.4/WP 6.0 CI matrix does not flag a missing WP HOUR_IN_SECONDS constant.
				'display'  => 'Every 6 Hours',
			);
		}
		return $schedules;
	}

	/**
	 * Check if the license is active.
	 *
	 * @return bool True when the license is active.
	 */
	public function is_active(): bool {
		if ( defined( 'MHM_CS_DEV_PRO' ) && MHM_CS_DEV_PRO ) {
			return true;
		}

		$data = $this->get_license_data();

		return isset( $data['status'] ) && 'active' === $data['status'];
	}

	/**
	 * Get the stored license key.
	 *
	 * @return string License key, or empty string when not set.
	 */
	public function get_license_key(): string {
		$data = $this->get_license_data();

		return (string) ( $data['license_key'] ?? '' );
	}

	/**
	 * Get all stored license data (for admin REST responses).
	 *
	 * @return array<string, mixed> License data array.
	 */
	public function get_stored_data(): array {
		return $this->get_license_data();
	}

	/**
	 * Get the stored activation ID.
	 *
	 * @return string Activation ID, or empty string when not set.
	 */
	public function get_activation_id(): string {
		$data = $this->get_license_data();

		return (string) ( $data['activation_id'] ?? '' );
	}

	/**
	 * Activate a license key with the license server.
	 *
	 * @param string $key License key to activate.
	 * @return array<string, mixed> Result with 'success' and 'message' keys.
	 */
	public function activate( string $key ): array {
		$result = $this->request(
			'/licenses/activate',
			array(
				'license_key'    => $key,
				'site_hash'      => $this->site_hash(),
				'site_url'       => home_url(),
				'is_staging'     => $this->is_staging(),
				// Identifies this plugin to mhm-license-server v1.8.0+ which
				// enforces per-product license binding server-side.
				'product_slug'   => 'mhm-currency-switcher',
				// v1.9.0+ — server runs reverse site validation when
				// client_version >= REVERSE_VALIDATION_FLOOR for this product.
				'client_version' => defined( 'MHM_CS_VERSION' ) ? MHM_CS_VERSION : '',
			)
		);

		if ( isset( $result['_error'] ) ) {
			return array(
				'success' => false,
				'message' => $result['_error'],
			);
		}

		// Error response from server.
		if ( isset( $result['success'] ) && false === $result['success'] ) {
			return $result;
		}

		// Successful activation — store license data.
		$license_data = array(
			'license_key'   => $key,
			'status'        => $result['status'] ?? 'active',
			'plan'          => $result['plan'] ?? 'pro',
			'expires_at'    => $this->normalize_expires_at( $result['expires_at'] ?? '' ),
			'activation_id' => $result['activation_id'] ?? '',
			// v0.5.0+ — Server-issued feature token used by Mode::can_use_*()
			// to gate Pro features. Empty string when talking to a legacy server.
			'feature_token' => isset( $result['feature_token'] ) ? (string) $result['feature_token'] : '',
			'activated'     => time(),
			'last_check'    => time(),
		);

		update_option( self::OPTION_KEY, $license_data );
		$this->license_data = $license_data;

		return array(
			'success' => true,
			'message' => 'License activated successfully.',
			'status'  => $license_data['status'],
		);
	}

	/**
	 * Returns the server-issued feature token (v0.5.0+) from local storage.
	 *
	 * Empty string when no active license, when talking to a legacy server,
	 * or when the daily validate cron has not yet refreshed it.
	 *
	 * @return string Stored feature token, or empty string.
	 */
	public function get_feature_token(): string {
		$data = $this->get_license_data();

		return (string) ( $data['feature_token'] ?? '' );
	}

	/**
	 * Deactivate the current license.
	 *
	 * @return bool True on success.
	 */
	public function deactivate(): bool {
		$key           = $this->get_license_key();
		$activation_id = $this->get_activation_id();

		if ( '' !== $key && '' !== $activation_id ) {
			$this->request(
				'/licenses/deactivate',
				array(
					'license_key'   => $key,
					'activation_id' => $activation_id,
				)
			);
		}

		delete_option( self::OPTION_KEY );
		$this->license_data = null;

		return true;
	}

	/**
	 * Plugin deactivation hook callback.
	 *
	 * @return void
	 */
	public static function deactivate_plugin_hook(): void {
		$manager = self::instance();

		if ( $manager->is_active() ) {
			$manager->deactivate();
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Daily cron verification of the license.
	 *
	 * Changed in v0.7.1 from void to array return type. On any transport error
	 * or server-side failure the method now fails closed: status, activation_id,
	 * and feature_token are cleared immediately so Mode::feature_granted() drops
	 * to Lite on the next page load. Transient transport failures recover on the
	 * next 6-hourly cron run. Mirrors Rentiva LicenseManager (validate() lines
	 * 354-387).
	 *
	 * The CRON hook callback ignores the return value — backward-compatible.
	 * The Settings.php re-validate handler uses it to branch the admin notice.
	 *
	 * @return array{ok: bool, status: string, message: string}
	 *   ok:      Whether validation succeeded with active status.
	 *   status:  'active' | 'inactive' | 'no_key' — the resolved local status.
	 *   message: Short human-readable summary (translated).
	 */
	public function daily_verification(): array {
		$key = $this->get_license_key();

		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'status'  => 'no_key',
				'message' => __( 'No license key saved on this site.', 'mhm-currency-switcher' ),
			);
		}

		$result = $this->request(
			'/licenses/validate',
			array(
				'license_key'  => $key,
				'site_hash'    => $this->site_hash(),
				'product_slug' => 'mhm-currency-switcher',
			)
		);

		if ( isset( $result['_error'] ) ) {
			// v0.7.1 — Server unreachable, license missing, or transport error.
			// Fail closed: clear activation + token so Mode::feature_granted()
			// drops to Lite immediately. Mirror Rentiva LicenseManager (validate()
			// lines 354-387). Transient transport errors recover on the next cron.
			$data                  = $this->get_license_data();
			$data['status']        = 'inactive';
			$data['feature_token'] = '';
			// Reset activation_id so the next activate() request looks fresh,
			// not a "re-activate this site" flow against a key the server doesn't know.
			if ( isset( $data['activation_id'] ) ) {
				$data['activation_id'] = '';
			}
			$data['last_check'] = time();
			update_option( self::OPTION_KEY, $data );
			$this->license_data = $data;
			return array(
				'ok'      => false,
				'status'  => 'inactive',
				'message' => sprintf(
					/* translators: %s — error description from the licence server */
					__( 'License could not be validated: %s. Pro features have been disabled until the next successful check.', 'mhm-currency-switcher' ),
					(string) $result['_error']
				),
			);
		}

		if ( isset( $result['status'] ) ) {
			$data               = $this->get_license_data();
			$data['status']     = $result['status'];
			$data['last_check'] = time();

			if ( isset( $result['expires_at'] ) ) {
				$data['expires_at'] = $this->normalize_expires_at( $result['expires_at'] );
			}

			// v0.5.0+ — Refresh feature token from server. Server omits the
			// field when the license downgrades to non-active state, so we
			// DO update here even when the new value is empty (Pro features
			// close immediately).
			if ( array_key_exists( 'feature_token', $result ) ) {
				$data['feature_token'] = (string) $result['feature_token'];
			}

			// v0.6.0+ — When the server reports a non-active state, drop the
			// cached feature token so Mode::feature_granted() fails closed on
			// the next page load. Mode already short-circuits on
			// is_active()===false, but clearing the token here removes the
			// stale credential entirely (defense in depth).
			if ( 'active' !== $result['status'] ) {
				$data['feature_token'] = '';
			}

			update_option( self::OPTION_KEY, $data );
			$this->license_data = $data;

			if ( 'active' === $result['status'] ) {
				return array(
					'ok'      => true,
					'status'  => 'active',
					'message' => __( 'License re-validated. Pro features remain active.', 'mhm-currency-switcher' ),
				);
			}
			return array(
				'ok'      => false,
				'status'  => (string) $result['status'],
				'message' => __( 'License is no longer active on this site. Pro features have been disabled.', 'mhm-currency-switcher' ),
			);
		}

		// Unexpected: response had no _error, no status. Treat as inactive (defensive).
		$data                  = $this->get_license_data();
		$data['status']        = 'inactive';
		$data['feature_token'] = '';
		if ( isset( $data['activation_id'] ) ) {
			$data['activation_id'] = '';
		}
		$data['last_check'] = time();
		update_option( self::OPTION_KEY, $data );
		$this->license_data = $data;
		return array(
			'ok'      => false,
			'status'  => 'inactive',
			'message' => __( 'License server returned an unexpected response. Pro features have been disabled until the next check.', 'mhm-currency-switcher' ),
		);
	}

	/**
	 * Request a Polar customer portal session for the active license.
	 *
	 * Snake_case parity to Rentiva v4.32.0's `createCustomerPortalSession()`.
	 * Used by the Manage Subscription button in admin-app/License.jsx via the
	 * `/mhm-currency/v1/license/manage-subscription` REST endpoint.
	 *
	 * @since 0.7.0
	 *
	 * @param string $return_url Optional URL the portal should redirect back
	 *                           to after the customer finishes managing their
	 *                           subscription.
	 * @return array{success: bool, error_code?: string, customer_portal_url?: string, expires_at?: string}
	 *               Result array. On success: `success`, `customer_portal_url`,
	 *               `expires_at`. On failure: `success=false` + `error_code`.
	 */
	public function create_customer_portal_session( string $return_url = '' ): array {
		$license = $this->get_license_data();
		if ( empty( $license['license_key'] ) || ! $this->is_active() ) {
			return array(
				'success'    => false,
				'error_code' => 'license_not_active',
			);
		}

		$response = $this->request(
			'/licenses/customer-portal-session',
			array(
				'license_key' => (string) $license['license_key'],
				'site_hash'   => $this->get_site_hash(),
				'return_url'  => $return_url,
			)
		);

		// Transport / signature / server-error path. CS's request() collapses
		// all failures to an `_error` key (human message). When the server
		// returned a 4xx with a structured `code` field that code is also
		// surfaced as `_code` — we forward it to the JS layer so the UI can
		// distinguish "no such license" (license_not_found) from "license is
		// not subscription-backed" (license_not_subscription). Signature
		// verification failure has no `_code` and is therefore mapped to
		// the generic `tampered_response` sentinel.
		if ( isset( $response['_error'] ) ) {
			$error_code = isset( $response['_code'] ) && is_string( $response['_code'] ) && '' !== $response['_code']
				? $response['_code']
				: 'tampered_response';
			return array(
				'success'    => false,
				'error_code' => $error_code,
			);
		}

		if ( empty( $response['success'] ) ) {
			$error_code = isset( $response['error'] ) && is_string( $response['error'] )
				? $response['error']
				: 'unknown_error';
			return array(
				'success'    => false,
				'error_code' => $error_code,
			);
		}

		$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();

		return array(
			'success'             => true,
			'customer_portal_url' => (string) ( $data['customer_portal_url'] ?? '' ),
			'expires_at'          => (string) ( $data['expires_at'] ?? '' ),
		);
	}

	/**
	 * Send a JSON POST request to the license server.
	 *
	 * @param string               $path API path (e.g. '/licenses/activate').
	 * @param array<string, mixed> $body Request body.
	 * @return array<string, mixed> Decoded response, or array with '_error' key.
	 */
	private function request( string $path, array $body ): array {
		$url      = $this->get_api_base() . $path;
		$raw_body = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $raw_body ) ) {
			return array( '_error' => 'Could not encode request body.' );
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'    => 'POST',
				'timeout'   => 15,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'User-Agent'   => 'MHM-CurrencySwitcher/' . MHM_CS_VERSION,
				),
				'body'      => $raw_body,
				'sslverify' => $this->should_verify_ssl(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( '_error' => $response->get_error_message() );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return array( '_error' => 'Invalid response from license server.' );
		}

		// v0.6.3+ — Check HTTP status code BEFORE accepting the response as
		// success. Without this guard a 400 product_mismatch / 401 invalid_key
		// response (which the licence server emits as a WP_Error → REST API
		// 4xx + JSON body with `code` and `message` fields) was being decoded
		// and returned as if it were a successful activation, so activate()
		// stored a "license_data" row for a key the server had explicitly
		// rejected. Mirrors the equivalent guard in mhm-rentiva's request()
		// (LicenseManager.php — `if ($code >= 400)` branch).
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code >= 400 ) {
			$error_message = isset( $decoded['message'] ) && is_string( $decoded['message'] )
				? $decoded['message']
				: sprintf( 'License server returned HTTP %d.', $status_code );
			return array(
				'_error'     => $error_message,
				'_http_code' => $status_code,
				'_code'      => isset( $decoded['code'] ) && is_string( $decoded['code'] )
					? $decoded['code']
					: 'license_http',
			);
		}

		// v0.5.0+ — Verify server-side response signature when present.
		// Legacy servers (v1.8.x) omit the field entirely; we accept those
		// responses unchanged so the v0.5.0 client can still talk to a
		// not-yet-upgraded license server during the rollout window.
		if ( isset( $decoded['signature'] ) ) {
			$secret = ClientSecrets::get_response_hmac_secret();
			if ( '' !== $secret ) {
				$verifier = new ResponseVerifier( $secret );
				if ( ! $verifier->verify( $decoded ) ) {
					return array( '_error' => __( 'License server response failed signature verification.', 'mhm-currency-switcher' ) );
				}
			}
		}

		return $decoded;
	}

	/**
	 * Get the API base URL, allowing env/constant override.
	 *
	 * @return string API base URL.
	 */
	private function get_api_base(): string {
		if ( defined( 'MHM_CS_LICENSE_API_BASE' ) && '' !== MHM_CS_LICENSE_API_BASE ) {
			return rtrim( (string) MHM_CS_LICENSE_API_BASE, '/' );
		}

		$env = getenv( 'MHM_CS_LICENSE_API_BASE' );

		if ( false !== $env && '' !== $env ) {
			return rtrim( $env, '/' );
		}

		return self::API_BASE;
	}

	/**
	 * Generate a site hash for server communication.
	 *
	 * @return string SHA-256 hash of site identity payload.
	 */
	private function site_hash(): string {
		$payload = array(
			'home' => home_url(),
			'site' => site_url(),
			'wp'   => get_bloginfo( 'version' ),
			'php'  => PHP_VERSION,
		);

		return hash( 'sha256', (string) wp_json_encode( $payload ) );
	}

	/**
	 * Public accessor for the local site hash. Mode::feature_granted()
	 * (v0.6.0+) needs this to bind a feature token to the current host
	 * before treating its claims as authoritative; activate/validate
	 * already used the private helper internally.
	 *
	 * @return string SHA-256 hex of {home, site, wp, php}.
	 */
	public function get_site_hash(): string {
		return $this->site_hash();
	}

	/**
	 * Whether the current site is a staging/dev environment.
	 *
	 * @return bool True when running on localhost or known dev TLDs.
	 */
	private function is_staging(): bool {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! is_string( $host ) ) {
			return false;
		}

		foreach ( array( '.local', '.test', '.dev', '.staging', 'localhost' ) as $pattern ) {
			$pattern_len = strlen( $pattern );
			if ( $host === $pattern
				|| ( strlen( $host ) >= $pattern_len && 0 === substr_compare( $host, $pattern, -$pattern_len ) )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether to verify SSL certificates.
	 *
	 * Disabled in local/staging environments.
	 *
	 * @return bool True when SSL should be verified.
	 */
	private function should_verify_ssl(): bool {
		if ( $this->is_staging() ) {
			return false;
		}

		if ( defined( 'MHM_CS_SSL_VERIFY' ) ) {
			return (bool) MHM_CS_SSL_VERIFY;
		}

		return true;
	}

	/**
	 * Normalize an expires_at value to ISO 8601 string.
	 *
	 * @param mixed $value Raw expires_at value from license server.
	 * @return string ISO 8601 date string, or empty string.
	 */
	private function normalize_expires_at( $value ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}

		if ( is_numeric( $value ) ) {
			$ts = (int) $value;
			return $ts > 0 ? gmdate( 'c', $ts ) : '';
		}

		if ( is_string( $value ) ) {
			$ts = strtotime( $value );
			return false !== $ts && $ts > 0 ? gmdate( 'c', $ts ) : '';
		}

		return '';
	}

	/**
	 * Get cached license data from the option.
	 *
	 * @return array<string, mixed> License data array.
	 */
	private function get_license_data(): array {
		if ( null === $this->license_data ) {
			$data = get_option( self::OPTION_KEY, array() );

			$this->license_data = is_array( $data ) ? $data : array();
		}

		return $this->license_data;
	}
}
