<?php
/**
 * Class-level locks for "a write whose result nobody read".
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;

/**
 * Class PersistenceResultTest
 *
 * 1.3.1 fixed two call sites that discarded a persistence result and reported
 * success anyway. An independent pre-ZIP audit then found three more members of
 * the same class still standing — including the busiest one, the hourly cron.
 * Fixing the reported sites and moving on is exactly what KANUN 0 forbids: a
 * reported defect is a SAMPLE, not an inventory.
 *
 * These tests derive the class from the source instead of listing it, so a new
 * call site added tomorrow is covered without anyone remembering this file.
 *
 * @coversNothing
 */
class PersistenceResultTest extends TestCase {

	/**
	 * Absolute path of a file inside the plugin root.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	private function plugin_file( string $relative ): string {
		return dirname( __DIR__, 3 ) . '/' . $relative;
	}

	/**
	 * Every shipped PHP file, as path => source.
	 *
	 * 🔴 Where this scan STARTS: the `src/` tree plus the two root PHP files,
	 * which together are the plugin's whole executable surface once
	 * `.distignore` has removed `tests/`, `bin/` and the rest. A scan rooted
	 * anywhere narrower — the diff, one directory, the files an audit happened
	 * to name — cannot see the member it was not pointed at, and that member is
	 * reliably the busiest one.
	 *
	 * @return array<string, string>
	 */
	private function shipped_sources(): array {
		$sources = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->plugin_file( 'src' ), \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$path             = str_replace( '\\', '/', $file->getPathname() );
				$sources[ $path ] = (string) file_get_contents( $file->getPathname() );
			}
		}

		foreach ( array( 'mhm-currency-switcher.php', 'uninstall.php' ) as $root_file ) {
			$path             = str_replace( '\\', '/', $this->plugin_file( $root_file ) );
			$sources[ $path ] = (string) file_get_contents( $this->plugin_file( $root_file ) );
		}

		return $sources;
	}

	/**
	 * Guard: the scan really reads the tree. An empty source map would make
	 * every assertion below pass for free.
	 *
	 * @return void
	 */
	public function test_the_scan_reaches_the_shipped_tree(): void {
		$sources = $this->shipped_sources();

		$this->assertGreaterThan( 20, count( $sources ), 'The scan should see the whole src/ tree.' );

		$joined = implode( "\n", $sources );

		$this->assertStringContainsString(
			'class CurrencyStore',
			$joined,
			'Guard: the file the rules below are about is inside the scanned set.'
		);
	}

	/**
	 * 🔴 No caller may throw away `CurrencyStore::save()`'s answer.
	 *
	 * The method has always returned a bool. A bare `$store->save();` is a
	 * write whose failure nobody will ever hear about — and every one of these
	 * sites went on to tell somebody the opposite: an HTTP 200, a WP-CLI
	 * "success", or a "last synced" timestamp moved forward.
	 *
	 * @return void
	 */
	public function test_no_currency_store_save_result_is_discarded(): void {
		$offenders = array();

		foreach ( $this->shipped_sources() as $path => $source ) {
			foreach ( explode( "\n", $source ) as $number => $line ) {
				// A bare statement: the call is the whole line, so its return
				// value goes nowhere. Guarded uses read `if ( ! ...->save() )`
				// or assign, and never match.
				if ( 1 === preg_match( '/^\s*\$\w+(->\w+)*->save\(\s*\);\s*$/', $line )
					&& false !== strpos( $line, 'store->save' ) ) {
					$offenders[] = basename( $path ) . ':' . ( $number + 1 );
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'These write the currency store and never read whether it worked: ' . implode( ', ', $offenders )
		);
	}

	/**
	 * 🔴 The "last successful sync" stamp may only be written by the one
	 * method that saves first.
	 *
	 * `record_sync()` moves the timestamp the panel shows. Called on its own,
	 * next to a save nobody checked, it announces a sync that may never have
	 * reached the database — and the panel then tells the shop owner the rates
	 * are current while it serves the old ones. Routing every caller through
	 * `RateProvider::commit_sync()` puts the ordering in one place instead of
	 * asking three call sites to remember it.
	 *
	 * @return void
	 */
	public function test_record_sync_is_only_reached_through_commit_sync(): void {
		$offenders = array();

		foreach ( $this->shipped_sources() as $path => $source ) {
			if ( 'RateProvider.php' === basename( $path ) ) {
				continue;
			}

			foreach ( explode( "\n", $source ) as $number => $line ) {
				// Comments discuss it freely; only a real call counts.
				$trimmed = ltrim( $line );

				if ( 0 === strpos( $trimmed, '*' ) || 0 === strpos( $trimmed, '//' ) ) {
					continue;
				}

				if ( false !== strpos( $line, 'record_sync(' ) ) {
					$offenders[] = basename( $path ) . ':' . ( $number + 1 );
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'These stamp a sync without owning the save that must precede it: ' . implode( ', ', $offenders )
		);
	}

	/**
	 * 🔴 A carry that precedes a destructive delete may not be written blind.
	 *
	 * The two locks above derive the class from `->save()` and `record_sync(`,
	 * and that starting set has a hole: a write made with a bare
	 * `update_option()` is invisible to both. The hole was not theoretical —
	 * `LegacyOptionMigrator::run()` carries two payloads, the first sweep of
	 * this class guarded one of them, and the second sat four lines below a
	 * comment explaining why guarding mattered. Neither gate above could see
	 * it, and the diff read like the whole job was done.
	 *
	 * The migration is the one place in the plugin where an unread write
	 * DESTROYS rather than misreports: it deletes the legacy rows and stamps
	 * itself finished afterwards, so a failed carry takes the only copy with
	 * it and nothing ever tries again. Both carry targets therefore have to go
	 * through OptionWriter, whose answer a caller can act on.
	 *
	 * `DONE_OPTION` is deliberately not covered: failing to stamp it costs a
	 * repeated migration attempt, which is the safe direction.
	 *
	 * 🔴 WHERE THIS RULE IS BLIND, stated rather than discovered later. It reads
	 * literals, so a key held in a variable — `$key = 'mhmcs_settings';
	 * update_option( $key, … );` — walks straight past it, and `$carry_targets`
	 * is a hand-written list that will not grow on its own when a third payload
	 * is added. Measured, not assumed: an independent audit built both mutants
	 * and this rule called them clean.
	 *
	 * That is survivable only because the lock is not alone.
	 * `LegacyOptionMigratorTest::test_a_failed_settings_carry_leaves_the_legacy_data_alone()`
	 * asks the same question of the BEHAVIOUR, where the shape of the call does
	 * not matter, and it catches every mutant this one misses. The pair is the
	 * design; either half on its own would be a gate with a hole in it.
	 *
	 * @return void
	 */
	public function test_the_migration_never_carries_a_payload_with_an_unchecked_write(): void {
		$path   = $this->plugin_file( 'src/Core/LegacyOptionMigrator.php' );
		$source = (string) file_get_contents( $path );

		$this->assertStringContainsString(
			'delete_option( self::LEGACY_CURRENCIES )',
			$source,
			'Guard: this rule only matters because the method destroys the source afterwards. '
				. 'If that delete ever goes away, revisit the rule rather than deleting the test.'
		);

		$carry_targets = array( 'CurrencyStore::OPTION_KEY', "'mhmcs_settings'" );
		$offenders     = array();

		foreach ( explode( 'update_option(', $source ) as $index => $chunk ) {
			if ( 0 === $index ) {
				continue;
			}

			/*
			 * Bounded at the statement's own closing `);`, not a fixed window.
			 * The first version of this rule read 200 characters ahead and
			 * reported a target that was already guarded — the window had run
			 * past the end of one statement and into a `get_option()` call that
			 * merely NAMED the same constant. A gate that is too wide is not a
			 * strict gate; it is one whose next reader will loosen it, having
			 * learned that its findings are not real.
			 */
			$end       = strpos( $chunk, ');' );
			$statement = false === $end ? $chunk : substr( $chunk, 0, $end );

			foreach ( $carry_targets as $target ) {
				if ( false !== strpos( $statement, $target ) ) {
					$offenders[] = $target;
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'A migrated payload is written with a bare update_option() and the method deletes '
				. 'the original afterwards: ' . implode( ', ', $offenders )
		);
	}

}
