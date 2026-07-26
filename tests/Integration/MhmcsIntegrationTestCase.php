<?php
/**
 * Shared scaffolding for the WooCommerce currency-conversion integration
 * tests: a configured non-base target currency, a simulated visitor, and
 * helpers for building real WooCommerce products without WooCommerce's own
 * test framework (WC_Helper_Product lives inside WooCommerce's *test*
 * checkout, which is not present when WooCommerce is installed as a
 * normal plugin, as it is here).
 *
 * Not itself a test: the phpunit-integration.xml.dist testsuite only picks
 * up files ending in "Test.php", so this file is never run directly.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use MhmCurrencySwitcher\Core\ConversionContext;
use MhmCurrencySwitcher\Plugin;
use ReflectionProperty;
use WP_UnitTestCase;
use WP_REST_Request;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Product_Attribute;

/**
 * Class MhmcsIntegrationTestCase
 */
abstract class MhmcsIntegrationTestCase extends WP_UnitTestCase {

	/**
	 * ISO 4217 code of the non-base currency configured for these tests.
	 *
	 * @var string
	 */
	protected const TARGET_CURRENCY = 'EUR';

	/**
	 * Exchange rate used for TARGET_CURRENCY. No fee, no rounding, so every
	 * assertion in these suites can rely on plain multiplication.
	 *
	 * @var float
	 */
	protected const TARGET_RATE = 2.0;

	/**
	 * Deliberately NOT a real-world currency symbol. WooCommerce ships its
	 * own default symbol for EUR; if a test asserted against that glyph, it
	 * could pass whether or not FormatFilter's override actually ran. This
	 * marker can only appear in rendered output if the plugin's own
	 * currency-format filter fired.
	 *
	 * @var string
	 */
	protected const TARGET_SYMBOL = 'CURX';

	/**
	 * Administrator user ID, created once per test class.
	 *
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * Create the shared administrator user for the class.
	 *
	 * `manage_woocommerce` (required by the plugin's own settings REST
	 * routes and, in these tests, also used to authenticate wc/v3 REST
	 * calls) is granted to the `administrator` role by WC_Install::install()
	 * during bootstrap.
	 *
	 * @param \WP_UnitTest_Factory $factory Core test factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Reset to a known state before every test: base currency USD, the
	 * visitor carrying no currency cookie, and TARGET_CURRENCY configured
	 * against the live, shared CurrencyStore instance.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->reset_conversion_context();

		update_option( 'woocommerce_currency', 'USD' );

		$this->clear_visitor_currency();

		$this->configure_currency(
			array(
				'code'     => self::TARGET_CURRENCY,
				'enabled'  => true,
				'rate'     => array(
					'type'  => 'manual',
					'value' => self::TARGET_RATE,
				),
				'fee'      => array(
					'type'  => 'none',
					'value' => 0,
				),
				'rounding' => array(
					'type'     => 'disabled',
					'value'    => 0,
					'subtract' => 0,
				),
				'format'   => array(
					'symbol'       => self::TARGET_SYMBOL,
					'decimals'     => 2,
					'decimal_sep'  => '.',
					'thousand_sep' => ',',
					'position'     => 'left',
				),
			)
		);
	}

	/**
	 * Return the plugin's shared ConversionContext to its start-of-request
	 * state, so that one PHPUnit test behaves like one HTTP request.
	 *
	 * ConversionContext deliberately carries a ONE-WAY latch: once it has
	 * answered "convert" after the `wp` action, it keeps answering "convert"
	 * for the rest of the request (design spec §3.3 — a latched "base" would
	 * show base prices on a page whose AJAX checkout then charges the
	 * converted amount). In production that latch dies with the request. Under
	 * PHPUnit there is only ever ONE request: Plugin::initialize_services()
	 * runs on `init`, which WordPress's test bootstrap fires exactly once for
	 * the whole run, so the very same context instance is shared by every test
	 * in every class. Without this reset, the first test that legitimately
	 * latches would silently force "convert" on every test that ran after it,
	 * and the tests asserting base-currency display would fail — or worse,
	 * pass for the wrong reason once reordered.
	 *
	 * Reflection is used on purpose. The context is private to Plugin and is
	 * handed to the filters by constructor injection; exposing a public getter
	 * or a static accessor purely for the tests would hand production code the
	 * global entry point the design specifically avoids. Nothing here is a
	 * production API — it is the test harness simulating a request boundary.
	 *
	 * @return void
	 */
	protected function reset_conversion_context(): void {
		$instance_property = new ReflectionProperty( Plugin::class, 'instance' );
		$instance_property->setAccessible( true );
		$plugin = $instance_property->getValue();

		if ( ! $plugin instanceof Plugin ) {
			return;
		}

		$context_property = new ReflectionProperty( Plugin::class, 'conversion_context' );
		$context_property->setAccessible( true );
		$context = $context_property->getValue( $plugin );

		if ( ! $context instanceof ConversionContext ) {
			return;
		}

		$context->force_convert( false );

		$latch_property = new ReflectionProperty( ConversionContext::class, 'latched' );
		$latch_property->setAccessible( true );
		$latch_property->setValue( $context, false );
	}

