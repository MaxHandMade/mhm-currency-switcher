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
	 * License server API base URL.
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
	 * Checks in order:
	 *   1. MHM_CS_DEV_PRO constant (development override).
	 *   2. Stored license data 'status' === 'active'.
	 *
	 * @return bool True when the license is active.
	 */
	public function is_active(): bool {
		// Development override.
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
	 * Activate a license key with the license server.
	 *
	 * @param string $key License key to activate.
	 * @return array<string, mixed> Result with 'success' and 'message' keys.
	 */
	public function activate( string $key ): array {
		$response = wp_remote_post(
			self::API_BASE . '/activate',
			array(
				'timeout' => 15,
				'body'    => array(
					'license_key' => $key,
					'site_url'    => get_option( 'siteurl', '' ),
					'product'     => 'mhm-currency-switcher',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => 'Connection to license server failed.',
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return array(
				'success' => false,
				'message' => 'Invalid response from license server.',
			);
		}

		// Store the license data.
		if ( ! empty( $body['success'] ) ) {
			$license_data = array(
				'license_key' => $key,
				'status'      => 'active',
				'expires'     => $body['expires'] ?? '',
				'activated'   => time(),
			);

			update_option( self::OPTION_KEY, $license_data );
			$this->license_data = $license_data;
		}

		return $body;
	}

	/**
	 * Deactivate the current license.
	 *
	 * @return bool True on success.
	 */
	public function deactivate(): bool {
		$key = $this->get_license_key();

		if ( '' === $key ) {
			return false;
		}

		wp_remote_post(
			self::API_BASE . '/deactivate',
			array(
				'timeout' => 15,
				'body'    => array(
					'license_key' => $key,
					'site_url'    => get_option( 'siteurl', '' ),
					'product'     => 'mhm-currency-switcher',
				),
			)
		);

		delete_option( self::OPTION_KEY );
		$this->license_data = null;

		return true;
	}

	/**
	 * Plugin deactivation hook callback.
	 *
	 * Deactivates the license when the plugin is deactivated.
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

		$response = wp_remote_post(
			self::API_BASE . '/verify',
			array(
				'timeout' => 15,
				'body'    => array(
					'license_key' => $key,
					'site_url'    => get_option( 'siteurl', '' ),
					'product'     => 'mhm-currency-switcher',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( is_array( $body ) && isset( $body['status'] ) ) {
			$data               = $this->get_license_data();
			$data['status']     = $body['status'];
			$data['last_check'] = time();

			update_option( self::OPTION_KEY, $data );
			$this->license_data = $data;
		}
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
