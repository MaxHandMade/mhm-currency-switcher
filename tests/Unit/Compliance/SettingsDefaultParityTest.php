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
}
