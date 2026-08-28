<?php
/**
 * The panel must not promise behaviour the server does not perform.
 *
 * Every setting has two defaults, written in two languages: what the React
 * panel shows when the key is absent from `mhmcs_settings`, and what PHP does
 * when it reads the same absent key. Nothing forces those two to agree, and
 * when they disagree the result is a control that lies — it displays a value
 * the server is not acting on, and the shop owner has no way to tell.
 *
 * 🔴 Found in the browser, not by a gate (2026-07-29). The Advanced tab showed
 * "Daily" for the rate-update interval on a site whose stored settings had no
 * such key, while `Plugin.php` read the same absent key as `manual` and
 * scheduled nothing. Measured on a real install: the panel said daily, and
 * `wp cron event list` had no `mhmcs_update_rates` event at all. Every other
 * key in the class agreed; this was the only one that did not.
 *
 * Only the string-valued defaults are pinned here. The booleans are written as
 * `!== false` / `=== true` on the JS side and `?? true` / `! empty()` on the
 * PHP side — same meaning, no shared token to compare — so a textual pin would
 * assert nothing. They were swept by hand in the same round and agree.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use MhmCurrencySwitcher\Admin\RestAPI;
use PHPUnit\Framework\TestCase;

/**
 * Class SettingsDefaultParityTest
 */
class SettingsDefaultParityTest extends TestCase {

	/**
	 * Read a file from the repository root.
	 *
	 * @param string $relative Repo-relative path.
	 * @return string
	 */
	private function source( string $relative ): string {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/' . $relative );

		$this->assertIsString( $source, $relative . ' must be readable.' );

		return $source;
	}

	/**
	 * Extract a single capture group, failing loudly when the shape the
	 * pin depends on has moved.
	 *
	 * @param string $pattern Regex with one capture group.
	 * @param string $source  Haystack.
	 * @param string $what    Description for the failure message.
	 * @return string
	 */
	private function capture( string $pattern, string $source, string $what ): string {
		$this->assertSame(
			1,
			preg_match( $pattern, $source, $match ),
			"Could not locate {$what}. If the code moved, move this pin with it — do not delete it."
		);

		return $match[1];
	}

	/**
	 * The rate-update interval: what the panel shows versus what the
	 * scheduler does when nothing is stored.
	 *
	 * @return void
	 */
	public function test_rate_update_interval_default_agrees_across_layers(): void {
		$panel = $this->capture(
			"/settings\.rate_update_interval \|\| '([a-z]+)'/",
			$this->source( 'admin-app/src/components/tabs/AdvancedSettings.jsx' ),
			'the panel default for rate_update_interval'
		);

		$server = $this->capture(
			"/\\\$settings\['rate_update_interval'\] \?\? '([a-z]+)'/",
			$this->source( 'src/Plugin.php' ),
			'the server default for rate_update_interval'
		);

		$this->assertSame(
			$server,
			$panel,
			"The panel shows '{$panel}' for the rate-update interval while the server acts on "
				. "'{$server}'. A control that displays an interval nobody scheduled is worse than "
				. 'no control: the shop owner believes rates are updating.'
		);
	}

	/**
	 * The switcher size: shown by the panel, rendered by the front end.
	 *
	 * @return void
	 */
	public function test_switcher_size_default_agrees_across_layers(): void {
		$panel = $this->capture(
			"/switcher\.size \|\| '([a-z]+)'/",
			$this->source( 'admin-app/src/components/tabs/DisplayOptions.jsx' ),
			'the panel default for the switcher size'
		);

		$server = $this->capture(
			"/isset\( \\\$display\['size'\] \).*?: '([a-z]+)'/s",
			$this->source( 'src/Frontend/Switcher.php' ),
			'the server default for the switcher size'
		);

		$this->assertSame( $server, $panel );
	}

