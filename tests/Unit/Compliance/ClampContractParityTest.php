<?php
/**
 * The clamping contract is written in three places and has to agree in all of
 * them: the server's constant, the sentence readme.txt shows a shop owner, and
 * the reason codes the panel turns into those sentences.
 *
 * Both pins here exist because an independent review proved the existing gates
 * could not see either drift.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use MhmCurrencySwitcher\Admin\RestAPI;
use PHPUnit\Framework\TestCase;

/**
 * Class ClampContractParityTest
 */
class ClampContractParityTest extends TestCase {

	/**
	 * Repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * Read a repo-relative file.
	 *
	 * @param string $relative Path.
	 * @return string
	 */
	private function source( string $relative ): string {
		$path = $this->root() . '/' . $relative;

		$this->assertFileExists( $path, $relative . ' must exist.' );

		$source = file_get_contents( $path );

		$this->assertIsString( $source, $relative . ' must be readable.' );

		return $source;
	}

	/**
	 * 🔴 The row cap's VALUE, not just its name.
	 *
	 * The two tests that exercise the cap build `MAX_CURRENCY_ROWS + 1` rows,
	 * so they follow the constant wherever it goes — which is right for them
	 * and leaves the number itself unpinned. An independent review set the
	 * constant to 5 and all 359 unit tests stayed green.
	 *
	 * The number is not an implementation detail: readme.txt answers "how many
	 * currencies can I add?" with it. And it has been wrong once already — it
	 * shipped at 100, which is BELOW the 163 codes `get_woocommerce_currencies()`
	 * offers, so the panel's own list could produce a request the server
	 * refused while the readme promised no limit at all.
	 *
	 * @return void
	 */
	public function test_the_row_cap_matches_the_number_the_readme_states(): void {
		$readme = $this->source( 'readme.txt' );

		$this->assertSame(
			1,
			preg_match( '/more than (\d+) currency rows/', $readme, $match ),
			'readme.txt must state the cap as "more than N currency rows" so this pin can read it.'
		);

		$this->assertSame(
			RestAPI::MAX_CURRENCY_ROWS,
			(int) $match[1],
			'readme.txt promises a different limit from the one the server enforces.'
		);

		$this->assertGreaterThan(
			200,
			RestAPI::MAX_CURRENCY_ROWS,
			'The cap must stay clear of the number of currency codes WooCommerce offers '
				. '(163 on WooCommerce 10.9.4). A cap below that is reachable from the plugin\'s '
				. 'own "New Currency" list, which is how it was wrong the first time.'
		);
	}

	/**
	 * 🔴 Every reason code the server emits has a sentence in the panel.
	 *
	 * `note_adjustment()` sends a machine-readable reason; `describeAdjustment()`
	 * in App.jsx turns it into the sentence the shop owner reads. A typo on
	 * either side does not fail anything — it falls through to the generic
	 * default, so a clamp that was carefully made reportable silently goes back
	 * to being unexplained. Nothing caught that before this pin.
	 *
	 * @return void
	 */
	public function test_every_server_reason_code_has_a_panel_sentence(): void {
		$php = $this->source( 'src/Admin/RestAPI.php' );
		$js  = $this->source( 'admin-app/src/App.jsx' );

		$this->assertGreaterThan(
			0,
			preg_match_all( "/note_adjustment\(\s*[^,]+,\s*[^,]+,\s*'([a-z_]+)'/", $php, $php_matches ),
			'No note_adjustment() reason codes found — the scan is broken, not the code.'
		);

		$this->assertGreaterThan(
			0,
			preg_match_all( "/case\s+'([a-z_]+)':/", $js, $js_matches ),
			'No case labels found in App.jsx — the scan is broken, not the code.'
		);

		$emitted  = array_unique( $php_matches[1] );
		$rendered = $js_matches[1];

		sort( $emitted );

		foreach ( $emitted as $reason ) {
			$this->assertContains(
				$reason,
				$rendered,
				"The server emits the adjustment reason '{$reason}' and App.jsx has no case for it, "
					. 'so the shop owner gets the generic fallback instead of the sentence that '
					. 'explains what was changed.'
			);
		}
	}
}
