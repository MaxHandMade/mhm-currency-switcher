<?php
/**
 * uninstall.php duplicates two key lists because it runs without the
 * autoloader. Duplicated lists drift; this is the pin that says so out loud.
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