	/**
	 * 🔴 A newly added "Auto" currency must not be seeded with an invented
	 * rate of 1.
	 *
	 * `handleAddCurrency` built the new row with `rate: { type: 'auto', value:
	 * 1 }`. The server stores that as given — `save_currencies()` triggers no
	 * fetch — and 1 passes every usability check there is, because it is a
	 * perfectly ordinary positive number.
	 *
	 * So between adding a currency and the first successful sync, the shop
	 * converted at one-to-one: a $40 product showed as "€40". Not a missing
	 * price, not an error, and nothing in the panel to suggest it — the rate
	 * field showed 1 and the server agreed it was 1. The two layers were in
	 * perfect agreement about a number nobody had ever fetched.
	 *
	 * Zero is the honest seed: no rate has been fetched yet, so there is no
	 * rate. `has_usable_rate()` then answers false, and the surfaces that ask
	 * it — the switcher and the product widget — leave the currency out until
	 * a sync fills it in. An absent currency is recoverable; a wrong price the
	 * shop owner cannot see is not.
	 *
	 * Pinned by reading the source because this repository has no React test
	 * runner (`@testing-library` is not installed); the same technique the
	 * sibling pins in this file use. It locks the seeded literal, not the
	 * behaviour, so if the seeding moves, move this pin with it.
	 *
	 * @return void
	 */
	public function test_a_new_auto_currency_is_not_seeded_with_an_invented_rate(): void {
		$source = $this->source( 'admin-app/src/components/tabs/ManageCurrencies.jsx' );

		// Tolerates comments between the two keys — the first version of this
		// pattern required them adjacent and stopped matching the moment the
		// fix added an explanatory block. It failed loudly rather than passing
		// on a shape it could no longer see, which is what capture() is for.
		$seeded = $this->capture(
			"/rate: \{[\s\S]{0,800}?type: 'auto',[\s\S]{0,800}?value: ([0-9.]+),/",
			$source,
			'the rate a newly added currency is seeded with'
		);

		$this->assertSame(
			'0',
			$seeded,
			"A new currency is seeded with a rate of {$seeded}. Any non-zero seed is a rate nobody "
				. 'fetched: until the first sync the shop converts at that number and every layer '
				. 'agrees it is correct.'
		);
	}

	/**
	 * The accepted `rate_update_interval` values must have one owner.
	 *
	 * 🔴 RestAPI::RATE_INTERVALS is read by RestAPI's own sanitiser and by its
	 * reconcile_rate_schedule(), but Plugin::bootstrap()'s independent,
	 * `init`-time cron (re)scheduling used to carry a THIRD, literal copy of
	 * the same list. Nothing bound the two together: an interval added to
	 * RATE_INTERVALS would be accepted and stored by the REST sanitiser, then
	 * silently un-scheduled again on the very next `init`, because Plugin.php
	 * had never heard of it — automatic sync would die without an error
	 * anywhere.
	 *
	 * A source scan, not a runtime one: RATE_INTERVALS is a class constant, so
	 * there is no seam to inject a fourth interval through at runtime and
	 * watch the scheduler fail to react. What this pins instead is the
	 * reference itself — it fails the moment Plugin.php goes back to typing
	 * the interval list out by hand instead of reading RestAPI's copy.
	 *
	 * @return void
	 */
	public function test_plugin_cron_scheduling_reads_the_shared_interval_list(): void {
		$source = $this->source( 'src/Plugin.php' );

		$this->assertStringContainsString(
			'RestAPI::RATE_INTERVALS',
			$source,
			"Plugin.php's cron scheduling must read RestAPI::RATE_INTERVALS rather than typing the "
				. 'accepted interval list out again — a second copy is exactly how the REST sanitiser '
				. 'and the scheduler drifted apart before.'
		);

		$this->assertSame(
			0,
			preg_match( "/in_array\\(\\s*\\\$interval,\\s*array\\(\\s*'hourly'/", $source ),
			'Plugin.php must not carry its own literal copy of the accepted interval list alongside '
				. 'RestAPI::RATE_INTERVALS.'
		);

		// Guard: the constant this test pins against is spelled the way the
		// test expects and really is public — otherwise Plugin.php could not
		// have read it at all, and the string-contains assertion above would
		// be trivially satisfied by a comment mentioning the same name.
		$reflection = new \ReflectionClassConstant( RestAPI::class, 'RATE_INTERVALS' );

		$this->assertTrue(
			$reflection->isPublic(),
			'RestAPI::RATE_INTERVALS must be public for Plugin.php to read it.'
		);
	}
}
