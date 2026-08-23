<?php
/**
 * WordPress.org forbids contacting known asset-offloading services.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;

/**
 * Class OffloadingHostsTest
 *
 * WordPress.org's Plugin Check rejects a plugin whose source mentions any of a
 * fixed list of CDN and file-hosting domains — `PluginCheck.CodeAnalysis
 * .Offloading.OffloadedContent`, reported as an ERROR. It is a domain list, not
 * an analysis of what the URL is used for: fetching JSON data from
 * `cdn.jsdelivr.net` reads the same to the sniff as loading a script from it.
 *
 * This plugin shipped exactly that for four releases — the fallback exchange
 * rate API was served over jsDelivr — and nothing in our own suite could see
 * it, because our PHPCS ruleset is a different ruleset from theirs. It was
 * found only by installing Plugin Check and running it against the built ZIP,
 * which is not something anyone does on an ordinary commit.
 *
 * So the rule lives here now: the submission gate is encoded in the suite that
 * runs on every change, rather than discovered on the day we submit.
 *
 * 🔴 This is deliberately STRICTER than the sniff it mirrors. Plugin Check
 * registers text-string tokens only, so a comment naming a disallowed domain
 * passes their check; this scans the whole file and fails it. Two reasons, and
 * neither is an accident. A human reviewer reads comments. And a domain that
 * survives in a comment is a domain the next person greps for and restores —
 * which is precisely how the URL below wants to drift back.
 *
 * @coversNothing
 */
class OffloadingHostsTest extends TestCase {

	/**
	 * Domains Plugin Check treats as offloading services.
	 *
	 * 🔴 A MIRROR of `PluginCheckCS\PluginCheck\Helpers\OffloadingServicesTrait
	 * ::get_known_offloading_services()`, read from plugin-check 2.1.0. A mirror
	 * can drift from its source, and this one will: when the list grows, this
	 * copy does not. That is an accepted limit, not an oversight — the
	 * alternative is depending on their plugin from our test suite. Re-read the
	 * source list before a submission; it is the authority, this is the early
	 * warning.
	 *
	 * Trimmed to the plain-substring entries: the source holds a few regex
	 * fragments with look-behinds (`(?<!api\.)cloudflare\.com`) whose exclusions
	 * matter, and half-copying those would produce false positives — which is
	 * how a gate teaches its readers to ignore it.
	 *
	 * @var array<int, string>
	 */
	private const OFFLOADING_HOSTS = array(
		'code.jquery.com',
		'cdn.jsdelivr.net',
		'cdn.rawgit.com',
		'code.getmdl.io',
		'bootstrapcdn',
		'cdn.datatables.net',
		'aspnetcdn.com',
		'ajax.googleapis.com',
		'webfonts.zoho.com',
		'raw.githubusercontent.com',
		'unpkg.com',
		'imgur.com',
		'rawgit.com',
		'amazonaws.com',
		'cdn.tiny.cloud',
		'tailwindcss.com',
		'herokuapp.com',
		'kit.fontawesome',
		'use.fontawesome',
		'googleusercontent.com',
		'placeholder.com',
	);

	/**
	 * Absolute path of a file inside the plugin root.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	private function plugin_file( string $relative ): string {
		return dirname( __DIR__, 3 ) . '/' . $relative;
	}

	/**
	 * Every shipped PHP file, as path => source.
	 *
	 * 🔴 Where this scan STARTS: `src/` plus the root PHP files — the executable
	 * surface that reaches the ZIP. `tests/` and `bin/` are excluded by
	 * `.distignore` and may name whatever they need to; this file names
	 * jsDelivr itself, in the list above.
	 *
	 * @return array<string, string>
	 */
	private function shipped_sources(): array {
		$sources = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->plugin_file( 'src' ), \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$sources[ str_replace( '\\', '/', $file->getPathname() ) ] = (string) file_get_contents( $file->getPathname() );
			}
		}

		foreach ( array( 'mhm-currency-switcher.php', 'uninstall.php' ) as $root_file ) {
			$sources[ $root_file ] = (string) file_get_contents( $this->plugin_file( $root_file ) );
		}

		return $sources;
	}

	/**
	 * Guard: an empty or wrongly-rooted scan would pass every assertion below
	 * for free.
	 *
	 * @return void
	 */
	public function test_the_scan_reaches_the_shipped_tree(): void {
		$sources = $this->shipped_sources();

		$this->assertGreaterThan( 20, count( $sources ) );
		$this->assertStringContainsString(
			'exchangerate-api.com',
			implode( "\n", $sources ),
			'Guard: the scan reaches the file that holds the rate provider URLs — the one this rule exists for.'
		);
	}

	/**
	 * 🔴 No shipped file may name an offloading host.
	 *
	 * @return void
	 */
	public function test_no_shipped_file_contacts_a_known_offloading_host(): void {
		$offenders = array();

		foreach ( $this->shipped_sources() as $path => $source ) {
			foreach ( self::OFFLOADING_HOSTS as $host ) {
				if ( false !== stripos( $source, $host ) ) {
					$offenders[] = basename( $path ) . ' → ' . $host;
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"WordPress.org rejects these as offloading, whatever the URL is used for: \n  "
				. implode( "\n  ", $offenders )
		);
	}

	/**
	 * The same rule for readme.txt, which is read by the reviewer and by every
	 * visitor to the plugin page.
	 *
	 * Kept separate from the source scan because the consequence differs: a URL
	 * in the code is a request the plugin makes, while one in readme.txt is a
	 * claim about a service the plugin uses. Both have to change together, and
	 * the release where they did not would be the confusing one.
	 *
	 * @return void
	 */
	public function test_the_readme_does_not_document_an_offloading_host(): void {
		$readme = (string) file_get_contents( $this->plugin_file( 'readme.txt' ) );

		$this->assertStringContainsString( 'External services', $readme, 'Guard: the disclosure section is there to scan.' );

		$offenders = array();

		foreach ( self::OFFLOADING_HOSTS as $host ) {
			if ( false !== stripos( $readme, $host ) ) {
				$offenders[] = $host;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'readme.txt still documents a service WordPress.org disallows: ' . implode( ', ', $offenders )
		);
	}
}
