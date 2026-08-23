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
		echo '<div class="wrap">';
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

		$asset_file = MHMCS_PATH . 'admin-app/build/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				// Mirrors what wp-scripts emits into index.asset.php. `wp-a11y` is here
				// because CopyableCode speaks the copy result; drop it and the bundle
				// breaks on any install where the asset file is missing.
				'dependencies' => array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-a11y', 'react-jsx-runtime' ),
				'version'      => MHMCS_VERSION,
			);

		wp_enqueue_script(
			'mhm-cs-admin',
			MHMCS_URL . 'admin-app/build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations(
			'mhm-cs-admin',
			'mhm-currency-switcher',
			MHMCS_PATH . 'languages'
		);

		wp_enqueue_style(
			'mhm-cs-admin',
			MHMCS_URL . 'admin-app/build/style-index.css',
			array( 'wp-components' ),
			$asset['version']
		);

		wp_enqueue_style( 'dashicons' );

		// Build WC currencies list (safe for non-WC contexts).
		$wc_currencies = function_exists( 'get_woocommerce_currencies' )
			? get_woocommerce_currencies()
			: array();

		/*
		 * 🔴 `restUrl` and `nonce` used to be here and are deliberately
		 * gone. The panel talks to the REST API through
		 * `@wordpress/api-fetch`, which resolves the root itself from a
		 * relative `path` and receives its nonce from the middleware core
		 * registers alongside the `wp-api-fetch` handle. Both hand-rolled
		 * values had stopped being read the day that dependency arrived,
		 * and nothing said so — a nonce printed into every admin page and
		 * consumed by nothing invites the next person to build on wiring
		 * that was never connected. `pluginVersion` went the same way.
		 *
		 * Authorisation is unaffected and never rested on that nonce: every
		 * admin route registers `check_admin_permission()`, which is
		 * `current_user_can( 'manage_woocommerce' )`.
		 *
		 * BaseSymbolLocalizeWiringTest reads this array back out of the
		 * script registry and fails on any key the panel source never
		 * names, so the next dead one is caught here rather than years
		 * later.
		 */
		wp_localize_script(
			'mhm-cs-admin',
			'mhmCsAdmin',
			array(
				'baseCurrency' => function_exists( 'get_option' )
					? get_option( 'woocommerce_currency', 'USD' )
					: 'USD',

				/*
				 * The base currency has no row in mhmcs_currencies — it is not
				 * a conversion target — so GET /currencies never carries its
				 * symbol, and no other value here carries a symbol at all. The
				 * Display preview needs it to render the base row the way the
				 * storefront does.
				 */
				'baseSymbol'   => \MhmCurrencySwitcher\Admin\RestAPI::default_symbol_for(
					function_exists( 'get_option' )
						? (string) get_option( 'woocommerce_currency', 'USD' )
						: 'USD'
				),
				'wcCurrencies' => $wc_currencies,
				'flagBaseUrl'  => MHMCS_URL . 'assets/images/flags/',
				'flagMap'      => \MhmCurrencySwitcher\Frontend\FlagMapper::get_map(),
			)
		);
	}
}
