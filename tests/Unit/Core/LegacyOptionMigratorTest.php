<?php
/**
 * Unit tests for the pre-rename option migration.
 *
 * The prefix rename in 0.3.0 (`mhm_currency_switcher_*` -> `mhmcs_*`) shipped
 * without a migration on the assumption that only one installation existed.
 * That assumption was wrong, and the failure it produces is silent: the new
 * option names are seeded by the activation hook alone, and the activation
 * hook does not fire on an in-place upgrade. A site crossing the rename ends
 * up with no `mhmcs_settings` row at all, so a shop that had switched
 * geolocation detection on finds it off with nothing reported.
 *
 * 🔴 The two legacy payloads are NOT equivalent and the tests say so
 * separately. `CurrencyStore::save()` is byte-identical before and after the
 * rename, so a shop that configured currencies holds JSON in exactly the shape
 * the current `load()` expects — that is a pure key rename. The activation
 * default, however, was a flat list of codes, a shape `load()` never could
 * read: it was dead on the day it was written. Carrying it over would not
 * restore a lost setting, it would switch on four currencies the shop has
 * never displayed. Refusing it is the whole point of `currencies_to_carry()`,
 * so that refusal gets its own test.
 *
 * The decisions are pure functions taking the stored value as an argument;
 * nothing here touches the options table. The WordPress-facing half (which row
 * is read, which is written, which is deleted) is covered by the integration
 * suite, because only a real options table can prove a write did not happen.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use MhmCurrencySwitcher\Core\LegacyOptionMigrator;
use PHPUnit\Framework\TestCase;

/**
 * Class LegacyOptionMigratorTest
 *
 * @covers \MhmCurrencySwitcher\Core\LegacyOptionMigrator
 */
class LegacyOptionMigratorTest extends TestCase {

	// ─── currencies_to_carry() ───────────────────────────────────────

	/**
	 * A shop that configured currencies stored the same JSON the current
	 * version reads, so the payload survives the rename untouched.
	 *
	 * @return void
	 */
	public function test_carries_a_configured_currency_payload(): void {
		$legacy = wp_json_encode(
			array(
				'base_currency' => 'TRY',
				'currencies'    => array(
					array(
						'code' => 'EUR',
						'rate' => 0.031,
					),
				),
			)
		);

		$carried = LegacyOptionMigrator::currencies_to_carry( $legacy );

		$this->assertIsArray( $carried );
		$this->assertSame( 'TRY', $carried['base_currency'] );
		$this->assertCount( 1, $carried['currencies'] );
		$this->assertSame( 'EUR', $carried['currencies'][0]['code'] );
	}

	/**
	 * The pre-0.3.0 activation hook wrote a flat list of currency codes,
	 * which `CurrencyStore::load()` has never been able to read. Carrying
	 * it would switch on currencies the shop never displayed.
	 *
	 * @return void
	 */
	public function test_refuses_the_flat_activation_default(): void {
		$legacy = array( 'USD', 'EUR', 'GBP', 'TRY' );

		$this->assertNull( LegacyOptionMigrator::currencies_to_carry( $legacy ) );
	}

	/**
	 * The same flat list survives a serialise/JSON round trip in some
	 * installs; the shape is what disqualifies it, not the storage type.
	 *
	 * @return void
	 */
	public function test_refuses_the_flat_activation_default_as_json(): void {
		$legacy = wp_json_encode( array( 'USD', 'EUR', 'GBP', 'TRY' ) );

		$this->assertNull( LegacyOptionMigrator::currencies_to_carry( $legacy ) );
	}

	/**
	 * An already-decoded array in the readable shape is carried too — the
	 * option column can hold either after years of hand edits and imports.
	 *
	 * @return void
	 */
	public function test_carries_an_array_payload_in_the_readable_shape(): void {
		$legacy = array(
			'base_currency' => 'USD',
			'currencies'    => array(),
		);

		$carried = LegacyOptionMigrator::currencies_to_carry( $legacy );

		$this->assertIsArray( $carried );
		$this->assertSame( 'USD', $carried['base_currency'] );
		$this->assertSame( array(), $carried['currencies'] );
	}

