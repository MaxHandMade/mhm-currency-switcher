<?php
/**
 * Integration tests: deleting the plugin must honour the shop owner's choice.
 *
 * Until this release, deleting the plugin deleted the currency code and the
 * applied exchange rate recorded on EVERY past order — classic meta and HPOS
 * alike. That is the only basis a shop has for multi-currency sales history,
 * and nothing warned anyone it was about to go.
 *
 * 🔴 Both directions are asserted, and that is not symmetry for its own sake.
 * A test that only proves the ON branch deletes passes on an uninstall that
 * deletes unconditionally; a test that only proves the OFF branch keeps passes
 * on an uninstall that deletes nothing at all. Either one alone certifies the
 * bug it was written to prevent.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use MhmCurrencySwitcher\Core\RateProvider;

/**
 * Class UninstallDataSwitchTest
 */
class UninstallDataSwitchTest extends MhmcsIntegrationTestCase {

	/**
	 * Seed one order carrying the plugin's meta, plus the options.
	 *
	 * @param bool $purge Value for the delete_all_data setting.
	 * @return int Order (post) ID.
	 */
	private function seed( bool $purge ): int {
		update_option(
			'mhmcs_settings',
			array(
				'cache_compat'     => true,
				'delete_all_data'  => $purge,
				// A secret from a control that no longer exists. It is still in
				// this row on any site that has not saved settings since the
				// control was removed, because the purge runs only on save.
				// Deliberately not shaped like a real provider key: a fixture
				// that looks like a credential trains both the reader and every
				// secret scanner to expect one here.
				'provider_api_key' => 'fixture-value-must-not-survive',
			)
		);

		update_option( 'mhmcs_currencies', wp_json_encode( array( 'base_currency' => 'USD', 'currencies' => array() ) ) );
		update_option( RateProvider::LAST_SYNC_OPTION, array( 'time' => 1700000000, 'base' => 'USD' ) );
		update_option( 'mhm_currency_switcher_license', 'LICENCE-KEY-MUST-NOT-SURVIVE' );

		$order_id = $this->factory->post->create( array( 'post_type' => 'shop_order' ) );

		update_post_meta( $order_id, '_mhmcs_currency_code', 'EUR' );
		update_post_meta( $order_id, '_mhmcs_exchange_rate', '0.858' );
		update_post_meta( $order_id, '_mhmcs_base_currency', 'USD' );

		return (int) $order_id;
	}

	/**
	 * Run the real uninstall file.
	 *
	 * `include`, not `include_once`: both tests in this class must be able to
	 * run it in the same process.
	 *
	 * @return void
	 */
	private function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'mhm-currency-switcher/mhm-currency-switcher.php' );
		}

		include dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	/**
	 * Switch OFF — the shop's sales history survives.
	 *
	 * @return void
	 */
	public function test_order_currency_history_survives_when_the_switch_is_off(): void {
		$order_id = $this->seed( false );

		$this->run_uninstall();

		$this->assertSame(
			'EUR',
			get_post_meta( $order_id, '_mhmcs_currency_code', true ),
			'Deleting the plugin with the switch OFF threw away the currency recorded on a past order.'
		);
		$this->assertSame(
			'0.858',
			get_post_meta( $order_id, '_mhmcs_exchange_rate', true ),
			'The applied exchange rate is half of the record; keeping only the code makes the order unreadable.'
		);
		$this->assertNotFalse(
			get_option( 'mhmcs_currencies', false ),
			'The switch is about data, and the currency configuration is data the shop owner still wants.'
		);
		$this->assertNotFalse(
			get_option( RateProvider::LAST_SYNC_OPTION, false ),
			'The sync timestamp describes the rates that were kept; dropping it leaves a reinstalled '
				. 'panel unable to say how old its own numbers are.'
		);
	}

	/**
	 * Switch OFF — but a credential never survives.
	 *
	 * @return void
	 */
	public function test_secrets_do_not_survive_the_keep_branch(): void {
		$this->seed( false );

		$this->run_uninstall();

		$settings = get_option( 'mhmcs_settings', array() );

		$this->assertIsArray( $settings, 'The keep branch must leave a usable settings row behind.' );
		$this->assertArrayNotHasKey(
			'provider_api_key',
			$settings,
			'A user-supplied secret outlived plugin deletion in the DEFAULT branch. The purge loop for '
				. 'these keys runs only inside save_settings(), so an install that has not saved since '
				. 'the control was removed still carries the key.'
		);
		$this->assertArrayHasKey(
			'cache_compat',
			$settings,
			'The strip removed more than the legacy keys — real settings went with it.'
		);
		$this->assertFalse(
			get_option( 'mhm_currency_switcher_license', false ),
			'The pre-1.0.0 licence option held a customer licence key. A credential must not survive '
				. 'an uninstall, whatever the switch says.'
		);
	}

	/**
	 * Switch ON — everything the plugin created is gone.
	 *
	 * @return void
	 */
	public function test_everything_goes_when_the_switch_is_on(): void {
		$order_id = $this->seed( true );

		$this->run_uninstall();

		$this->assertSame(
			'',
			get_post_meta( $order_id, '_mhmcs_currency_code', true ),
			'The shop owner asked for all data to be deleted and the order meta stayed.'
		);
		$this->assertFalse( get_option( 'mhmcs_settings', false ), 'mhmcs_settings survived a full purge.' );
		$this->assertFalse( get_option( 'mhmcs_currencies', false ), 'mhmcs_currencies survived a full purge.' );
		$this->assertFalse(
			get_option( RateProvider::LAST_SYNC_OPTION, false ),
			'mhmcs_rates_last_sync survived a full purge — an uninstall that leaves rows behind is an '
				. 'uninstall that did not finish.'
		);
	}
}
