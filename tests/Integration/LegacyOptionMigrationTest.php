<?php
/**
 * Integration tests for the pre-0.3.0 option migration.
 *
 * These use a real options table because the load-bearing claims are about
 * writes that must NOT happen. `run()` refusing to overwrite a live
 * configuration cannot be proved with a stub that records calls: the proof is
 * that the row still holds the value it held before, after a full run.
 *
 * Deliberately extends WP_UnitTestCase rather than MhmcsIntegrationTestCase.
 * That base class seeds `mhmcs_currencies` in set_up as a fixture, and the
 * whole question here is what happens when that row is absent versus present.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\LegacyOptionMigrator;
use WP_UnitTestCase;

/**
 * Class LegacyOptionMigrationTest
 *
 * @covers \MhmCurrencySwitcher\Core\LegacyOptionMigrator
 */
class LegacyOptionMigrationTest extends WP_UnitTestCase {

	/**
	 * Start every test from a site that has never run the migration and
	 * holds none of the current option names.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( LegacyOptionMigrator::DONE_OPTION );
		delete_option( LegacyOptionMigrator::LEGACY_CURRENCIES );
		delete_option( LegacyOptionMigrator::LEGACY_SETTINGS );
		delete_option( CurrencyStore::OPTION_KEY );
		delete_option( 'mhmcs_settings' );

		update_option( 'woocommerce_currency', 'TRY' );
	}

	/**
	 * A shop that configured currencies before the rename keeps them, and
	 * the store reads them back through its ordinary loader.
	 *
	 * @return void
	 */
	public function test_a_configured_currency_row_survives_the_rename(): void {
		update_option(
			LegacyOptionMigrator::LEGACY_CURRENCIES,
			wp_json_encode(
				array(
					'base_currency' => 'TRY',
					'currencies'    => array(
						array(
							'code'    => 'EUR',
							'enabled' => true,
						),
					),
				)
			)
		);

		( new LegacyOptionMigrator() )->run();

		$store = new CurrencyStore();
		$store->load();

		$currencies = $store->get_currencies();

		$this->assertCount( 1, $currencies );
		$this->assertSame( 'EUR', $currencies[0]['code'] );
	}

	/**
	 * The pre-0.3.0 activation default is refused: a shop that never
	 * configured anything must not come out of the upgrade displaying four
	 * currencies it has never displayed before.
	 *
	 * @return void
	 */
	public function test_the_flat_activation_default_does_not_become_a_currency_list(): void {
		update_option(
			LegacyOptionMigrator::LEGACY_CURRENCIES,
			array( 'USD', 'EUR', 'GBP', 'TRY' )
		);

		( new LegacyOptionMigrator() )->run();

		$store = new CurrencyStore();
		$store->load();

		$this->assertSame( array(), $store->get_currencies() );
	}

	/**
	 * The seeded row still has to be a row the loader understands, with the
	 * store's own base currency in it — refusing the legacy value is not a
	 * licence to leave the option unreadable.
	 *
	 * @return void
	 */
	public function test_a_refused_payload_still_seeds_a_readable_row(): void {
		update_option( LegacyOptionMigrator::LEGACY_CURRENCIES, array( 'USD', 'EUR' ) );

		( new LegacyOptionMigrator() )->run();

		$raw = get_option( CurrencyStore::OPTION_KEY );

		$this->assertIsString( $raw );

		$decoded = json_decode( $raw, true );

		$this->assertIsArray( $decoded );
		$this->assertSame( 'TRY', $decoded['base_currency'] );
		$this->assertSame( array(), $decoded['currencies'] );
	}

	/**
	 * The regression this migration exists for: geolocation detection was
	 * on before the upgrade and has to still be on after it.
	 *
	 * @return void
	 */
	public function test_auto_detect_survives_the_upgrade(): void {
		update_option(
			LegacyOptionMigrator::LEGACY_SETTINGS,
			array(
				'provider'       => 'exchangerate',
				'cache_duration' => 3600,
				'auto_detect'    => true,
				'round_prices'   => true,
			)
		);

		( new LegacyOptionMigrator() )->run();

		$settings = get_option( 'mhmcs_settings' );

		$this->assertIsArray( $settings );
		$this->assertTrue( $settings['auto_detect'] );
		$this->assertArrayNotHasKey( 'provider', $settings );
		$this->assertArrayNotHasKey( 'cache_duration', $settings );
		$this->assertArrayNotHasKey( 'round_prices', $settings );
	}

