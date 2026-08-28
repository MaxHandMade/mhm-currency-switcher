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
	 * The hook suffix `add_submenu_page()` returned for this page.
	 *
	 * Exposed publicly so other code can compare against the REAL, runtime
	 * value WordPress assigned — e.g. CacheCompatDiagnostic::SCREENS, whose
	 * admin-notice scoping names this page by its hook suffix. Duplicating
	 * that suffix as a second hardcoded literal there could drift from this
	 * class's own slug/parent without either side failing; a test that reads
	 * it from here instead is reading the same value WordPress itself would
	 * use to decide whether to call `render_notice()`.
	 *
	 * @since 2.0.1
	 * @return string Hook suffix once `add_menu_page()` has run; '' before then.
	 */
	public function get_hook_suffix(): string {
		return $this->hook_suffix;
	}

	/**
	 * Render the admin page — just a mount point for React.
	 *
	 * @return void
	 */
	public function render_page(): void {
		echo '<div class="wrap">';
		echo '<div id="mhmcs-admin-root"></div>';
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
			'mhmcs-admin',
			MHMCS_URL . 'admin-app/build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations(
			'mhmcs-admin',
			'mhm-currency-switcher',
			MHMCS_PATH . 'languages'
		);

		wp_enqueue_style(
			'mhmcs-admin',
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
			'mhmcs-admin',
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
				'about'        => self::about_payload(),
			)
		);
	}

	/**
	 * Static content for the About tab.
	 *
	 * 🔴 Every URL here is plain, with NO tracking or campaign parameters, and
	 * nothing on that tab makes a network request. That is a guideline, not a
	 * preference: the plugin directory's rule on the admin dashboard says
	 * advertising "should be avoided" and then draws one hard line —
	 * "tracking referrals via those ads is not permitted". A tab the shop owner
	 * chooses to open is not the nagging the same rule is aimed at, but a
	 * tagged link would cross the line that rule actually forbids.
	 *
	 * The sibling-plugin block is hidden when that plugin is already active:
	 * telling somebody about software they are running is noise, and it keeps
	 * the promotional surface to the installs where it could mean anything.
	 * The marker is MHMRENTIVA_VERSION rather than is_plugin_active(), which
	 * lives in wp-admin/includes/plugin.php and would tie this method to the
	 * admin request context for no gain. If that constant is ever renamed the
	 * check simply reads false and the block shows — the harmless direction.
	 *
	 * @return array<string, string|bool>
	 */
	private static function about_payload(): array {
		return array(
			'version'       => MHMCS_VERSION,
			'docsUrl'       => 'https://maxhandmade.github.io/mhm-currency-switcher-docs/',
			'forumUrl'      => 'https://wordpress.org/support/plugin/mhm-currency-switcher/',
			'issuesUrl'     => 'https://github.com/MaxHandMade/mhm-currency-switcher/issues',
			'siteUrl'       => 'https://wpalemi.com',
			'supportEmail'  => 'support@wpalemi.com',
			'siblingUrl'    => 'https://wordpress.org/plugins/mhm-rentiva/',
			'siblingActive' => defined( 'MHMRENTIVA_VERSION' ),
		);
	}
}
