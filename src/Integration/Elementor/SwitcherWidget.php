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

use MhmCurrencySwitcher\Core\ConversionContext;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;
use MhmCurrencySwitcher\Frontend\Switcher;

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
		$settings = $this->get_settings_for_display();

		$store = new CurrencyStore();

		/*
		 * A context of its own, not the plugin's. This widget builds a
		 * throwaway detection service purely to read the visitor's current
		 * currency for the dropdown; register() is never called on it, so the
		 * cookie priming that consults the context never runs here. Reaching
		 * for the request's shared instance would mean exposing it globally
		 * for a collaborator this path does not actually use.
		 */
		$detection = new DetectionService( $store, new ConversionContext() );
		$switcher  = new Switcher( $store, $detection );

		$output = $switcher->render_shortcode(
			array(
				'size' => $settings['size'] ?? 'medium',
			)
		);

		echo wp_kses_post( $output );
	}
}
