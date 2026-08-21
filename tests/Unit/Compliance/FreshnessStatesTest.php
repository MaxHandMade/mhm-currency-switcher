<?php
/**
 * The freshness signal must render four states, and "no record" is not "never".
 *
 * 🔴 The upgrade case is the one that matters. mhmcs_rates_last_sync is created
 * by this release, so on the day it lands EVERY upgraded install has working
 * rates and no record. A panel that reads absence as "rates have never been
 * synchronised" tells a shop that synced yesterday that it never has — the
 * exact class of defect (the panel saying what the store does not do) that this
 * whole round exists to remove.
 *
 * Two files are read here, not one. `freshnessState()`, `FRESHNESS` and
 * `STALE_AFTER` live in the shared `admin-app/src/lib/freshness.js` module —
 * not in ManageCurrencies.jsx — because a later task's Advanced tab reuses the
 * same states, and exporting them from one tab component would make another
 * tab import it. The user-facing "No sync recorded yet" string, on the other
 * hand, is rendered in ManageCurrencies.jsx itself, so that is where it is
 * pinned.
 *
 * Source-read, because this repository has no React test runner.
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

		$freshness_body = $this->stripped_source( 'admin-app/src/lib/freshness.js' );

		$this->assertStringContainsString(
			'MANUAL_ONLY',
			$freshness_body,
			'The pill states must be evaluated in a named order with manual-only first; otherwise a '
				. 'manual-only shop matches both "never" and "manual-only" and shows an amber pill that '
				. 'is noise by its own definition.'
		);
	}
}
