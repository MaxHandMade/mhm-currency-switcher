<?php
/**
 * One-time migration of the pre-0.3.0 option names.
 *
 * @package MhmCurrencySwitcher\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries `mhm_currency_switcher_*` data onto the `mhmcs_*` option names.
 *
 * The 0.3.0 prefix rename shipped without a migration, on the recorded
 * assumption that only one installation existed. The assumption was wrong,
 * and the resulting failure is silent in both directions:
 *
 * 1. The `mhmcs_*` rows are seeded by the activation hook alone, and that
 *    hook does not fire on an in-place upgrade. A site crossing the rename
 *    therefore ends up with no settings row at all. Most readers defend
 *    themselves against an absent row, but `auto_detect` does not: its
 *    fresh-install default is `true`, so a shop that had geolocation
 *    detection switched on finds it off, with nothing reported anywhere.
 * 2. `CurrencyStore::save()` is byte-identical before and after the rename.
 *    A shop that configured currencies is holding JSON in exactly the shape
 *    the current `load()` expects — the data was never incompatible, only
 *    the key was. Left alone, that shop loses its entire currency
 *    configuration on upgrade.
 *
 * 🔴 The pre-0.3.0 activation default is deliberately NOT carried. It wrote
 * a flat list of currency codes, a shape `load()` has never been able to
 * read — measured on a real 0.2.0 install, which reported an empty currency
 * set while that row sat in the table. It was dead on the day it was
 * written. Carrying it would not restore a lost setting; it would switch on
 * four currencies the shop has never displayed, on a live store. Shape, not
 * emptiness, is what separates the two cases.
 *
 * Settings are carried through an allow-list rather than a deny-list. The
 * keys that survived the rename are known and few; anything else in the old
 * row is either a control that was removed as dead (`provider`,
 * `cache_duration`, `round_prices`) or a key no reader has ever asked for.
 * A deny-list would leak every one of those the day it fell behind.
 *
 * @since 1.1.3
 */
final class LegacyOptionMigrator {

	/**
	 * Guard option: set once the migration has run to completion.
	 *
	 * @var string
	 */
	public const DONE_OPTION = 'mhmcs_legacy_option_migration';

	/**
	 * Pre-0.3.0 currency option name.
	 *
	 * @var string
	 */
	public const LEGACY_CURRENCIES = 'mhm_currency_switcher_currencies';

	/**
	 * Pre-0.3.0 settings option name.
	 *
	 * @var string
	 */
	public const LEGACY_SETTINGS = 'mhm_currency_switcher_settings';

	/**
	 * Pre-0.3.0 rate-update cron hook.
	 *
	 * @var string
	 */
	public const LEGACY_RATE_CRON = 'mhm_cs_update_rates';

	/**
	 * Setting keys the pre-rename schema could store that the current
	 * version still reads under the same name. Everything outside this
	 * list is left behind by design.
	 *
	 * @var array<int, string>
	 */
	public const CARRIED_SETTING_KEYS = array(
		'auto_detect',
		'rate_update_interval',
		'product_widget',
	);

	/**
	 * Rate-update intervals the scheduler accepts.
	 *
	 * @var array<int, string>
	 */
	private const RATE_INTERVALS = array( 'manual', 'hourly', 'twicedaily', 'daily' );

	/**
	 * The settings a site should hold when the row is seeded.
	 *
	 * Single definition, used by both the activation hook and this
	 * migration. Two hand-written default sets drifting apart is precisely
	 * how the currencies option came to hold a shape its own reader could
	 * not parse; there is no second copy to drift from.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings(): array {
		return array(
			'auto_detect'  => true,
			// Read by ConversionContext decision 4; written by the
			// Advanced Settings tab. Same spelling in all three.
			'cache_compat' => true,
			'switcher'     => array(
				'show_flag'   => true,
				'show_name'   => false,
				'show_symbol' => true,
				'show_code'   => true,
				'size'        => 'medium',
			),
		);
	}

	/**
	 * Decide whether a stored legacy currency payload may be carried over.
	 *
	 * Returns the payload in the `{base_currency, currencies}` shape when
	 * it is one the current reader understands, or `null` when it is not —
	 * including the flat activation default, which is refused on purpose.
	 * A `base_currency` that is missing or not a string comes back as an
	 * empty string, meaning "no opinion"; the caller substitutes the store
	 * setting rather than inventing one here.
	 *
	 * @param mixed $legacy Raw value read from the legacy option.
	 * @return array{base_currency: string, currencies: array<int, mixed>}|null
	 */
	public static function currencies_to_carry( $legacy ): ?array {
		if ( is_string( $legacy ) ) {
			if ( '' === $legacy ) {
				return null;
			}

			$decoded = json_decode( $legacy, true );
		} elseif ( is_array( $legacy ) ) {
			$decoded = $legacy;
		} else {
			return null;
		}

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		// The discriminator. A configured payload has a `currencies`
		// member; the flat activation default is a bare list of codes and
		// has no string keys at all.
		if ( ! array_key_exists( 'currencies', $decoded ) || ! is_array( $decoded['currencies'] ) ) {
			return null;
		}

		$base = $decoded['base_currency'] ?? null;

		return array(
			'base_currency' => is_string( $base ) ? $base : '',
			'currencies'    => array_values( $decoded['currencies'] ),
		);
	}