	/**
	 * Anything that is not a readable payload is refused rather than
	 * written through — a migration that trusts the old table is how a bad
	 * row becomes a new bug.
	 *
	 * @return void
	 */
	public function test_refuses_unreadable_values(): void {
		$this->assertNull( LegacyOptionMigrator::currencies_to_carry( 'not json at all' ) );
		$this->assertNull( LegacyOptionMigrator::currencies_to_carry( '' ) );
		$this->assertNull( LegacyOptionMigrator::currencies_to_carry( false ) );
		$this->assertNull( LegacyOptionMigrator::currencies_to_carry( 42 ) );
	}

	/**
	 * A payload whose `currencies` member is not a list is refused: the
	 * key alone is not proof the value is usable.
	 *
	 * @return void
	 */
	public function test_refuses_a_payload_whose_currencies_member_is_not_a_list(): void {
		$legacy = array(
			'base_currency' => 'USD',
			'currencies'    => 'EUR,GBP',
		);

		$this->assertNull( LegacyOptionMigrator::currencies_to_carry( $legacy ) );
	}

	// ─── settings_to_carry() ─────────────────────────────────────────

	/**
	 * `auto_detect` is the setting the upgrade actually loses: it is read
	 * by both schemas under the same name, and the fresh-install default is
	 * `true`, so an absent row silently flips a shop that had it on.
	 *
	 * @return void
	 */
	public function test_carries_auto_detect(): void {
		$carried = LegacyOptionMigrator::settings_to_carry(
			array( 'auto_detect' => true ),
			array( 'auto_detect' => false )
		);

		$this->assertTrue( $carried['auto_detect'] );
	}

	/**
	 * A shop that switched detection OFF must stay off. Merging defaults
	 * the wrong way round would turn it back on, which is the same class of
	 * silent change the migration exists to prevent.
	 *
	 * @return void
	 */
	public function test_carries_auto_detect_when_the_shop_switched_it_off(): void {
		$carried = LegacyOptionMigrator::settings_to_carry(
			array( 'auto_detect' => false ),
			array( 'auto_detect' => true )
		);

		$this->assertFalse( $carried['auto_detect'] );
	}

	/**
	 * The rate-update interval drives cron scheduling under the new hook
	 * name, so losing it stops automatic rate updates.
	 *
	 * @return void
	 */
	public function test_carries_the_rate_update_interval(): void {
		$carried = LegacyOptionMigrator::settings_to_carry(
			array( 'rate_update_interval' => 'twicedaily' ),
			array( 'rate_update_interval' => 'manual' )
		);

		$this->assertSame( 'twicedaily', $carried['rate_update_interval'] );
	}

	/**
	 * An interval outside the allowed set falls back to the default rather
	 * than being written through to a cron scheduler.
	 *
	 * @return void
	 */
	public function test_refuses_an_unknown_rate_update_interval(): void {
		$carried = LegacyOptionMigrator::settings_to_carry(
			array( 'rate_update_interval' => 'every_minute' ),
			array( 'rate_update_interval' => 'manual' )
		);

		$this->assertSame( 'manual', $carried['rate_update_interval'] );
	}

	/**
	 * The product widget's own settings are read by the current version
	 * under the same names, so they carry.
	 *
	 * @return void
	 */
	public function test_carries_the_product_widget_settings(): void {
		$carried = LegacyOptionMigrator::settings_to_carry(
			array(
				'product_widget' => array(
					'enabled'    => true,
					'show_flags' => false,
					'currencies' => array( 'EUR', 'GBP' ),
				),
			),
			array()
		);

		$this->assertTrue( $carried['product_widget']['enabled'] );
		$this->assertFalse( $carried['product_widget']['show_flags'] );
		$this->assertSame( array( 'EUR', 'GBP' ), $carried['product_widget']['currencies'] );
	}

	/**
	 * Currency codes inside the widget setting are re-validated rather than
	 * trusted, and the surviving list is re-indexed so it stays a list.
	 *
	 * @return void
	 */
	public function test_revalidates_product_widget_currency_codes(): void {
		$carried = LegacyOptionMigrator::settings_to_carry(
			array(
				'product_widget' => array(
					'currencies' => array( 'EUR', 'not-a-code', 'gbp', 'USD' ),
				),
			),
			array()
		);

		$this->assertSame( array( 'EUR', 'USD' ), $carried['product_widget']['currencies'] );
	}

