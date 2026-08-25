<?php
/**
 * Compliance test: nothing that runs against the declared PHP floor may use an
 * API that arrived after it.
 *
 * 🔴 The case that bought this file. `bin/check-admin-only-functions.php` was
 * added to CI in this branch with the commit message "loading-context gate --
 * CI'da". It called `str_contains()`, which is PHP 8.0. The plugin's floor is
 * 7.4 and CI's integration matrix has a 7.4 entry, so the gate died there with
 * a fatal on its first real run -- 815 admin-only functions catalogued, canaries
 * printed, and then nothing measured. Exit 255, no findings, no verdict.
 *
 * It survived unnoticed because of a scope gap, not luck:
 *   - `phpcs.xml.dist` scans the SHIPPED files (src/, the plugin file,
 *     uninstall.php). `bin/` is not shipped, so no ruleset ever read it.
 *   - The unit suite runs on 7.4 but never executes `bin/`.
 *   - The workflow only triggers on master/develop, so the branch that added
 *     the gate never ran it until a pull request was opened.
 *
 * Three gates, and the defect lived in the space between them.
 *
 * WHAT THIS TEST CAN AND CANNOT SEE
 * ---------------------------------
 * It matches a NAMED LIST of post-7.4 spellings, so it is a sieve, not a
 * compatibility analyser: an 8.0 API that is not in the list below passes. That
 * limit is the honest one available without adding a PHPCompatibility
 * dependency, and the list covers what actually gets typed by habit. When the
 * floor rises, this test goes green on its own and should be deleted rather
 * than left standing as decoration -- the floor guard below will say so.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;

/**
 * Class FloorPhpApiTest
 */
class FloorPhpApiTest extends TestCase {

	/**
	 * Spellings that do not exist on PHP 7.4, and the version that added them.
	 *
	 * @var array<string, string>
	 */
	private const POST_FLOOR_SPELLINGS = array(
		'str_contains('       => '8.0',
		'str_starts_with('    => '8.0',
		'str_ends_with('      => '8.0',
		'get_debug_type('     => '8.0',
		'preg_last_error_msg(' => '8.0',
		'fdiv('               => '8.0',
		'array_is_list('      => '8.1',
		'enum_exists('        => '8.1',
		'?->'                 => '8.0',
	);

	/**
	 * Repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return str_replace( '\\', '/', dirname( __DIR__, 3 ) );
	}

	/**
	 * Every PHP file that can be executed by a PHP as old as the declared floor.
	 *
	 * Shipped code plus `bin/`. The bin scripts are not shipped, but CI runs
	 * one of them on the floor entry of the integration matrix, and the reason
	 * the other is exempt today ("nobody wired it into CI") is a fact about the
	 * workflow file, not a property of the script.
	 *
	 * @return array<int, string>
	 */
	private function floor_files(): array {
		$root  = $this->root();
		$files = array( $root . '/mhm-currency-switcher.php', $root . '/uninstall.php' );

		foreach ( array( '/src', '/bin' ) as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . $dir ) );
			foreach ( $it as $f ) {
				if ( 'php' === $f->getExtension() ) {
					$files[] = str_replace( '\\', '/', $f->getPathname() );
				}
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * The floor this test is written against is still the declared one.
	 *
	 * Without this the whole file becomes decoration the day the plugin moves
	 * to PHP 8: every assertion below would keep passing while measuring a rule
	 * that no longer applies.
	 *
	 * @return void
	 */
	public function test_the_declared_php_floor_is_still_seven_four(): void {
		$header = (string) file_get_contents( $this->root() . '/mhm-currency-switcher.php' );

		$this->assertSame(
			1,
			preg_match( '/^\s*\*\s*Requires PHP:\s*([0-9.]+)/mi', $header, $m ),
			'Guard: the plugin header still declares a PHP floor.'
		);
		$this->assertSame(
			'7.4',
			$m[1],
			'The floor moved. Re-read the list in this file against the new floor, then delete '
				. 'this test if the list is empty rather than leaving it green and meaningless.'
		);
	}

	/**
	 * The scanner reaches the files the defect lived in.
	 *
	 * A path typo here would empty the file list and turn every assertion below
	 * into a pass over nothing -- the exact failure mode this whole file exists
	 * to catch in someone else's gate.
	 *
	 * @return void
	 */
	public function test_the_scan_reaches_shipped_code_and_the_bin_tools(): void {
		$files = $this->floor_files();

		$this->assertGreaterThan( 30, count( $files ), 'Guard: the shipped tree was found.' );

		foreach ( array( 'bin/check-admin-only-functions.php', 'bin/audit-control-classes.php', 'src/Admin/RestAPI.php' ) as $needle ) {
			$hit = false;
			foreach ( $files as $f ) {
				if ( false !== strpos( $f, $needle ) ) {
					$hit = true;
					break;
				}
			}
			$this->assertTrue( $hit, "Guard: {$needle} is inside the scanned set." );
		}
	}

	/**
	 * No file that can meet the floor uses an API newer than it.
	 *
	 * @return void
	 */
	public function test_nothing_on_the_floor_uses_a_newer_php_api(): void {
		$offenders = array();

		foreach ( $this->floor_files() as $file ) {
			$lines = explode( "\n", (string) file_get_contents( $file ) );

			foreach ( $lines as $n => $line ) {
				// A mention inside a comment is documentation, not a call.
				$code = trim( $line );
				if ( '' === $code || 0 === strpos( $code, '*' ) || 0 === strpos( $code, '//' ) || 0 === strpos( $code, '#' ) ) {
					continue;
				}

				foreach ( self::POST_FLOOR_SPELLINGS as $spelling => $since ) {
					if ( false !== strpos( $line, $spelling ) ) {
						$offenders[] = sprintf(
							'%s:%d uses %s (PHP %s)',
							str_replace( $this->root() . '/', '', $file ),
							$n + 1,
							rtrim( $spelling, '(' ),
							$since
						);
					}
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"These run against a PHP 7.4 floor and would fatal there:\n  " . implode( "\n  ", $offenders )
		);
	}
}
