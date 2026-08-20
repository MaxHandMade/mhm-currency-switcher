<?php
/**
 * Compliance test: the panel must not throw away work the admin has not saved.
 *
 * `handleSyncRates()` re-fetched the server's currency list after a sync and
 * called `setCurrencies()` with it unconditionally. A currency the admin had
 * just added, or a rate they had just typed, disappeared from the table — while
 * the "unsaved changes" bar stayed on screen, because `dirty` was never
 * cleared. The admin was told they had unsaved changes and simultaneously shown
 * a table that no longer contained them.
 *
 * Refusing to sync while dirty is not the cautious choice, it is the only
 * coherent one: the server syncs against ITS OWN stored list, which by
 * definition does not contain unsaved edits. A sync started from a dirty panel
 * applies rates to a list the admin is no longer looking at and then presents
 * the result as theirs.
 *
 * WHY THIS IS A SOURCE-READING TEST
 * --------------------------------
 * This repository has no React test runner — `@wordpress/scripts` is the only
 * JS devDependency and `@testing-library/react` is not installed, verified by
 * resolution — so a rendered-component assertion is not available here. The
 * sibling pins in `SettingsDefaultParityTest` use the same technique for the
 * same reason.
 *
 * It locks a SHAPE, not a behaviour, and that limit is real: it cannot prove
 * the guard works, only that a guard is there and stands before the overwrite.
 * If the panel is rewritten, move this pin with it rather than deleting it —
 * the defect it describes is a property of the flow, not of this syntax.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;

/**
 * Class PanelUnsavedChangesTest
 */
class PanelUnsavedChangesTest extends TestCase {

	/**
	 * The body of `handleSyncRates`, from its declaration to the declaration
	 * that follows it.
	 *
	 * @return string
	 */
	private function sync_handler_body(): string {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/admin-app/src/App.jsx' );

		$this->assertIsString( $source, 'App.jsx must be readable.' );

		$start = strpos( $source, 'const handleSyncRates' );

		$this->assertNotFalse(
			$start,
			'Could not find handleSyncRates in App.jsx. If the sync handler moved or was renamed, '
				. 'move this pin with it — do not delete it.'
		);

		// Up to the next top-level `const handle...` declaration, which is how
		// every handler in this file is separated from the next.
		$next = strpos( $source, "\n\tconst handle", $start + 10 );
		$end  = false === $next ? strlen( $source ) : $next;

		$body = substr( $source, $start, $end - $start );

		// 🔴 Comments stripped before anything is searched for.
		//
		// The first version of this test looked for the word "dirty" in the raw
		// body — and the fix's own explanatory comment contains the phrase
		// "a dirty panel". Deleting the entire guard left this test GREEN,
		// proven by mutation. A pin that a comment can satisfy is not a pin; it
		// reports on prose, not on code.
		$body = preg_replace( '#/\*[\s\S]*?\*/#', '', $body );
		$body = preg_replace( '#(^|[^:])//.*$#m', '$1', (string) $body );

		return (string) $body;
	}

	/**
	 * The sync handler must consult `dirty` before it replaces local state.
	 *
	 * @return void
	 */
	public function test_syncing_is_guarded_by_the_unsaved_changes_flag(): void {
		$body = $this->sync_handler_body();

		// The guard SHAPE, not the word: `if ( dirty )`. Searching for the bare
		// identifier matched prose and matched a read that does nothing.
		$guard = strpos( $body, 'if ( dirty )' );

		$this->assertNotFalse(
			$guard,
			'handleSyncRates has no `if ( dirty )` guard, so syncing silently discards whatever '
				. 'the admin had typed but not saved.'
		);

		$overwrite = strpos( $body, 'setCurrencies' );

		$this->assertNotFalse(
			$overwrite,
			'handleSyncRates no longer calls setCurrencies. If the refresh moved, move this pin with it.'
		);

		$this->assertLessThan(
			$overwrite,
			$guard,
			'handleSyncRates reads `dirty` only AFTER overwriting the currency list, which is too '
				. 'late to protect anything.'
		);
	}

	/**
	 * The guard must actually stop the handler, not merely warn and carry on.
	 *
	 * Without this, a version that shows a notice and then overwrites the table
	 * anyway satisfies the ordering assertion above and loses exactly as much
	 * work as before.
	 *
	 * @return void
	 */
	public function test_the_guard_returns_instead_of_continuing(): void {
		$body = $this->sync_handler_body();

		$guard = strpos( $body, 'if ( dirty )' );
		$this->assertNotFalse( $guard, 'No `if ( dirty )` guard to check.' );

		$overwrite = strpos( $body, 'setCurrencies' );
		$this->assertNotFalse( $overwrite, 'No setCurrencies call to check against.' );

		$returns = strpos( $body, 'return;', $guard );

		$this->assertNotFalse(
			$returns,
			'The unsaved-changes guard never returns, so the handler falls through and overwrites '
				. 'the table anyway.'
		);

		$this->assertLessThan(
			$overwrite,
			$returns,
			'The guard returns only after the overwrite, which protects nothing.'
		);
	}
}
