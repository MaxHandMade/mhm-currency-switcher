<?php
/**
 * Compliance test: only one file knows how an unconverted base price is read.
 *
 * WooCommerce's `_price` meta is the catalogue price before this plugin's own
 * `PriceFilter` touches it. Reading it raw is the CORRECT thing to do in the
 * one place that needs it: asking `$product->get_price()` would run the value
 * back through our own filter and return an already-converted amount, which we
 * would then convert a second time.
 *
 * 🔴 The problem was never that the read is raw. It is that the knowledge —
 * the meta key, the reason, and the "do not use the CRUD getter here" caveat —
 * lived inline, twice, in a rendering class, behind a one-line comment. An
 * independent audit found both call sites and asked for exactly this: confine
 * `_price` to a single abstraction so the caveat has somewhere to live and a
 * third copy cannot appear quietly.
 *
 * This test pins the SHAPE. It cannot prove the resolver is correct — the unit
 * tests beside it do that — only that the knowledge did not spread again.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;

/**
 * Class BasePriceKnowledgeTest
 */
class BasePriceKnowledgeTest extends TestCase {

	/**
	 * The file allowed to name WooCommerce's price meta key.
	 *
	 * @var string
	 */
	private const OWNER = 'src/Core/BasePriceResolver.php';

	/**
	 * Every shipped PHP file, as repo-relative path => source.
	 *
	 * @return array<string, string>
	 */
	private function shipped_sources(): array {
		$root    = str_replace( '\\', '/', dirname( __DIR__, 3 ) );
		$sources = array();

		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $it as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$path             = str_replace( '\\', '/', $file->getPathname() );
				$sources[ str_replace( $root . '/', '', $path ) ] = (string) file_get_contents( $path );
			}
		}

		foreach ( array( 'mhm-currency-switcher.php', 'uninstall.php' ) as $rel ) {
			$sources[ $rel ] = (string) file_get_contents( $root . '/' . $rel );
		}

		return $sources;
	}

	/**
	 * The scan sees the tree, and sees the owner in it.
	 *
	 * Without this, a wrong root would hand the assertion below an empty set
	 * and it would pass over nothing at all.
	 *
	 * @return void
	 */
	public function test_the_scan_reaches_the_shipped_tree(): void {
		$sources = $this->shipped_sources();

		$this->assertGreaterThan( 25, count( $sources ), 'Guard: the shipped tree was found.' );
		$this->assertArrayHasKey( self::OWNER, $sources, 'Guard: the owning file is inside the scanned set.' );
	}

	/**
	 * Nothing outside the resolver names WooCommerce's price meta key.
	 *
	 * @return void
	 */
	public function test_only_the_resolver_names_the_price_meta_key(): void {
		$offenders = array();

		foreach ( $this->shipped_sources() as $path => $source ) {
			if ( self::OWNER === $path ) {
				continue;
			}

			// Comments are stripped first: a file may explain why it does NOT
			// read the meta directly, and prose must not fail the build.
			$code = preg_replace( '#/\*[\s\S]*?\*/#', '', $source );
			$code = (string) preg_replace( '#(^|[^:])//.*$#m', '$1', (string) $code );

			if ( preg_match( "/'_price'|\"_price\"/", $code ) ) {
				$offenders[] = $path;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"WooCommerce's `_price` meta is read outside " . self::OWNER . ": " . implode( ', ', $offenders )
				. ". Route it through the resolver so the reason for the raw read — avoiding this "
				. "plugin's own PriceFilter — stays in one place."
		);
	}
}
