<?php // phpcs:ignoreFile
/**
 * Elementor integration bootstrap.
 *
 * Registers custom Elementor widgets and a dedicated category
 * for the MHM Currency Switcher plugin.
 *
 * @package MhmCurrencySwitcher\Integration\Elementor
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Integration\Elementor;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ElementorIntegration — widget and category registration.
 *
 * @since 0.4.0
 */
final class ElementorIntegration {

	/**
	 * Check whether Elementor is loaded and active.
	 *
	 * @return bool True when Elementor has been loaded.
	 */
	public static function is_active(): bool {
		return did_action( 'elementor/loaded' ) > 0;
	}

	/**
	 * Hook into Elementor registration events.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'elementor/widgets/register', array( self::class, 'register_widgets' ) );
		add_action( 'elementor/elements/categories_registered', array( self::class, 'register_category' ) );
	}

	/**
	 * Register custom widgets with the Elementor widgets manager.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
	 * @return void
	 */
	public static function register_widgets( $widgets_manager ): void {
		$widgets_manager->register( new SwitcherWidget() );
		$widgets_manager->register( new PriceDisplayWidget() );
	}

	/**
	 * Register a custom Elementor widget category.
	 *
	 * @param \Elementor\Elements_Manager $elements_manager Elementor elements manager.
	 * @return void
	 */
	public static function register_category( $elements_manager ): void {
		$elements_manager->add_category(
			'mhm-currency-switcher',
			array(
				'title' => 'MHM Currency Switcher',
				'icon'  => 'eicon-globe',
			)
		);
	}
}