	/**
	 * Configure the plugin's currency list through its own admin REST API.
	 *
	 * `Plugin::initialize_services()` builds exactly one CurrencyStore
	 * instance on the `init` hook and hands that SAME instance to every
	 * filter class (PriceFilter, CartFilter, RestApiFilter, ...). That
	 * instance lazily caches its currency list on first read and never
	 * re-reads the `mhmcs_currencies` option after that -- see
	 * CurrencyStore::get_currencies(). WordPress's test bootstrap fires
	 * `init` exactly once for the whole PHPUnit run, so a later
	 * `update_option( 'mhmcs_currencies', ... )` from a test would be
	 * invisible to every already-booted filter.
	 *
	 * Going through `POST mhmcs/v1/currencies` -- the same route the
	 * plugin's own admin UI calls -- reaches `RestAPI::save_currencies()`,
	 * which calls `set_data()` on that identical shared instance, so every
	 * filter observes the change on its very next call. This is also, not
	 * incidentally, the same path a real site administrator's browser
	 * takes, which is why it is used here instead of a lower-level bypass.
	 *
	 * @param array<string, mixed> $currency Single currency configuration.
	 * @param string                $base    Base currency code.
	 * @return void
	 */
	protected function configure_currency( array $currency, string $base = 'USD' ): void {
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/mhmcs/v1/currencies' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'base_currency' => $base,
					'currencies'    => array( $currency ),
				)
			)
		);

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$this->fail( 'Failed to configure test currency via mhmcs/v1/currencies: ' . (string) wp_json_encode( $response->get_data() ) );
		}

		wp_set_current_user( 0 );
	}

	/**
	 * Simulate a visitor who has already chosen a currency. The plugin's
	 * detection order checks this cookie first (DetectionService::get_current_currency()).
	 *
	 * @param string $code ISO 4217 currency code.
	 * @return void
	 */
	protected function set_visitor_currency( string $code ): void {
		$_COOKIE['mhmcs_currency'] = $code;
	}

	/**
	 * Simulate a visitor with no stored currency preference (falls back to
	 * the base currency).
	 *
	 * @return void
	 */
	protected function clear_visitor_currency(): void {
		unset( $_COOKIE['mhmcs_currency'] );
	}

	/**
	 * Create and persist a simple product.
	 *
	 * @param float      $regular_price Regular price in the base currency.
	 * @param float|null $sale_price    Optional sale price in the base currency.
	 * @return WC_Product_Simple Saved, reloaded product.
	 */
	protected function create_simple_product( float $regular_price, ?float $sale_price = null ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'MHMCS Test Simple Product' );
		$product->set_regular_price( (string) $regular_price );

		if ( null !== $sale_price ) {
			$product->set_sale_price( (string) $sale_price );
		}

		$product->set_status( 'publish' );
		$product->save();

		$reloaded = wc_get_product( $product->get_id() );

		if ( ! $reloaded instanceof WC_Product_Simple ) {
			$this->fail( 'Failed to reload the freshly created simple product.' );
		}

		return $reloaded;
	}

	/**
	 * Create and persist a variable product with one variation per given
	 * regular price, using a local (non-taxonomy) "Size" attribute.
	 *
	 * @param array<int, float> $variation_regular_prices Regular price per variation.
	 * @return array{product: WC_Product_Variable, variations: array<int, WC_Product_Variation>}
	 */
	protected function create_variable_product( array $variation_regular_prices ): array {
		$prices  = array_values( $variation_regular_prices );
		$options = array();

		foreach ( array_keys( $prices ) as $index ) {
			$options[] = 'MhmcsOption' . $index;
		}

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Size' );
		$attribute->set_options( $options );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$product = new WC_Product_Variable();
		$product->set_name( 'MHMCS Test Variable Product' );
		$product->set_attributes( array( $attribute ) );
		$product->set_status( 'publish' );
		$product->save();

		$variations = array();

		foreach ( $prices as $index => $price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( 'size' => $options[ $index ] ) );
			$variation->set_regular_price( (string) $price );
			$variation->set_status( 'publish' );
			$variation->save();

			$variations[] = $variation;
		}

		WC_Product_Variable::sync( $product->get_id() );

		$reloaded = wc_get_product( $product->get_id() );

		if ( ! $reloaded instanceof WC_Product_Variable ) {
			$this->fail( 'Failed to reload the freshly created variable product.' );
		}

		return array(
			'product'    => $reloaded,
			'variations' => $variations,
		);
	}
}
