<?php
/**
 * Integration tests: an order placed through the Cart & Checkout Blocks
 * (Store API) must carry the same currency audit trail a classic-checkout
 * order does.
 *
 * `CartFilter` recorded `_mhmcs_currency_code`, `_mhmcs_exchange_rate` and
 * `_mhmcs_base_currency` from `woocommerce_checkout_create_order` alone. That
 * action is fired by the classic checkout and by nothing else: WooCommerce's
 * Store API creates its orders on a separate path that never reaches it. Since
 * WooCommerce 8.3 the block checkout is the default, so on a stock install the
 * audit trail was missing from the orders most shops actually take -- while
 * readme.txt told owners the rate was recorded and advised exporting orders to
 * convert them by it.
 *
 * The bug was invisible from inside the plugin: every unit test, every
 * integration test and the 1.1.2 browser pass exercised the classic path or
 * checked totals rather than meta, and all of them were right about what they
 * looked at.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use WC_Order;

/**
 * Class BlocksCheckoutOrderMetaTest
 */
class BlocksCheckoutOrderMetaTest extends MhmcsIntegrationTestCase {

	/**
	 * Base currency for this suite.
	 *
	 * @var string
	 */
	private const BASE = 'USD';

	/**
	 * Build a saved order and put the visitor in the configured non-base
	 * currency, which is the state a real block checkout runs in.
	 *
	 * @return WC_Order
	 */
	private function order_from_a_blocks_visitor(): WC_Order {
		// set_up() has already configured TARGET_CURRENCY at TARGET_RATE with
		// no fee and no rounding against a USD base, which is what makes the
		// rate assertions below plain multiplication.
		$this->set_visitor_currency( self::TARGET_CURRENCY );
		$this->reset_detection_service();
		$this->reset_conversion_context();

		$order = new WC_Order();
		$order->save();

		return $order;
	}

	/**
	 * Re-read the order from storage. Asserting against the in-memory object
	 * would pass even if nothing was ever persisted, which is the difference
	 * between "we called update_meta_data" and "the shop has the record".
	 *
	 * @param int $order_id Order ID.
	 * @return array<string, mixed>
	 */
	private function persisted_meta( int $order_id ): array {
		$fresh = wc_get_order( $order_id );

		return array(
			'code' => $fresh ? $fresh->get_meta( '_mhmcs_currency_code' ) : '',
			'rate' => $fresh ? $fresh->get_meta( '_mhmcs_exchange_rate' ) : '',
			'base' => $fresh ? $fresh->get_meta( '_mhmcs_base_currency' ) : '',
		);
	}

	/**
	 * The hook WooCommerce documents for attaching order meta on a Store API
	 * checkout. This is the block checkout's equivalent of the classic
	 * `woocommerce_checkout_create_order`.
	 *
	 * @return void
	 */
	public function test_store_api_meta_hook_records_the_currency_and_rate(): void {
		$order = $this->order_from_a_blocks_visitor();

		do_action( 'woocommerce_store_api_checkout_update_order_meta', $order );

		$meta = $this->persisted_meta( $order->get_id() );

		$this->assertSame(
			self::TARGET_CURRENCY,
			$meta['code'],
			'A block-checkout order carries no currency code, so the shop cannot tell what it was sold in.'
		);
		$this->assertEqualsWithDelta(
			self::TARGET_RATE,
			(float) $meta['rate'],
			0.0001,
			'A block-checkout order carries no exchange rate, so readme.txt\'s "convert them using the rate recorded on each one" is impossible.'
		);
		$this->assertSame( self::BASE, $meta['base'], 'A block-checkout order carries no base currency.' );
	}

