<?php
/**
 * Frontend asset enqueue — registers CSS and JS for the switcher.
 *
 * Conditionally enqueues the switcher stylesheet and script on the
 * frontend when WooCommerce is active.
 *
 * @package MhmCurrencySwitcher\Frontend
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Frontend;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue — frontend CSS and JS asset loader.
 *
 * Hooks into `wp_enqueue_scripts` to register and enqueue the
 * currency switcher stylesheet and JavaScript on the public site.
 *
 * @since 0.3.0
 */
final class Enqueue {

	/**
	 * Register the enqueue hook.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue frontend assets.
	 *
	 * Only loads when WooCommerce is active (class_exists check).
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		wp_enqueue_style(
			'mhm-cs-switcher',
			MHM_CS_URL . 'assets/css/switcher.css',
			array(),
			MHM_CS_VERSION
		);

		wp_enqueue_style(
			'mhm-cs-product-widget',
			MHM_CS_URL . 'assets/css/product-widget.css',
			array(),
			MHM_CS_VERSION
		);

		wp_enqueue_script(
			'mhm-cs-switcher',
			MHM_CS_URL . 'assets/js/switcher.js',
			array(),
			MHM_CS_VERSION,
			true
		);
	}
}
