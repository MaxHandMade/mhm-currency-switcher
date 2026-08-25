<?php
/**
 * The suite runs in the order-storage mode the environment asked for.
 *
 * 🔴 THIS TEST IS THE REASON THE OTHER ORDER TESTS MEAN ANYTHING IN TWO MODES.
 *
 * The plugin declares HPOS compatibility, and it writes the currency and the
 * applied exchange rate onto every order — the only record of what a
 * multi-currency sale was actually charged in. Until now the whole integration
 * suite ran in whichever storage mode the environment happened to default to,
 * which is the classic post table. So "HPOS compatible" was a declaration
 * measured by nothing.
 *
 * Running the suite a second time with HPOS switched on fixes that only if
 * something asserts the switch TOOK. Without this test a misspelt option name,
 * a WooCommerce version that renamed the feature flag, or an ordering mistake
 * that sets the flag after `WC_Install::install()` would all produce a second
 * green run over the same classic tables — two identical passes reported as
 * coverage of two modes. That is the failure this file exists to make loud.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

/**
 * Class OrderStorageModeTest
 */
class OrderStorageModeTest extends MhmcsIntegrationTestCase {

	/**
	 * Whether this run was asked for HPOS.
	 *
	 * Mirrors the reading in tests/bootstrap-integration.php deliberately: if
	 * the two ever disagree, this test fails rather than silently measuring a
	 * different question from the one the bootstrap answered.
	 *
	 * @return bool
	 */
	private function hpos_requested(): bool {
		$raw = getenv( 'MHMCS_HPOS' );

		return in_array( strtolower( (string) $raw ), array( '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * WooCommerce agrees with the environment about where orders live.
	 *
	 * @return void
	 */
	public function test_the_active_storage_mode_is_the_one_the_environment_asked_for(): void {
		$this->assertTrue(
			class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ),
			'Guard: WooCommerce is loaded and exposes the utility this test reads. Without it the '
				. 'assertion below could not tell the two modes apart at all.'
		);

		$actual = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		$this->assertSame(
			$this->hpos_requested(),
			$actual,
			$this->hpos_requested()
				? 'MHMCS_HPOS asked for the custom order tables and WooCommerce is still using the '
					. 'classic post table. This run measures nothing the default run did not already '
					. 'measure — do not read it as HPOS coverage.'
				: 'WooCommerce is using the custom order tables on a run that did not ask for them. '
					. 'The classic path is then untested, which is the mode most existing shops are on.'
		);
	}

	/**
	 * The tables that mode needs actually exist.
	 *
	 * Separated from the round-trip below on purpose. When the HPOS tables are
	 * missing, WooCommerce still routes writes to the custom data store and the
	 * failure surfaces as a wall of "table doesn't exist" database errors from
	 * inside `OrdersTableDataStore` — measured, that is exactly what happened
	 * the first time this switch was wired. Asking the question directly turns
	 * that into one sentence.
	 *
	 * @return void
	 */
	public function test_the_storage_this_mode_needs_exists(): void {
		global $wpdb;

		if ( ! $this->hpos_requested() ) {
			$this->assertNotEmpty(
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->posts ) ),
				'Classic order storage needs the posts table.'
			);

			return;
		}

		foreach ( array( $wpdb->prefix . 'wc_orders', $wpdb->prefix . 'wc_orders_meta' ) as $table ) {
			$this->assertNotEmpty(
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ),
				"HPOS was requested but {$table} does not exist. WC_Install::install() does not create "
					. 'the order tables; the DataSynchronizer does, and the bootstrap must ask it to.'
			);
		}
	}

	/**
	 * An order round-trips its currency meta in whichever mode is active.
	 *
	 * Deliberately does NOT branch on the mode: the point is that the same
	 * assertions run against both storage backends. What differs between the
	 * two runs is where WooCommerce puts the row, and that is exactly what
	 * should be invisible to this plugin.
	 *
	 * @return void
	 */
	public function test_order_currency_meta_survives_a_round_trip_in_this_mode(): void {
		$order = wc_create_order();
		$order->update_meta_data( '_mhmcs_currency_code', 'EUR' );
		$order->update_meta_data( '_mhmcs_exchange_rate', '0.92' );
		$order->save();

		$id = $order->get_id();
		$this->assertGreaterThan( 0, $id, 'Guard: the order was actually stored.' );

		// Read through a FRESH object: an assertion against the instance we
		// just wrote to would pass on in-memory data even if nothing persisted.
		wp_cache_flush();
		$fresh = wc_get_order( $id );

		$this->assertNotFalse( $fresh, 'The order is readable back in this storage mode.' );
		$this->assertSame( 'EUR', $fresh->get_meta( '_mhmcs_currency_code' ) );
		$this->assertSame( '0.92', $fresh->get_meta( '_mhmcs_exchange_rate' ) );
	}
}
