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
 * NOTE: test_the_migration_never_carries_a_payload_with_an_unchecked_write()
 * was removed in 2.1.0 together with the pre-0.3.0 option-name migrator it
 * exercised. Its subject -- a carry whose write result went unchecked -- no
 * longer exists. The general unchecked-write class remains covered below:
 * CurrencyStore::save() (which forwards to OptionWriter::write()) and
 * record_sync() each still have their own lock in this file.
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

}
