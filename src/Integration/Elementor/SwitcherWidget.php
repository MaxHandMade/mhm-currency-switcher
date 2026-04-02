<?php // phpcs:ignoreFile
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
		return 'mhm_cs_switcher';
	}

	/**
	 * Get widget title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return 'Currency Switcher';
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
				'label' => 'Content',
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'size',
			array(
				'label'   => 'Size',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'medium',
				'options' => array(
					'small'  => 'Small',
					'medium' => 'Medium',
					'large'  => 'Large',
				),
			)
		);

		$this->end_controls_section();

		// Style section.
		$this->start_controls_section(
			'style_section',
			array(
				'label' => 'Style',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'text_color',
			array(
				'label'     => 'Text Color',
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

		$store     = new CurrencyStore();
		$detection = new DetectionService( $store );
		$switcher  = new Switcher( $store, $detection );

		echo $switcher->render_shortcode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'size' => $settings['size'] ?? 'medium',
			)
		);
	}
}
