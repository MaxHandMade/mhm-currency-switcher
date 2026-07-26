<?php
/**
 * Guards the unit bootstrap's isolation from any WordPress install.
 *
 * @package MhmCurrencySwitcher\Tests\Unit
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Class BootstrapTest
 */
class BootstrapTest extends TestCase {

	/**
	 * The unit suite must never boot WordPress, even when a tests-lib
	 * directory happens to exist on the machine.
	 *
	 * Regression: the bootstrap used to enter the WP path whenever
	 * `/tmp/wordpress-tests-lib` existed — a directory another project
	 * may have left behind — which made the unit suite fatal on hosts
	 * without mysqli and forced every contributor to pass
	 * WP_TESTS_DIR=/nonexistent by hand.
	 *
	 * @return void
	 */
	public function test_unit_suite_does_not_boot_wordpress(): void {
		$this->assertFalse(
			function_exists( 'wp_install' ),
			'The unit suite must run against stubs, not a real WordPress.'
		);
		$this->assertFalse(
			defined( 'MHMCS_INTEGRATION_TESTS' ),
			'MHMCS_INTEGRATION_TESTS must only be defined by the integration bootstrap.'
		);
	}
}
