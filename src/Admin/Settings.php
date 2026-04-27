<?php
/**
 * Admin settings page — mounts the React admin panel.
 *
 * Registers the WooCommerce submenu page and enqueues
 * the React admin app assets.
 *
 * @package MhmCurrencySwitcher\Admin
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MhmCurrencySwitcher\License\Mode;

/**
 * Settings — admin page mount point for the React app.
 *
 * @since 0.5.0
 */
final class Settings {

	/**
	 * Page hook suffix returned by add_submenu_page.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the WooCommerce submenu page.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'MHM Currency Switcher', 'mhm-currency-switcher' ),
			__( 'MHM Currency', 'mhm-currency-switcher' ),
			'manage_woocommerce',
			'mhm-currency-switcher',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the admin page — just a mount point for React.
	 *
	 * @return void
	 */
	public function render_page(): void {
		// v0.6.2+ — Manual "Re-validate Now" trigger. Bypasses the 5-minute
		// throttle on the visit-driven force-validate so an admin who just
		// had a license revoked / re-issued on the licence-server side can
		// force an immediate re-check without waiting. The button renders
		// above the React mount point so it never collides with the SPA.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked on the next line.
		$revalidate_requested = isset( $_GET['mhm_cs_revalidate'] );
		if (
			$revalidate_requested
			&& isset( $_GET['_wpnonce'] )
			&& current_user_can( 'manage_options' )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mhm_cs_revalidate' )
		) {
			delete_transient( 'mhm_cs_license_visit_throttle' );
			$license_manager   = \MhmCurrencySwitcher\License\LicenseManager::instance();
			$current           = $license_manager->get_stored_data();
			$revalidate_ok     = '0';
			$revalidate_status = 'inactive';
			if ( ! empty( $current['license_key'] ) && ! empty( $current['activation_id'] ) ) {
				// v0.7.1 — daily_verification() now returns {ok, status, message}.
				// Surface the outcome in the redirect so the notice block can branch.
				$verify_result     = $license_manager->daily_verification();
				$revalidate_ok     = $verify_result['ok'] ? '1' : '0';
				$revalidate_status = sanitize_key( $verify_result['status'] );
			}
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'              => 'mhm-currency-switcher',
						'license'           => 'revalidated',
						'revalidate_ok'     => $revalidate_ok,
						'revalidate_status' => $revalidate_status,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		echo '<div class="wrap">';

		// v0.6.5+ — Revalidated notice rendered server-side because the redirect
		// from `?mhm_cs_revalidate=1` lands here before the React app mounts;
		// the SPA cannot show the message in time.
		// v0.7.1 — Notice is now conditional: success when revalidate_ok=1,
		// warning when revalidate_ok=0 (transport error, 404, inactive, …).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query-flag check for admin notice.
		$revalidated_flag = isset( $_GET['license'] ) && 'revalidated' === sanitize_text_field( wp_unslash( $_GET['license'] ) );
		if ( $revalidated_flag ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query-flag check for admin notice.
			$revalidate_ok = isset( $_GET['revalidate_ok'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['revalidate_ok'] ) );
			if ( $revalidate_ok ) {
				echo '<div class="notice notice-success is-dismissible" style="margin: 5px 0 15px;"><p>'
					. esc_html__( '🔄 License re-validated against the licence server. Pro state is now in sync.', 'mhm-currency-switcher' )
					. '</p></div>';
			} else {
				echo '<div class="notice notice-warning is-dismissible" style="margin: 5px 0 15px;"><p>'
					. esc_html__( '⚠️ License could not be validated against the licence server. Pro features have been disabled — please check your licence key on the License tab.', 'mhm-currency-switcher' )
					. '</p></div>';
			}
		}

		// The "Re-validate Now" button itself is now rendered inside the
		// React License tab (v0.6.5) so it sits next to "Deactivate License"
		// and only on the License tab. Click handler navigates to the same
		// `?mhm_cs_revalidate=1` URL via `window.location.href`, so the PHP
		// handler at the top of this method continues to do the work.

		echo '<div id="mhm-cs-admin-root"></div>';
		echo '</div>';
	}

	/**
	 * Enqueue React admin app assets on our page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		// v0.6.0+ — Force a fresh server check when an admin opens this
		// page so a deactivation issued from the license-server side is
		// reflected immediately instead of waiting for the 6-hourly cron.
		// Throttled by a 5-minute transient so reloads on the same page do
		// not hammer the license server.
		$throttle_key = 'mhm_cs_license_visit_throttle';
		if ( false === get_transient( $throttle_key ) ) {
			$license_manager = \MhmCurrencySwitcher\License\LicenseManager::instance();
			$current         = $license_manager->get_stored_data();
			if ( ! empty( $current['license_key'] ) && ! empty( $current['activation_id'] ) ) {
				$license_manager->daily_verification();
			}
			set_transient( $throttle_key, time(), 300 ); // 5 minutes (literal — PHPStan does not see WP MINUTE_IN_SECONDS in this CI matrix).
		}

		$asset_file = MHM_CS_PATH . 'admin-app/build/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
				'version'      => MHM_CS_VERSION,
			);

		wp_enqueue_script(
			'mhm-cs-admin',
			MHM_CS_URL . 'admin-app/build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations(
			'mhm-cs-admin',
			'mhm-currency-switcher',
			MHM_CS_PATH . 'languages'
		);

		wp_enqueue_style(
			'mhm-cs-admin',
			MHM_CS_URL . 'admin-app/build/style-index.css',
			array( 'wp-components' ),
			$asset['version']
		);

		wp_enqueue_style( 'dashicons' );

		// Build payment methods array (safe for non-WC contexts).
		$payment_methods = array();

		if ( function_exists( 'WC' ) && WC()->payment_gateways ) {
			$gateways = WC()->payment_gateways->get_available_payment_gateways();

			foreach ( $gateways as $id => $gateway ) {
				$payment_methods[ $id ] = array(
					'title' => $gateway->get_title(),
				);
			}
		}

		// Build WC currencies list (safe for non-WC contexts).
		$wc_currencies = function_exists( 'get_woocommerce_currencies' )
			? get_woocommerce_currencies()
			: array();

		// Build license info for admin panel.
		$license_manager = \MhmCurrencySwitcher\License\LicenseManager::instance();
		$license_data    = $license_manager->get_stored_data();
		$license_key     = $license_data['license_key'] ?? '';
		$license_active  = ! empty( $license_data['license_key'] ) && ! empty( $license_data['activation_id'] );
		$license_info    = array(
			'status'    => $license_data['status'] ?? 'inactive',
			'plan'      => $license_data['plan'] ?? '',
			'expiresAt' => $license_data['expires_at'] ?? '',
			'lastCheck' => ! empty( $license_data['last_check'] ) ? gmdate( 'c', (int) $license_data['last_check'] ) : '',
			'activated' => ! empty( $license_data['activated'] ) ? gmdate( 'c', (int) $license_data['activated'] ) : '',
			'maskedKey' => strlen( $license_key ) > 8
				? substr( $license_key, 0, 4 ) . str_repeat( '*', strlen( $license_key ) - 8 ) . substr( $license_key, -4 )
				: $license_key,
		);

		// v0.6.5+ — Pre-built URL for the in-app "Re-validate Now" button so
		// the React component does not have to know how to mint the WP nonce.
		// Empty string when no active license (button is hidden in that state).
		$revalidate_url = $license_active
			? wp_nonce_url(
				add_query_arg(
					array(
						'page'              => 'mhm-currency-switcher',
						'mhm_cs_revalidate' => '1',
					),
					admin_url( 'admin.php' )
				),
				'mhm_cs_revalidate'
			)
			: '';

		wp_localize_script(
			'mhm-cs-admin',
			'mhmCsAdmin',
			array(
				'restUrl'          => rest_url( 'mhm-currency/v1/' ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'isPro'            => Mode::is_pro(),
				'baseCurrency'     => function_exists( 'get_option' )
					? get_option( 'woocommerce_currency', 'USD' )
					: 'USD',
				'wcCurrencies'     => $wc_currencies,
				'wcPaymentMethods' => $payment_methods,
				'flagBaseUrl'      => MHM_CS_URL . 'assets/images/flags/',
				'flagMap'          => \MhmCurrencySwitcher\Frontend\FlagMapper::get_map(),
				'license'          => $license_info,
				'revalidateUrl'    => $revalidate_url,
				'pluginVersion'    => defined( 'MHM_CS_VERSION' ) ? MHM_CS_VERSION : '0.0.0',
			)
		);
	}
}
