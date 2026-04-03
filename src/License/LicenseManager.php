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
	const API_BASE = 'https://maxhandmade.com/wp-json/mhm-license/v1';

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

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
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
				'license_key' => $key,
				'site_hash'   => $this->site_hash(),
				'site_url'    => home_url(),
				'is_staging'  => $this->is_staging(),
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
	 * @return void
	 */
	public function daily_verification(): void {
		$key = $this->get_license_key();

		if ( '' === $key ) {
			return;
		}

		$result = $this->request(
			'/licenses/validate',
			array(
				'license_key' => $key,
				'site_hash'   => $this->site_hash(),
			)
		);

		if ( isset( $result['_error'] ) ) {
			return;
		}

		if ( isset( $result['status'] ) ) {
			$data               = $this->get_license_data();
			$data['status']     = $result['status'];
			$data['last_check'] = time();

			if ( isset( $result['expires_at'] ) ) {
				$data['expires_at'] = $this->normalize_expires_at( $result['expires_at'] );
			}

			update_option( self::OPTION_KEY, $data );
			$this->license_data = $data;
		}
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
			if ( $host === $pattern || str_ends_with( $host, $pattern ) ) {
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
