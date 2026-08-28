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
	const META_KEY = '_mhmcs_fixed_prices';

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
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_tab' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
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
		$tabs['mhmcs_currency_prices'] = array(
			'label'    => __( 'Currency Prices', 'mhm-currency-switcher' ),
			'target'   => 'mhmcs_currency_prices_panel',
			'class'    => array(),
			'priority' => 80,
		);

		return $tabs;
	}

	/**
	 * Load the product-screen stylesheet.
	 *
	 * These rules used to be `style="..."` attributes echoed straight into the
	 * panel markup. WordPress.org's guidance on inline CSS makes no exception
	 * for admin screens; Plugin Check does not flag it either way, so this
	 * moved on the rule rather than on a finding.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'mhmcs-admin-product',
			MHMCS_URL . 'assets/css/admin-product.css',
			array(),
			MHMCS_VERSION
		);
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

		$currencies = $this->store->get_currencies();
		$saved      = $this->get_fixed_prices( $post->ID );
		$base       = $this->store->get_base_currency();
		$flag_base  = MHMCS_URL . 'assets/images/flags/';

		echo '<div id="mhmcs_currency_prices_panel" class="panel woocommerce_options_panel hidden">';
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
			$code     = $currency['code'];
			$country  = FlagMapper::get_country( $code );
			$flag_url = $flag_base . $country . '.svg';
			$value    = $saved[ $code ] ?? '';
			$field_id = 'mhmcs_price_' . strtolower( $code );
			$symbol   = $currency['format']['symbol'] ?? $code;

			echo '<p class="form-field ' . esc_attr( $field_id ) . '_field">';
			echo '<label for="' . esc_attr( $field_id ) . '">';
			echo '<img src="' . esc_url( $flag_url ) . '" alt="' . esc_attr( $code ) . '" '
				. 'class="mhmcs-admin-flag" />';
			echo esc_html( $code ) . ' (' . esc_html( $symbol ) . ')';
			echo '</label>';
			echo '<input type="text" class="short wc_input_price" id="' . esc_attr( $field_id ) . '" '
				. 'name="mhmcs_fixed_prices[' . esc_attr( $code ) . ']" '
				. 'value="' . esc_attr( $value ) . '" '
				. 'placeholder="' . esc_attr__( 'Auto', 'mhm-currency-switcher' ) . '" />';
			echo '</p>';
		}

		echo '</div>';

		wp_nonce_field( 'mhmcs_save_product_prices', 'mhmcs_product_prices_nonce' );

		echo '</div>';
	}

	/**
	 * Save fixed prices when product is saved.
	 *
	 * @param int $post_id Product post ID.
	 * @return void
	 */
	public function save_prices( int $post_id ): void {
		if ( ! isset( $_POST['mhmcs_product_prices_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mhmcs_product_prices_nonce'] ) ), 'mhmcs_save_product_prices' )
		) {
			return;
		}

		/*
		 * WooCommerce fires woocommerce_process_product_meta only after its own
		 * edit_post check, so this is belt and braces — but it is the object
		 * -level check, on THIS product, and it costs one line. A nonce proves
		 * the request came from our form, not that its sender may edit this
		 * post.
		 */
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw_prices = isset( $_POST['mhmcs_fixed_prices'] ) && is_array( $_POST['mhmcs_fixed_prices'] )
			? map_deep( wp_unslash( $_POST['mhmcs_fixed_prices'] ), 'sanitize_text_field' )
			: array();
		$prices     = self::sanitize_fixed_price_map( $raw_prices );

		if ( empty( $prices ) ) {
			delete_post_meta( $post_id, self::META_KEY );
		} else {
			update_post_meta( $post_id, self::META_KEY, wp_json_encode( $prices ) );
		}
	}

	/**
	 * Render fixed price fields on variation pricing row.
	 *
	 * @param int                  $loop           Variation loop index.
	 * @param array<string, mixed> $variation_data Variation data array.
	 * @param object               $variation      WP_Post variation object.
	 * @return void
	 */
	public function render_variation_fields( int $loop, array $variation_data, object $variation ): void {
		$currencies = $this->store->get_currencies();
		$saved      = $this->get_fixed_prices( $variation->ID );
		$flag_base  = MHMCS_URL . 'assets/images/flags/';

		if ( empty( $currencies ) ) {
			return;
		}

		echo '<div class="mhmcs-variation-prices">';
		echo '<p class="mhmcs-variation-heading">';
		echo esc_html__( 'Fixed Currency Prices', 'mhm-currency-switcher' );
		echo '</p>';

		foreach ( $currencies as $currency ) {
			$code     = $currency['code'];
			$country  = FlagMapper::get_country( $code );
			$flag_url = $flag_base . $country . '.svg';
			$value    = $saved[ $code ] ?? '';
			$name     = 'mhmcs_variation_prices[' . $loop . '][' . $code . ']';

			echo '<label class="mhmcs-variation-field">';
			echo '<img src="' . esc_url( $flag_url ) . '" alt="' . esc_attr( $code ) . '" '
				. 'class="mhmcs-variation-flag" />';
			echo '<span class="mhmcs-variation-code">' . esc_html( $code ) . '</span>';
			echo '<input type="text" class="wc_input_price mhmcs-variation-input" name="' . esc_attr( $name ) . '" '
				. 'value="' . esc_attr( $value ) . '" '
				. 'placeholder="' . esc_attr__( 'Auto', 'mhm-currency-switcher' ) . '" />';
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
		if ( ! isset( $_POST['mhmcs_product_prices_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mhmcs_product_prices_nonce'] ) ), 'mhmcs_save_product_prices' )
		) {
			return;
		}

		// Object-level check on the variation being written; see save_prices().
		if ( ! current_user_can( 'edit_post', $variation_id ) ) {
			return;
		}

		$all_variation_prices = isset( $_POST['mhmcs_variation_prices'] ) && is_array( $_POST['mhmcs_variation_prices'] )
			? map_deep( wp_unslash( $_POST['mhmcs_variation_prices'] ), 'sanitize_text_field' )
			: array();
		$raw_prices           = isset( $all_variation_prices[ $loop ] ) && is_array( $all_variation_prices[ $loop ] )
			? $all_variation_prices[ $loop ]
			: array();
		$prices               = self::sanitize_fixed_price_map( $raw_prices );

		if ( empty( $prices ) ) {
			delete_post_meta( $variation_id, self::META_KEY );
		} else {
			update_post_meta( $variation_id, self::META_KEY, wp_json_encode( $prices ) );
		}
	}

	/**
	 * Turn a submitted currency-code => price map into what may be stored.
	 *
	 * 🔴 One rule, previously two copies — the product form and the variation
	 * form each carried their own loop, and both asked only `is_numeric()`.
	 * Two of the three values that question lets through cannot be stored
	 * safely:
	 *
	 * - Too large to represent. Measured: `is_numeric('1e309')` is true,
	 *   `floatval()` gives `INF`, `(string) INF` is the literal `"INF"`, and
	 *   reading it back with `(float) "INF"` gives `0.0`, because `"INF"` is
	 *   not a numeric string. The fixed price silently becomes zero, and
	 *   `PriceFilter::convert_price()` returns it ahead of any exchange rate —
	 *   the shop sells the product for nothing in that currency, with every
	 *   gate green, because zero is a valid float.
	 * - Negative. Nothing downstream neutralises it: it reaches
	 *   `woocommerce_product_get_price` and becomes a negative line total.
	 *
	 * A comma decimal separator is still accepted and converted, because that
	 * is what most of Europe types into the field.
	 *
	 * @since 1.3.1
	 *
	 * @param array<string, mixed> $raw Submitted map, already unslashed.
	 * @return array<string, string> Currency code => storable price string.
	 */
	public static function sanitize_fixed_price_map( array $raw ): array {
		$prices = array();

		foreach ( $raw as $code => $value ) {
			$code  = sanitize_text_field( (string) $code );
			$value = sanitize_text_field( (string) $value );

			if ( 1 !== preg_match( '/^[A-Z]{3}$/', $code ) ) {
				continue;
			}

			$normalised = str_replace( ',', '.', $value );

			if ( '' === $normalised || ! is_numeric( $normalised ) ) {
				continue;
			}

			$number = (float) $normalised;

			if ( ! is_finite( $number ) || $number < 0.0 ) {
				continue;
			}

			$prices[ $code ] = (string) $number;
		}

		return $prices;
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
