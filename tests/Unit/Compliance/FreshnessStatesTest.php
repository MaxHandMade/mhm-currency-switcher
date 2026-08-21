<?php
/**
 * The absent-record state must have its own user-facing string, or an
 * upgraded install is told its rates have never been synchronised.
 *
 * 🔴 The upgrade case is the one that matters. mhmcs_rates_last_sync is created
 * by this release, so on the day it lands EVERY upgraded install has working
 * rates and no record. A panel that reads absence as "rates have never been
 * synchronised" tells a shop that synced yesterday that it never has — the
 * exact class of defect (the panel saying what the store does not do) that this
 * whole round exists to remove.
 *
 * WHAT THIS FILE NO LONGER PINS, AND WHY
 * ---------------------------------------
 * This test previously also asserted that the string "MANUAL_ONLY" appears
 * somewhere in `admin-app/src/lib/freshness.js`. That assertion is gone, on
 * purpose, not by omission.
 *
 * A reviewer proved it dead by mutation: swapping the MANUAL_ONLY and
 * NO_RECORD blocks inside `freshnessState()` — which changes which pill a
 * manual-only shop with no sync record actually sees — left the substring
 * check green, because the word "MANUAL_ONLY" is still present in the file
 * regardless of which branch it sits in. A source-grep can see that a name
 * exists; it cannot see what ORDER the branches run in, and for this
 * function the order is the entire specification.
 *
 * `tests/js/freshness.test.js` replaces it. It loads freshnessState() as a
 * real function (this repository has a working Jest job for exactly this —
 * see price-converter.test.js) and executes it against the two overlapping
 * pairs that make ordering load-bearing: a manual-only shop with no sync
 * record (must be MANUAL_ONLY, not NO_RECORD) and a manual-only shop that
 * HAS synced (must be MANUAL_ONLY, not FRESH). Both are proven order-sensitive
 * by the same swap-and-rerun mutation drill that killed the old pin here.
 *
 * The user-facing string below earns a different kind of test: no React
 * test runner exists in this repository to render the panel and read what a
 * shop owner would actually see, so a source-read pin is the best available
 * check that this exact sentence is still the one on screen. It is kept.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;

/**
 * Class FreshnessStatesTest
 */
class FreshnessStatesTest extends TestCase {

	/**
	 * Read a source file relative to the plugin root, with comments stripped.
	 *
	 * 🔴 Comments stripped before anything is searched for, the same way
	 * PanelUnsavedChangesTest does it — a pin that a comment can satisfy is
	 * not a pin, it reports on prose, not on code.
	 *
	 * @param string $relative_path Path relative to the plugin root.
	 * @return string
	 */
	private function stripped_source( string $relative_path ): string {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/' . $relative_path );

		$this->assertIsString( $source, $relative_path . ' must be readable.' );

		$body = preg_replace( '#/\*[\s\S]*?\*/#', '', $source );
		$body = preg_replace( '#(^|[^:])//.*$#m', '$1', (string) $body );

		return (string) $body;
	}

	/**
	 * @return void
	 */
	public function test_the_panel_distinguishes_no_record_from_never_synchronised(): void {
		$panel_body = $this->stripped_source( 'admin-app/src/components/tabs/ManageCurrencies.jsx' );

		$this->assertStringContainsString(
			'No sync recorded yet',
			$panel_body,
			'The absent-record state has no string of its own, so an upgraded install is told its rates '
				. 'have never been synchronised.'
		);
	}
}