	/**
	 * `provider`, `cache_duration` and `round_prices` are listed in
	 * `RestAPI::LEGACY_SETTING_KEYS` — controls that turned out to be dead
	 * and are deliberately purged. The migration must not reintroduce them.
	 *
	 * @return void
	 */
	public function test_never_carries_the_dead_setting_keys(): void {
		$carried = LegacyOptionMigrator::settings_to_carry(
			array(
				'provider'         => 'exchangerate',
				'cache_duration'   => 3600,
				'round_prices'     => true,
				'provider_api_key' => 'secret',
			),
			array()
		);

		$this->assertArrayNotHasKey( 'provider', $carried );
		$this->assertArrayNotHasKey( 'cache_duration', $carried );
		$this->assertArrayNotHasKey( 'round_prices', $carried );
		$this->assertArrayNotHasKey( 'provider_api_key', $carried );
	}

	/**
	 * A legacy row that is missing, corrupt or not an array leaves the
	 * fresh-install defaults exactly as they are.
	 *
	 * @return void
	 */
	public function test_falls_back_to_defaults_when_the_legacy_row_is_unusable(): void {
		$defaults = array(
			'auto_detect'  => true,
			'cache_compat' => true,
		);

		$this->assertSame( $defaults, LegacyOptionMigrator::settings_to_carry( false, $defaults ) );
		$this->assertSame( $defaults, LegacyOptionMigrator::settings_to_carry( 'garbage', $defaults ) );
		$this->assertSame( $defaults, LegacyOptionMigrator::settings_to_carry( array(), $defaults ) );
	}

	/**
	 * Keys the legacy schema never had — `cache_compat` above all, which
	 * governs the flagship cache-friendly mode — keep their fresh-install
	 * values instead of being dropped by the merge.
	 *
	 * @return void
	 */
	public function test_keeps_defaults_the_legacy_schema_never_had(): void {
		$carried = LegacyOptionMigrator::settings_to_carry(
			array( 'auto_detect' => false ),
			array(
				'auto_detect'  => true,
				'cache_compat' => true,
				'switcher'     => array( 'size' => 'medium' ),
			)
		);

		$this->assertTrue( $carried['cache_compat'] );
		$this->assertSame( array( 'size' => 'medium' ), $carried['switcher'] );
	}

	/**
	 * The declared allow-list is the contract, not a comment.
	 *
	 * `settings_to_carry()` validates each key in its own branch, so the
	 * constant beside it could drift out of agreement with the code and
	 * still read as authoritative. This pins the two together: carry a key
	 * without declaring it and the assertion names it.
	 *
	 * @return void
	 */
	public function test_carries_no_setting_key_outside_the_declared_allow_list(): void {
		$carried = LegacyOptionMigrator::settings_to_carry(
			array(
				'auto_detect'          => true,
				'rate_update_interval' => 'daily',
				'product_widget'       => array( 'enabled' => true ),
				'provider'             => 'exchangerate',
				'round_prices'         => true,
				'some_future_key'      => 'value',
			),
			array()
		);

		$undeclared = array_values(
			array_diff( array_keys( $carried ), LegacyOptionMigrator::CARRIED_SETTING_KEYS )
		);

		$this->assertSame(
			array(),
			$undeclared,
			'Carried a setting key that CARRIED_SETTING_KEYS does not declare: ' . implode( ', ', $undeclared )
		);
	}

	// ─── default_settings() ──────────────────────────────────────────

	/**
	 * The activation hook and the upgrade path must seed the same thing.
	 * Two hand-written default sets drifting apart is exactly how the
	 * currencies option came to hold a shape its own reader could not
	 * parse, so there is one definition and both callers use it.
	 *
	 * @return void
	 */
	public function test_default_settings_switch_on_the_documented_defaults(): void {
		$defaults = LegacyOptionMigrator::default_settings();

		$this->assertTrue( $defaults['auto_detect'] );
		$this->assertTrue( $defaults['cache_compat'] );
		$this->assertIsArray( $defaults['switcher'] );
		$this->assertSame( 'medium', $defaults['switcher']['size'] );
	}