	/**
	 * Merge the carryable half of a legacy settings row onto the defaults.
	 *
	 * Values are re-validated rather than trusted: a migration that writes
	 * whatever the old table held is how a bad row becomes a new bug, and
	 * one of these values reaches the cron scheduler.
	 *
	 * @param mixed                $legacy   Raw value read from the legacy option.
	 * @param array<string, mixed> $defaults Fresh-install defaults.
	 * @return array<string, mixed>
	 */
	public static function settings_to_carry( $legacy, array $defaults ): array {
		$carried = $defaults;

		if ( ! is_array( $legacy ) ) {
			return $carried;
		}

		if ( array_key_exists( 'auto_detect', $legacy ) ) {
			$carried['auto_detect'] = (bool) $legacy['auto_detect'];
		}

		$interval = $legacy['rate_update_interval'] ?? null;

		if ( is_string( $interval ) && in_array( $interval, self::RATE_INTERVALS, true ) ) {
			$carried['rate_update_interval'] = $interval;
		}

		if ( isset( $legacy['product_widget'] ) && is_array( $legacy['product_widget'] ) ) {
			$carried['product_widget'] = self::carry_product_widget( $legacy['product_widget'] );
		}

		return $carried;
	}

	/**
	 * Re-validate the product-widget sub-array.
	 *
	 * @param array<string, mixed> $widget Stored widget settings.
	 * @return array<string, mixed>
	 */
	private static function carry_product_widget( array $widget ): array {
		$carried = array();

		if ( array_key_exists( 'enabled', $widget ) ) {
			$carried['enabled'] = (bool) $widget['enabled'];
		}

		if ( array_key_exists( 'show_flags', $widget ) ) {
			$carried['show_flags'] = (bool) $widget['show_flags'];
		}

		if ( isset( $widget['currencies'] ) && is_array( $widget['currencies'] ) ) {
			$carried['currencies'] = array_values(
				array_filter(
					$widget['currencies'],
					static function ( $code ): bool {
						return is_string( $code ) && 1 === preg_match( '/^[A-Z]{3}$/', $code );
					}
				)
			);
		}

		return $carried;
	}

	/**
	 * Run the migration once.
	 *
	 * Neither `mhmcs_*` row is overwritten when it already exists: a site
	 * that activated on 0.3.0 or later holds the live configuration under
	 * the current name, and a legacy row sitting beside it is the stale one.
	 *
	 * @return void
	 */
	public function run(): void {
		if ( 'done' === get_option( self::DONE_OPTION ) ) {
			return;
		}

		$legacy_currencies = get_option( self::LEGACY_CURRENCIES, null );
		$legacy_settings   = get_option( self::LEGACY_SETTINGS, null );

		if ( null === $legacy_currencies && null === $legacy_settings ) {
			// Nothing to migrate. Record the run anyway, so this stops
			// asking on every request for the whole installed base.
			update_option( self::DONE_OPTION, 'done', true );

			return;
		}

		if ( false === get_option( CurrencyStore::OPTION_KEY, false ) ) {
			$carried = self::currencies_to_carry( $legacy_currencies );

			if ( null === $carried ) {
				$carried = CurrencyStore::default_option_value();
			}

			if ( ! is_string( $carried['base_currency'] ) || '' === $carried['base_currency'] ) {
				$carried['base_currency'] = get_option( 'woocommerce_currency', 'USD' );
			}

			/*
			 * Written as the JSON string CurrencyStore::save() produces, so a
			 * migrated row is indistinguishable from a saved one.
			 *
			 * 🔴 And CHECKED, because of what the rest of this method does
			 * next: it deletes the legacy rows and stamps the migration done.
			 * Unchecked, an upgrade whose write failed deleted the only copy of
			 * the shop's currency configuration and then recorded that there
			 * was nothing left to migrate — silently, permanently, on a code
			 * path that runs once and never looks again.
			 *
			 * Returning here leaves everything exactly as it was found, so the
			 * next request tries again. Of the whole "nobody read the write"
			 * class this release swept, the other members report a wrong
			 * success; this one destroyed what it was moving.
			 */
			if ( ! OptionWriter::write( CurrencyStore::OPTION_KEY, wp_json_encode( $carried ) ) ) {
				return;
			}
		}

		if ( false === get_option( 'mhmcs_settings', false ) ) {
			update_option(
				'mhmcs_settings',
				self::settings_to_carry( $legacy_settings, self::default_settings() )
			);
		}

		/*
		 * The pre-rename rate-update event. Carrying `rate_update_interval`
		 * makes the bootstrap schedule the current hook, so leaving this one
		 * behind would give the upgraded site both: the live event and an
		 * orphan firing a hook nothing listens to, for the life of the
		 * install. Uninstall already clears it, but upgrading is not
		 * uninstalling — the same gap the licence cleanup exists to close.
		 */
		wp_clear_scheduled_hook( self::LEGACY_RATE_CRON );

		delete_option( self::LEGACY_CURRENCIES );
		delete_option( self::LEGACY_SETTINGS );

		update_option( self::DONE_OPTION, 'done', true );
	}
}
