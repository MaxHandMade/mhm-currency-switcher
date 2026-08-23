<?php
/**
 * Every path that applies fetched rates must also record that a sync happened.
 *
 * There are three of them — the REST sync button, the cron tick and the WP-CLI
 * command — and this plugin has already paid once for that shape: the
 * "sync lie" was three copies of the same five lines, and fixing two of them
 * would have left the third to keep serving the cache. A freshness timestamp
 * written by two of the three is worse than none: the pill would report a
 * stale time confidently.
 *
 * This reads source rather than behaviour on purpose. The CLI path needs
 * WP_CLI and is not reachable from the unit suite. What is checkable is the
 * shape: within the body that calls apply_rates(), the sync is committed too.
 *
 * 🔴 1.3.1 tightened what "committed" means. The token this looks for changed
 * from `record_sync(` to `commit_sync(`, and that is a STRONGER assertion, not
 * a relaxed one: `record_sync()` only stamped the clock, while `commit_sync()`
 * stores the rates first and refuses to stamp anything if that store failed.
 * All three paths used to call `record_sync()` immediately after an UNCHECKED
 * `save()`, so this gate was green while every one of them could stamp a sync
 * that never reached the database. PersistenceResultTest now forbids reaching
 * `record_sync()` from anywhere else, so the two locks together say: applying
 * rates implies committing them, and committing implies storing them.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;

/**
 * Class SyncTimestampParityTest
 */
class SyncTimestampParityTest extends TestCase {

	/**
	 * The three files that hold a sync path.
	 *
	 * @var array<int, string>
	 */
	private const SYNC_PATHS = array(
		'src/Admin/RestAPI.php',
		'src/Plugin.php',
		'src/CLI/Commands.php',
	);

	/**
	 * Source with comments removed.
	 *
	 * 🔴 Comments first. All three of these files DISCUSS apply_rates() in
	 * prose; a search over the raw text would match the explanation and pass
	 * with the call gone. Same lesson as PanelUnsavedChangesTest.
	 *
	 * @param string $relative Repo-relative path.
	 * @return string
	 */
	private function code( string $relative ): string {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/' . $relative );

		$this->assertIsString( $source, $relative . ' must be readable.' );

		$source = preg_replace( '#/\*[\s\S]*?\*/#', '', (string) $source );
		$source = preg_replace( '#(^|[^:])//.*$#m', '$1', (string) $source );

		return (string) $source;
	}

	/**
	 * @return void
	 */
	public function test_every_apply_rates_caller_also_commits_the_sync(): void {
		$callers = 0;

		foreach ( self::SYNC_PATHS as $relative ) {
			// One chunk per function or closure body.
			$chunks = preg_split( '/\bfunction\b/', $this->code( $relative ) );

			foreach ( (array) $chunks as $chunk ) {
				if ( false === strpos( (string) $chunk, 'apply_rates(' ) ) {
					continue;
				}

				++$callers;

				$this->assertStringContainsString(
					'commit_sync(',
					(string) $chunk,
					"A sync path in {$relative} applies fetched rates without committing the sync. "
						. 'The freshness pill would then report a time that belongs to a different '
						. 'sync, or none at all, while looking authoritative — or worse, a time for '
						. 'rates that were never stored.'
				);
			}
		}

		// 🔴 The anti-blindness control. Without it, a call site that MOVES to a
		// file outside SYNC_PATHS makes this test green by scanning nothing.
		$this->assertSame(
			3,
			$callers,
			'Expected exactly three apply_rates() call sites across the three sync paths. '
				. 'If a path moved, move this list with it — do not delete the pin.'
		);
	}
}