	/**
	 * A live configuration is never overwritten. A site that activated on
	 * 0.3.0 or later holds the real settings under the current name; the
	 * legacy row sitting beside it is the stale one.
	 *
	 * @return void
	 */
	public function test_an_existing_configuration_is_never_overwritten(): void {
		$live = wp_json_encode(
			array(
				'base_currency' => 'TRY',
				'currencies'    => array( array( 'code' => 'JPY' ) ),
			)
		);

		update_option( CurrencyStore::OPTION_KEY, $live );
		update_option( 'mhmcs_settings', array( 'auto_detect' => false ) );

		update_option(
			LegacyOptionMigrator::LEGACY_CURRENCIES,
			wp_json_encode(
				array(
					'base_currency' => 'USD',
					'currencies'    => array( array( 'code' => 'EUR' ) ),
				)
			)
		);
		update_option( LegacyOptionMigrator::LEGACY_SETTINGS, array( 'auto_detect' => true ) );

		( new LegacyOptionMigrator() )->run();

		$this->assertSame( $live, get_option( CurrencyStore::OPTION_KEY ) );
		$this->assertSame( array( 'auto_detect' => false ), get_option( 'mhmcs_settings' ) );
	}

	/**
	 * The legacy rows are removed once carried, so nothing is left behind
	 * to be mistaken later for the authoritative copy.
	 *
	 * @return void
	 */
	public function test_the_legacy_rows_are_removed_after_a_run(): void {
		update_option( LegacyOptionMigrator::LEGACY_CURRENCIES, array( 'USD' ) );
		update_option( LegacyOptionMigrator::LEGACY_SETTINGS, array( 'auto_detect' => true ) );

		( new LegacyOptionMigrator() )->run();

		$this->assertFalse( get_option( LegacyOptionMigrator::LEGACY_CURRENCIES, false ) );
		$this->assertFalse( get_option( LegacyOptionMigrator::LEGACY_SETTINGS, false ) );
	}

	/**
	 * A completed migration never runs again, even if a legacy row comes
	 * back.
	 *
	 * 🔴 This is the only scenario that actually exercises the done-guard,
	 * and finding that out cost a mutation round. The obvious test — run
	 * twice, assert the second run writes nothing — passes with the guard
	 * deleted, because the first run removes the legacy rows and the second
	 * one then finds nothing to carry either way. Two protections were
	 * covering for each other and the suite read green over a guard that
	 * was not being tested at all.
	 *
	 * A legacy row reappearing after the migration is not hypothetical: a
	 * partial backup restore does exactly this, and without the guard the
	 * shop's current settings would be replaced by whatever that old row
	 * held.
	 *
	 * @return void
	 */
	public function test_a_completed_migration_never_runs_again(): void {
		update_option( LegacyOptionMigrator::DONE_OPTION, 'done' );
		update_option( LegacyOptionMigrator::LEGACY_SETTINGS, array( 'auto_detect' => true ) );

		( new LegacyOptionMigrator() )->run();

		$this->assertFalse(
			get_option( 'mhmcs_settings', false ),
			'A migration already recorded as done must not seed anything.'
		);
		$this->assertSame(
			array( 'auto_detect' => true ),
			get_option( LegacyOptionMigrator::LEGACY_SETTINGS ),
			'A run that should not have happened must not delete rows either.'
		);
	}

	/**
	 * Clearing the settings row after the migration does not bring it back.
	 *
	 * Locks the combination rather than the guard — see the test above for
	 * why that distinction matters.
	 *
	 * @return void
	 */
	public function test_a_second_run_does_nothing(): void {
		update_option( LegacyOptionMigrator::LEGACY_SETTINGS, array( 'auto_detect' => true ) );

		( new LegacyOptionMigrator() )->run();

		delete_option( 'mhmcs_settings' );

		( new LegacyOptionMigrator() )->run();

		$this->assertFalse( get_option( 'mhmcs_settings', false ) );
	}

	/**
	 * The pre-rename rate-update cron is unscheduled.
	 *
	 * Carrying `rate_update_interval` makes the bootstrap schedule the
	 * current hook, so without this the upgraded site would carry both: the
	 * live event and an orphan firing a hook nothing listens to, forever.
	 * Uninstall clears it, but upgrading is not uninstalling — the same gap
	 * the licence cleanup was written to close.
	 *
	 * @return void
	 */
	public function test_the_orphaned_legacy_rate_cron_is_cleared(): void {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mhm_cs_update_rates' );

		$this->assertNotFalse(
			wp_next_scheduled( 'mhm_cs_update_rates' ),
			'Fixture failed: the legacy event was not scheduled, so clearing it would prove nothing.'
		);

		update_option( LegacyOptionMigrator::LEGACY_SETTINGS, array( 'rate_update_interval' => 'daily' ) );

		( new LegacyOptionMigrator() )->run();

		$this->assertFalse( wp_next_scheduled( 'mhm_cs_update_rates' ) );
	}

	/**
	 * On a site with nothing to migrate the run is recorded anyway, so it
	 * stops looking on every request for the rest of the install's life.
	 *
	 * @return void
	 */
	public function test_a_site_with_no_legacy_rows_records_the_run_and_writes_nothing(): void {
		( new LegacyOptionMigrator() )->run();

		$this->assertSame( 'done', get_option( LegacyOptionMigrator::DONE_OPTION ) );
		$this->assertFalse( get_option( CurrencyStore::OPTION_KEY, false ) );
		$this->assertFalse( get_option( 'mhmcs_settings', false ) );
	}
}
