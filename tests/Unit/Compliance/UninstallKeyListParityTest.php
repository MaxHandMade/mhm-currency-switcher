<?php
/**
 * uninstall.php duplicates RestAPI::LEGACY_SETTING_KEYS as
 * $mhmcs_legacy_setting_keys because it runs without the autoloader and
 * cannot import the class. Duplicated lists drift; this pins those two
 * together so a drift fails loudly instead of leaving a secret in the
 * database.
 *
 * uninstall.php duplicates two OTHER lists that this test does NOT pin —
 * named here as a known gap, not an oversight:
 * - $mhmcs_meta_keys (uninstall.php, purge branch), which mirrors
 *   ProductPricing::META_KEY and the meta-key literal in
 *   CartFilter.php:302;
 * - the hardcoded pre-1.0.0 option names in the purge branch
 *   ('mhm_currency_switcher_currencies', 'mhm_currency_switcher_settings').
 * Adding pins for those was out of scope for the task that added this file.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use MhmCurrencySwitcher\Admin\RestAPI;
use PHPUnit\Framework\TestCase;

/**
 * Class UninstallKeyListParityTest
 */
class UninstallKeyListParityTest extends TestCase {

	/**
	 * @return void
	 */
	public function test_the_legacy_settings_key_list_matches_the_class_constant(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/uninstall.php' );

		$this->assertIsString( $source, 'uninstall.php must be readable.' );

		$this->assertSame(
			1,
			preg_match( '/\$mhmcs_legacy_setting_keys\s*=\s*array\(([^)]*)\)/', $source, $block ),
			'Could not find $mhmcs_legacy_setting_keys in uninstall.php. If it moved, move this pin.'
		);

		preg_match_all( "/'([a-z_]+)'/", $block[1], $found );

		sort( $found[1] );
		$expected = RestAPI::LEGACY_SETTING_KEYS;
		sort( $expected );

		$this->assertSame(
			$expected,
			$found[1],
			"uninstall.php's copy of the legacy settings keys has drifted from "
				. 'RestAPI::LEGACY_SETTING_KEYS. A key missing from the copy is a key the keep branch '
				. 'leaves in the database — and one of them is a user-supplied secret.'
		);
	}
}
