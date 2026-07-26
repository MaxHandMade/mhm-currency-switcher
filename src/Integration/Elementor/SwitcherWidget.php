<?php
/**
 * Elementor Currency Switcher widget.
 *
 * Wraps the Switcher shortcode output inside an Elementor widget
 * so it can be used in the Elementor page builder.
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
 * SwitcherWidget — Elementor widget for currency switching dropdown.
 *
 * @since 0.4.0
 */
class SwitcherWidget extends \Elementor\Widget_Base {

	/**
	 * Get widget name.
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'mhmcs_switcher';
	}

	/**
	 * Get widget title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return __( 'Currency Switcher', 'mhm-currency-switcher' );
	}

	/**
	 * Get widget icon.
	 *
	 * @return string Elementor icon class.
	 */
	public function get_icon(): string {
		return 'eicon-globe';
	}

	/**
	 * Get widget categories.
	 *
	 * @return array<int, string> Category slugs.
	 */
	public function get_categories(): array {
		return array( 'mhm-currency-switcher' );
	}

	/**
	 * Register widget controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		// Content section.
		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Content', 'mhm-currency-switcher' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'size',
			array(
				'label'   => __( 'Size', 'mhm-currency-switcher' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'medium',
				'options' => array(
					'small'  => __( 'Small', 'mhm-currency-switcher' ),
					'medium' => __( 'Medium', 'mhm-currency-switcher' ),
					'large'  => __( 'Large', 'mhm-currency-switcher' ),
				),
			)
		);

		$this->end_controls_section();

		// Style section.
		$this->start_controls_section(
			'style_section',
			array(
				'label' => __( 'Style', 'mhm-currency-switcher' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'text_color',
			array(
				'label'     => __( 'Text Color', 'mhm-currency-switcher' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .mhm-cs-switcher' => 'color: {{VALUE}}',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render the widget output.
	 *
	 * @return void
	 */
	protected function render(): void {
		/*
		 * The request's shared switcher, not a throwaway one.
		 *
		 * This used to build its own CurrencyStore, DetectionService and
		 * ConversionContext, on the reasoning that the widget only wanted to
		 * read the visitor's currency and had no use for the plugin's
		 * collaborators. That reasoning predates the switcher needing the
		 * context: the renderer now asks should_convert() to decide whether
		 * the markup may name the visitor's currency at all, and a fresh
		 * ConversionContext carries none of the request's latch state, so it
		 * can answer differently from every other surface on the same page.
		 * That is a correctness bug, not a style point — a "convert" request
		 * whose Elementor switcher rendered neutral, or the reverse.
		 *
		 * It also retires the second DetectionService this path created,
		 * which gave Elementor pages their own geolocation memo and so
		 * geolocated the visitor twice.
		 *
		 * Elementor rebuilds widgets per element with `new $class( $data,
		 * $args )`, so there is no constructor to inject into; the static
		 * holder on ElementorIntegration is the seam, and Plugin.php fills it
		 * in the same code path that registers this widget.
		 */
		$switcher = ElementorIntegration::get_switcher();

		if ( null === $switcher ) {
			return;
		}

		$settings = $this->get_settings_for_display();

		$output = $switcher->render_shortcode(
			array(
				'size' => $settings['size'] ?? 'medium',
			)
		);

		echo wp_kses_post( $output );
	}
}
