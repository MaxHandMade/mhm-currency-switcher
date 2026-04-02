<?php // phpcs:ignoreFile
/**
 * Elementor Currency Prices widget.
 *
 * Wraps the ProductWidget shortcode output inside an Elementor
 * widget for the page builder.
 *
 * @package MhmCurrencySwitcher\Integration\Elementor
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Integration\Elementor;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Frontend\ProductWidget;

/**
 * PriceDisplayWidget — Elementor widget for multi-currency price display.
 *
 * @since 0.4.0
 */
class PriceDisplayWidget extends \Elementor\Widget_Base {

	/**
	 * Get widget name.
	 *
	 * @return string Widget name.
	 */
	public function get_name(): string {
		return 'mhm_cs_price_display';
	}

	/**
	 * Get widget title.
	 *
	 * @return string Widget title.
	 */
	public function get_title(): string {
		return 'Currency Prices';
	}

	/**
	 * Get widget icon.
	 *
	 * @return string Elementor icon class.
	 */
	public function get_icon(): string {
		return 'eicon-price-list';
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
		$this->start_controls_section(
			'content_section',
			array(
				'label' => 'Content',
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'currencies',
			array(
				'label'       => 'Currencies',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => 'USD,EUR,GBP',
				'placeholder' => 'USD,EUR,GBP',
				'description' => 'Comma-separated currency codes to display.',
			)
		);

		$this->add_control(
			'show_flag',
			array(
				'label'        => 'Show Flags',
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => 'Yes',
				'label_off'    => 'No',
				'return_value' => 'yes',
				'default'      => 'yes',
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
		$converter = new Converter( $store );
		$widget    = new ProductWidget( $store, $converter );

		echo $widget->render_shortcode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'currencies' => $settings['currencies'] ?? '',
			)
		);
	}
}
