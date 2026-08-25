<?php
/**
 * Compliance test: saving must not leave the panel holding a stale schedule.
 *
 * `nextSync` is seated once, from GET /currencies at mount, and `handleSave()`
 * never touched it. So a shop on "Manual only" — the state of every fresh
 * install — that switched the Advanced tab to "Hourly" and pressed Save got a
 * server-side event and a panel still holding the mount-time `null`, which
 * `formatNextSync()` renders as "Automatic updates are switched on, but no
 * update is scheduled. Re-save this setting to schedule one."
 *
 * The advice in that sentence was a dead end: a second save re-read nothing
 * either, so the warning survived every attempt to follow it and cleared only
 * on a page reload. The feature this dal added — telling the shop owner WHEN
 * the next update is due — was wrong in the one flow that turns it on.
 *
 * The sibling defect is on the server and is pinned by real behavioural tests
 * in `RestAPITest` (the save reports the schedule it armed, and reconciles
 * rather than re-anchoring on every save). This file pins the half that lives
 * in JSX.
 *
 * WHY THIS IS A SOURCE-READING TEST
 * --------------------------------
 * Same reason as `PanelUnsavedChangesTest`: there is no React test runner in
 * this repository — `@wordpress/scripts` is the only JS devDependency and
 * `@testing-library/react` is not installed — so a rendered-component
 * assertion is not available. It locks a SHAPE, and that limit is real: it
 * proves the handler re-seats the schedule, not that the value it seats is
 * correct. What the value must BE is asserted server-side in RestAPITest.
 *
 * If the panel is rewritten, move this pin with it rather than deleting it —
 * the defect it describes is a property of the flow, not of this syntax.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;

/**
 * Class PanelScheduleReseatTest
 */
class PanelScheduleReseatTest extends TestCase {

	/**
	 * The body of `handleSave`, from its declaration to the one that follows.
	 *
	 * @return string
	 */
	private function save_handler_body(): string {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/admin-app/src/App.jsx' );

		$this->assertIsString( $source, 'App.jsx must be readable.' );

		$start = strpos( (string) $source, 'const handleSave' );

		$this->assertNotFalse(
			$start,
			'Could not find handleSave in App.jsx. If the save handler moved or was renamed, '
				. 'move this pin with it — do not delete it.'
		);

		// Up to the next top-level `const handle...` declaration, which is how
		// every handler in this file is separated from the next.
		$next = strpos( (string) $source, "\n\tconst handle", $start + 10 );
		$end  = false === $next ? strlen( (string) $source ) : $next;

		$body = substr( (string) $source, $start, $end - $start );

		/*
		 * Comments stripped before anything is searched for — the lesson
		 * PanelUnsavedChangesTest paid for. This file's own explanation of the
		 * defect names `setNextSync`, so a raw search would be satisfied by
		 * the prose describing the bug rather than by the code fixing it.
		 */
		$body = preg_replace( '#/\*[\s\S]*?\*/#', '', $body );
		$body = preg_replace( '#(^|[^:])//.*$#m', '$1', (string) $body );

		return (string) $body;
	}

	/**
	 * The extractor found the real handler, and stopped at its end.
	 *
	 * Without this, a slipped anchor would hand every later assertion either an
	 * empty string (silently green on a `assertStringNotContainsString`) or the
	 * whole rest of the file (green on everything, because `handleSyncRates`
	 * further down already seats the schedule).
	 *
	 * @return void
	 */
	public function test_the_extractor_sees_the_save_handler_and_only_it(): void {
		$body = $this->save_handler_body();

		$this->assertStringContainsString(
			'saveSettings(',
			$body,
			'Guard: the extracted body is the save handler.'
		);
		$this->assertStringNotContainsString(
			'handleSyncRates',
			$body,
			'Guard: extraction stopped at the next handler. handleSyncRates already seats the schedule, '
				. 'so running past it would make every assertion below pass for the wrong reason.'
		);
	}

	/**
	 * Saving re-seats the next scheduled sync.
	 *
	 * Deliberately does not care HOW: reading `next_sync` out of the save
	 * response and re-fetching GET /currencies are both correct fixes. What is
	 * not correct is leaving the state a save can invalidate seated only at
	 * mount.
	 *
	 * @return void
	 */
	public function test_saving_reseats_the_next_scheduled_sync(): void {
		$this->assertStringContainsString(
			'setNextSync(',
			$this->save_handler_body(),
			'handleSave changes the schedule (the interval control lives on the same form) and nothing '
				. 'else re-reads it before the next page load, so the panel would keep rendering the '
				. 'schedule state it had at mount — including the false "no update is scheduled" warning.'
		);
	}
}