	/**
	 * 🔴 A migration that could not write must not throw away what it was
	 * migrating.
	 *
	 * `run()` writes the carried currencies, then deletes the legacy rows, then
	 * stamps itself done — and it did all three regardless of whether the write
	 * landed. On an upgrade where that write failed, the shop's entire currency
	 * configuration was deleted, the migration marked itself complete so it
	 * would never try again, and nothing said a word.
	 *
	 * This is the same class 1.3.1 closed in the REST endpoints and the sync
	 * paths — a persistence result nobody read — but with the worst
	 * consequence of the three: the others report a wrong success, this one
	 * destroys the data it was moving.
	 *
	 * The safe direction is to leave everything alone: the legacy rows stay,
	 * `done` stays unset, and the next request tries again.
	 *
	 * @return void
	 */
	public function test_a_failed_carry_leaves_the_legacy_data_alone(): void {
		$previous       = $GLOBALS['__mhmcs_test_options'] ?? null;
		$previous_fails = $GLOBALS['__mhmcs_test_option_write_fails'] ?? null;

		$GLOBALS['__mhmcs_test_options'] = array(
			'mhm_currency_switcher_currencies' => wp_json_encode(
				array(
					'base_currency' => 'USD',
					'currencies'    => array(
						array(
							'code'    => 'EUR',
							'enabled' => true,
							'rate'    => array(
								'type'  => 'manual',
								'value' => 0.92,
							),
						),
					),
				)
			),
		);

		$GLOBALS['__mhmcs_test_option_write_fails'] = array( \MhmCurrencySwitcher\Core\CurrencyStore::OPTION_KEY );

		try {
			$this->assertArrayHasKey(
				'mhm_currency_switcher_currencies',
				$GLOBALS['__mhmcs_test_options'],
				'Guard: the legacy row is there to lose.'
			);

			( new LegacyOptionMigrator() )->run();

			$this->assertArrayHasKey(
				'mhm_currency_switcher_currencies',
				$GLOBALS['__mhmcs_test_options'],
				'The carry failed, so the only copy of the configuration must still exist.'
			);

			$this->assertArrayNotHasKey(
				LegacyOptionMigrator::DONE_OPTION,
				$GLOBALS['__mhmcs_test_options'],
				'A migration that did not migrate anything must not mark itself done — the next request has to try again.'
			);
		} finally {
			if ( null === $previous_fails ) {
				unset( $GLOBALS['__mhmcs_test_option_write_fails'] );
			} else {
				$GLOBALS['__mhmcs_test_option_write_fails'] = $previous_fails;
			}

			if ( null === $previous ) {
				unset( $GLOBALS['__mhmcs_test_options'] );
			} else {
				$GLOBALS['__mhmcs_test_options'] = $previous;
			}
		}
	}

	/**
	 * 🔴 The settings half of the same migration, and the member that survived
	 * the first sweep.
	 *
	 * `run()` carries two things: the currency list and the settings. The
	 * currency carry was checked; the settings carry four lines below it was
	 * not — under a comment explaining why checking was necessary. Then both
	 * legacy rows are deleted and the migration stamps itself done, so a shop
	 * whose settings write failed loses geolocation and its rate-update
	 * interval permanently and silently, exactly the failure the currency
	 * branch was fixed to prevent.
	 *
	 * @return void
	 */
	public function test_a_failed_settings_carry_leaves_the_legacy_data_alone(): void {
		$previous       = $GLOBALS['__mhmcs_test_options'] ?? null;
		$previous_fails = $GLOBALS['__mhmcs_test_option_write_fails'] ?? null;

		$GLOBALS['__mhmcs_test_options'] = array(
			'mhm_currency_switcher_settings' => array(
				'auto_detect'          => true,
				'rate_update_interval' => 'hourly',
			),
		);

		$GLOBALS['__mhmcs_test_option_write_fails'] = array( 'mhmcs_settings' );

		try {
			( new LegacyOptionMigrator() )->run();

			$this->assertArrayHasKey(
				'mhm_currency_switcher_settings',
				$GLOBALS['__mhmcs_test_options'],
				'The settings carry failed, so the only copy of those settings must still exist.'
			);

			$this->assertArrayNotHasKey(
				LegacyOptionMigrator::DONE_OPTION,
				$GLOBALS['__mhmcs_test_options'],
				'A migration that lost half its payload must not record itself as finished.'
			);
		} finally {
			if ( null === $previous_fails ) {
				unset( $GLOBALS['__mhmcs_test_option_write_fails'] );
			} else {
				$GLOBALS['__mhmcs_test_option_write_fails'] = $previous_fails;
			}

			if ( null === $previous ) {
				unset( $GLOBALS['__mhmcs_test_options'] );
			} else {
				$GLOBALS['__mhmcs_test_options'] = $previous;
			}
		}
	}

}
