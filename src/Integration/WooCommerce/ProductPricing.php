<?php
/**
 * Per-product fixed currency pricing — WooCommerce product editor panel.
 *
 * Adds a "Currency Prices" tab to the WooCommerce Product Data metabox,
 * allowing store owners to set fixed prices per currency instead of
 * relying on automatic exchange rate conversion.
 *
 * @package MhmCurrencySwitcher\Integration\WooCommerce
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Integration\WooCommerce;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Frontend\FlagMapper;
use MhmCurrencySwitcher\License\Mode;

/**
 * ProductPricing — per-product fixed currency prices.
 *
 * @since 0.3.0
 */
final class ProductPricing {

	/**
	 * Post meta key for fixed currency prices.
	 *
	 * @var string
	 */
	const META_KEY = '_mhm_cs_fixed_prices';

	/**
	 * Currency data store.
	 *
	 * @var CurrencyStore
	 */
	private CurrencyStore $store;

	/**
	 * Constructor.
	 *
	 * @param CurrencyStore $store Currency data store.
	 */
	public function __construct( CurrencyStore $store ) {
		$this->store = $store;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! Mode::can_use_fixed_prices() ) {
			return;
		}

		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_prices' ) );

		// Variation support.
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_prices' ), 10, 2 );
	}

	/**
	 * Add "Currency Prices" tab to Product Data tabs.
	 *
	 * @param array<string, array<string, mixed>> $tabs Existing tabs.
	 * @return array<string, array<string, mixed>> Modified tabs.
	 */
	public function add_product_tab( array $tabs ): array {
		$tabs['mhm_currency_prices'] = array(
			'label'    => __( 'Currency Prices', 'mhm-currency-switcher' ),
			'target'   => 'mhm_currency_prices_panel',
			'class'    => array(),
			'priority' => 80,
		);

		return $tabs;
	}

	/**
	 * Render the Currency Prices panel content.
	 *
	 * @return void
	 */
	public function render_panel(): void {
		global $post;

		if ( ! $post ) {
			return;
		}

		$currencies  = $this->store->get_currencies();
		$saved       = $this->get_fixed_prices( $post->ID );
		$base        = $this->store->get_base_currency();
		$flag_base   = MHM_CS_URL . 'assets/images/flags/';

		echo '<div id="mhm_currency_prices_panel" class="panel woocommerce_options_panel hidden">';
		echo '<div class="options_group">';

		echo '<p class="form-field">';
		echo '<strong>' . esc_html__( 'Fixed Price for Each Currency', 'mhm-currency-switcher' ) . '</strong><br>';
		echo '<span class="description">';
		echo esc_html__( 'Set a fixed price per currency. Leave empty to use automatic exchange rate conversion.', 'mhm-currency-switcher' );
		echo '</span>';
		echo '</p>';

		if ( empty( $currencies ) ) {
			echo '<p class="form-field">';
			echo '<em>' . esc_html__( 'No currencies configured. Add currencies in MHM Currency Switcher settings.', 'mhm-currency-switcher' ) . '</em>';
			echo '</p>';
		}

		foreach ( $currencies as $currency ) {
			$code      = $currency['code'];
			$country   = FlagMapper::get_country( $code );
			$flag_url  = $flag_base . $country . '.svg';
			$value     = $saved[ $code ] ?? '';
			$field_id  = 'mhm_cs_price_' . strtolower( $code );
			$symbol    = $currency['format']['symbol'] ?? $code;

			echo '<p class="form-field ' . esc_attr( $field_id ) . '_field">';
			echo '<label for="' . esc_attr( $field_id ) . '">';
			echo '<img src="' . esc_url( $flag_url ) . '" alt="' . esc_attr( $code ) . '" '
				. 'style="width:20px;height:15px;vertical-align:middle;margin-right:5px;border-radius:2px;box-shadow:0 0 1px rgba(0,0,0,0.2);" />';
			echo esc_html( $code ) . ' (' . esc_html( $symbol ) . ')';
			echo '</label>';
			echo '<input type="text" class="short wc_input_price" id="' . esc_attr( $field_id ) . '" '
				. 'name="mhm_cs_fixed_prices[' . esc_attr( $code ) . ']" '
				. 'value="' . esc_attr( $value ) . '" '
				. 'placeholder="' . esc_attr__( 'Auto', 'mhm-currency-switcher' ) . '" />';
			echo '</p>';
		}

		echo '</div>';

		wp_nonce_field( 'mhm_cs_save_product_prices', 'mhm_cs_product_prices_nonce' );

		echo '</div>';
	}

	/**
	 * Save fixed prices when product is saved.
	 *
	 * @param int $post_id Product post ID.
	 * @return void
	 */
	public function save_prices( int $post_id ): void {
		if ( ! isset( $_POST['mhm_cs_product_prices_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mhm_cs_product_prices_nonce'] ) ), 'mhm_cs_save_product_prices' )
		) {
			return;
		}

		$prices = array();

		if ( isset( $_POST['mhm_cs_fixed_prices'] ) && is_array( $_POST['mhm_cs_fixed_prices'] ) ) {
			foreach ( $_POST['mhm_cs_fixed_prices'] as $code => $value ) {
				$code  = sanitize_text_field( $code );
				$value = sanitize_text_field( wp_unslash( $value ) );

				if ( 1 !== preg_match( '/^[A-Z]{3}$/', $code ) ) {
					continue;
				}

				// Store non-empty numeric values only.
				if ( '' !== $value && is_numeric( str_replace( ',', '.', $value ) ) ) {
					$prices[ $code ] = (string) floatval( str_replace( ',', '.', $value ) );
				}
			}
		}

		if ( empty( $prices ) ) {
			delete_post_meta( $post_id, self::META_KEY );
		} else {
			update_post_meta( $post_id, self::META_KEY, wp_json_encode( $prices ) );
		}
	}

	/**
	 * Render fixed price fields on variation pricing row.
	 *
	 * @param int    $loop           Variation loop index.
	 * @param array  $variation_data Variation data array.
	 * @param object $variation      WP_Post variation object.
	 * @return void
	 */
	public function render_variation_fields( int $loop, array $variation_data, object $variation ): void {
		$currencies = $this->store->get_currencies();
		$saved      = $this->get_fixed_prices( $variation->ID );
		$flag_base  = MHM_CS_URL . 'assets/images/flags/';

		if ( empty( $currencies ) ) {
			return;
		}

		echo '<div class="mhm-cs-variation-prices" style="width:100%;margin-top:10px;padding-top:10px;border-top:1px solid #eee;">';
		echo '<p style="font-weight:600;margin:0 0 5px;">';
		echo esc_html__( 'Fixed Currency Prices', 'mhm-currency-switcher' );
		echo '</p>';

		foreach ( $currencies as $currency ) {
			$code     = $currency['code'];
			$country  = FlagMapper::get_country( $code );
			$flag_url = $flag_base . $country . '.svg';
			$value    = $saved[ $code ] ?? '';
			$name     = 'mhm_cs_variation_prices[' . $loop . '][' . $code . ']';

			echo '<label style="display:inline-flex;align-items:center;gap:4px;margin-right:12px;margin-bottom:5px;">';
			echo '<img src="' . esc_url( $flag_url ) . '" alt="' . esc_attr( $code ) . '" '
				. 'style="width:16px;height:12px;border-radius:1px;" />';
			echo '<span style="font-size:12px;">' . esc_html( $code ) . '</span>';
			echo '<input type="text" class="wc_input_price" name="' . esc_attr( $name ) . '" '
				. 'value="' . esc_attr( $value ) . '" '
				. 'placeholder="' . esc_attr__( 'Auto', 'mhm-currency-switcher' ) . '" '
				. 'style="width:90px;" />';
			echo '</label>';
		}

		echo '</div>';
	}

	/**
	 * Save variation fixed prices.
	 *
	 * @param int $variation_id Variation post ID.
	 * @param int $loop         Variation loop index.
	 * @return void
	 */
	public function save_variation_prices( int $variation_id, int $loop ): void {
		$prices = array();

		if ( isset( $_POST['mhm_cs_variation_prices'][ $loop ] ) && is_array( $_POST['mhm_cs_variation_prices'][ $loop ] ) ) {
			foreach ( $_POST['mhm_cs_variation_prices'][ $loop ] as $code => $value ) {
				$code  = sanitize_text_field( $code );
				$value = sanitize_text_field( wp_unslash( $value ) );

				if ( 1 !== preg_match( '/^[A-Z]{3}$/', $code ) ) {
					continue;
				}

				if ( '' !== $value && is_numeric( str_replace( ',', '.', $value ) ) ) {
					$prices[ $code ] = (string) floatval( str_replace( ',', '.', $value ) );
				}
			}
		}

		if ( empty( $prices ) ) {
			delete_post_meta( $variation_id, self::META_KEY );
		} else {
			update_post_meta( $variation_id, self::META_KEY, wp_json_encode( $prices ) );
		}
	}

	/**
	 * Get fixed prices for a product.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return array<string, string> Currency code => price map.
	 */
	public static function get_fixed_prices( int $product_id ): array {
		$raw = get_post_meta( $product_id, self::META_KEY, true );

		if ( empty( $raw ) || ! is_string( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Get the fixed price for a specific currency.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $currency   Currency code.
	 * @return float|null Fixed price, or null when not set.
	 */
	public static function get_fixed_price( int $product_id, string $currency ): ?float {
		$prices = self::get_fixed_prices( $product_id );

		if ( isset( $prices[ $currency ] ) && '' !== $prices[ $currency ] ) {
			return (float) $prices[ $currency ];
		}

		return null;
	}
}
