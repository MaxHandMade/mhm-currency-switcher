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
		echo '<div class="wrap"><div id="mhm-cs-admin-root"></div></div>';
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
			)
		);
	}
}
