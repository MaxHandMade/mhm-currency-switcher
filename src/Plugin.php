<?php
/**
 * Plugin singleton orchestrator.
 *
 * @package MhmCurrencySwitcher
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class — singleton orchestrator.
 *
 * Boots all services and coordinates plugin lifecycle.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Private constructor to enforce singleton.
	 */
	private function __construct() {
		$this->register_hooks();
	}

	/**
	 * Bootstrap the plugin.
	 *
	 * Creates the singleton instance if not already created.
	 *
	 * @return self
	 */
	public static function bootstrap(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register core hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'init', array( $this, 'initialize_services' ), 2 );
	}

	/**
	 * Load plugin text domain.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'mhm-currency-switcher',
			false,
			dirname( MHM_CS_BASENAME ) . '/languages'
		);
	}

	/**
	 * Initialize plugin services.
	 *
	 * @return void
	 */
	public function initialize_services(): void {
		// Phase 2: ExchangeRateProvider + CurrencyService.
		// Phase 3: Admin settings page.
		// Phase 4: Frontend widget / block.
		// Phase 5: WooCommerce price filters.
		// Phase 6: Checkout currency handling.
		// Phase 7: Geolocation auto-detect.
	}
}