	/**
	 * The second Store API entry point. `CheckoutOrder` -- the route that
	 * checks out an order that already exists -- fires only
	 * `woocommerce_store_api_checkout_order_processed`, never the
	 * `update_order_meta` action above. Hooking one of the two would leave
	 * that route behind: the same defect, one door along.
	 *
	 * @return void
	 */
	public function test_store_api_order_processed_hook_records_the_currency_and_rate(): void {
		$order = $this->order_from_a_blocks_visitor();

		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$meta = $this->persisted_meta( $order->get_id() );

		$this->assertSame( self::TARGET_CURRENCY, $meta['code'], 'The pay-for-existing-order Store API route records no currency code.' );
		$this->assertEqualsWithDelta( self::TARGET_RATE, (float) $meta['rate'], 0.0001, 'The pay-for-existing-order Store API route records no exchange rate.' );
		$this->assertSame( self::BASE, $meta['base'], 'The pay-for-existing-order Store API route records no base currency.' );
	}

	/**
	 * The reverse gate, and the only test here that could have caught the
	 * original defect.
	 *
	 * The two tests above fire hook names this plugin chose. If those names
	 * were wrong, both would still pass -- they would be asserting that our
	 * own wiring works, against a hook WooCommerce never fires. That is
	 * exactly the shape of the bug: `woocommerce_checkout_create_order` is a
	 * real, correct, well-known action, and the plugin called it faithfully.
	 * It simply is not the one the block checkout uses.
	 *
	 * So this test asks WooCommerce instead of asking ourselves: every Store
	 * API action we depend on must actually be fired by the installed
	 * WooCommerce's own source, and the classic action must be absent from it
	 * -- which is why hooking the classic action alone was never enough.
	 *
	 * @return void
	 */
	public function test_the_store_api_hooks_we_depend_on_are_really_fired_by_woocommerce(): void {
		$store_api_dir = WC_ABSPATH . 'src/StoreApi';

		$this->assertDirectoryExists(
			$store_api_dir,
			'WooCommerce has no src/StoreApi directory; this gate cannot see what it claims to check.'
		);

		$source = '';

		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $store_api_dir ) );

		foreach ( $files as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$source .= (string) file_get_contents( $file->getPathname() );
			}
		}

		$this->assertNotSame( '', $source, 'Read no PHP source from WooCommerce\'s Store API; the gate would pass on an empty string.' );

		foreach ( array( 'woocommerce_store_api_checkout_update_order_meta', 'woocommerce_store_api_checkout_order_processed' ) as $hook ) {
			$this->assertStringContainsString(
				"do_action( '" . $hook . "'",
				$source,
				sprintf( 'This plugin records block-checkout order meta on "%s", but the installed WooCommerce never fires it.', $hook )
			);

			$this->assertTrue(
				$this->plugin_listens_on( $hook ),
				sprintf( 'WooCommerce fires "%s" but this plugin is not listening on it.', $hook )
			);
		}

		$this->assertStringNotContainsString(
			"do_action( 'woocommerce_checkout_create_order'",
			$source,
			'WooCommerce\'s Store API now fires the classic order-creation action too. If that is genuinely the case, re-read this file before deleting the block-specific hooks — do not assume they became redundant.'
		);
	}

	/**
	 * Whether a CartFilter belonging to the booted plugin is listening on the
	 * given hook.
	 *
	 * Deliberately not written as `has_action( $hook, [ $instance, $method ] )`
	 * where `$instance` was itself found by reading that same hook: that phrases
	 * the question as "is the thing on this hook on this hook", which is true
	 * whatever the hook is called. The class is the fixed point here, not the
	 * hook.
	 *
	 * @param string $hook Action name.
	 * @return bool
	 */
	private function plugin_listens_on( string $hook ): bool {
		$registered = $GLOBALS['wp_filter'][ $hook ] ?? null;

		if ( ! $registered ) {
			return false;
		}

		foreach ( $registered->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;

				if ( is_array( $function )
					&& $function[0] instanceof \MhmCurrencySwitcher\Integration\WooCommerce\CartFilter
					&& 'save_order_meta_from_store_api' === $function[1]
				) {
					return true;
				}
			}
		}

		return false;
	}
}
