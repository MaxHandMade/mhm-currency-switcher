<?php
/**
 * The admin bundle's script dependencies are a floor decision, not a detail.
 *
 * The plugin's "Requires at least: 6.6" exists because the bundle depends on
 * `react-jsx-runtime`, which core first registers in 6.6. An unregistered
 * dependency makes wp_enqueue_script() emit nothing at all — no error, no
 * console message — which is how the settings screen was a blank div on WP
 * 6.0–6.5 for every release from v0.3.0 to v1.1.1 while every one of them
 * advertised 6.0.
 *
 * So a new @wordpress/* import is a floor change in disguise, and this test is
 * where it has to be noticed.
 *
 * THIS TEST ONLY WORKS PAIRED WITH A CI STEP — IT CANNOT SEE A STALE BUILD
 * -------------------------------------------------------------------------
 * `test_the_built_bundle_declares_the_expected_handles()` below reads a BUILT
 * artefact, `admin-app/build/index.asset.php`, not the JS source. If someone
 * adds a new `@wordpress/*` import to `admin-app/src/` and commits without
 * running `npm run build`, `index.asset.php` is unchanged, this test reads the
 * unchanged file, and it passes — which is precisely the failure its own
 * assertion message warns about.
 *
 * What actually forces the artefact to be current is the "Built admin bundle
 * matches its source" step in `.github/workflows/testing.yml` (as of this
 * writing, lines 256-260): it runs `npm run build` in CI and fails the job if
 * `admin-app/build` then differs from what is committed. Only after that step
 * has run — and forced a real rebuild — does reading the built file here mean
 * anything.
 *
 * These two gates work only as a PAIR. Delete the CI step and this test keeps
 * passing on a stale, already-committed build forever, because nothing ever
 * rebuilds it. Delete this test and the CI step still rebuilds and diffs, but
 * nothing pins the resulting handle LIST to what the plugin's declared floor
 * can actually serve — a rebuilt-and-committed bundle with a new, correctly
 * committed `wp-date` dependency would pass CI and still break WP 6.6. If you
 * are looking at removing either one, read the other first.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;

/**
 * Class AdminBundleDependencyTest
 */
class AdminBundleDependencyTest extends TestCase {

	/**
	 * The handles this plugin's floor was measured against.
	 *
	 * @var array<int, string>
	 */
	private const EXPECTED = array(
		'react-jsx-runtime',
		'wp-a11y',
		'wp-api-fetch',
		'wp-components',
		'wp-element',
		'wp-i18n',
	);

	/**
	 * @return void
	 */
	public function test_the_built_bundle_declares_the_expected_handles(): void {
		$asset = require dirname( __DIR__, 3 ) . '/admin-app/build/index.asset.php';

		$this->assertIsArray( $asset );

		$handles = $asset['dependencies'];
		sort( $handles );
		$expected = self::EXPECTED;
		sort( $expected );

		$this->assertSame(
			$expected,
			$handles,
			'The admin bundle gained or lost a script dependency. A NEW handle may not be registered on '
				. 'the WordPress version this plugin declares as its floor, and the panel would render '
				. 'as an empty div with no error anywhere. Measure the floor before accepting this.'
		);
	}

	/**
	 * @return void
	 */
	public function test_the_hand_written_fallback_matches_the_built_list(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/Settings.php' );

		$this->assertIsString( $source );

		$this->assertSame(
			1,
			preg_match( "/'dependencies' => array\(([^)]*)\)/", $source, $block ),
			'Could not find the fallback dependency list in Settings.php.'
		);

		preg_match_all( "/'([a-z0-9-]+)'/", $block[1], $found );

		$fallback = $found[1];
		sort( $fallback );
		$expected = self::EXPECTED;
		sort( $expected );

		$this->assertSame(
			$expected,
			$fallback,
			'Settings.php\'s fallback list has drifted from the built asset file. It is what runs on any '
				. 'install where index.asset.php is missing, so a drifted copy is a panel that breaks '
				. 'only on the installs nobody tests.'
		);
	}
}
