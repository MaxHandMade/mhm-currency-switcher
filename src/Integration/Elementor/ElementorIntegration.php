<?php
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

use MhmCurrencySwitcher\Frontend\Switcher;

/**
 * ElementorIntegration — widget and category registration.
 *
 * @since 0.4.0
 */
final class ElementorIntegration {

	/**
	 * The request's shared switcher renderer.
	 *
	 * A static holder because Elementor gives no other seam: it rebuilds a
	 * widget per element with `new $class( $data, $args )`, so constructor
	 * injection into SwitcherWidget is not available, and this class is
	 * already all-static. Plugin.php sets it in the same code path that calls
	 * init(), so a registered integration always has one.
	 *
	 * @var Switcher|null
	 */
	private static ?Switcher $switcher = null;

	/**
	 * Hand the integration the request's shared switcher renderer.
	 *
	 * @param Switcher $switcher Shared switcher renderer.
	 * @return void
	 */
	public static function set_switcher( Switcher $switcher ): void {
		self::$switcher = $switcher;
	}

	/**
	 * The shared switcher renderer, or null when the plugin never wired one.
	 *
	 * @return Switcher|null
	 */
	public static function get_switcher(): ?Switcher {
		return self::$switcher;
	}

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
				'title' => __( 'MHM Currency Switcher', 'mhm-currency-switcher' ),
				'icon'  => 'eicon-globe',
			)
		);
	}
}
