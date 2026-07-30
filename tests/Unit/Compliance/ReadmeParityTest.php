<?php
/**
 * The two READMEs must stay the same document in two languages.
 *
 * Two files describing one plugin drift the moment someone edits one of them.
 * The sibling project's pair is the evidence: mhm-rentiva ships a 826-line
 * README.md next to a 651-line README-tr.md, and nothing ever told anyone.
 *
 * Heading TEXT cannot be compared — that is the point of a translation. What can
 * be compared is everything that is not prose: the shape of the document, the
 * images it shows, the code it tells a reader to run, and the version numbers it
 * claims. Those are the parts that go stale, and every one of them is checked
 * here in both directions.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;

/**
 * Class ReadmeParityTest
 */
class ReadmeParityTest extends TestCase {

	private const EN = 'README.md';
	private const TR = 'README-tr.md';

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
	 * The heading levels a document uses, in source order.
	 *
	 * Levels, not text: "## Screenshots" and "## Ekran görüntüleri" are the same
	 * structural position in two languages. A section added to one file and not
	 * the other changes this sequence.
	 *
	 * @param string $markdown Document.
	 * @return array<int, int>
	 */
	private function heading_levels( string $markdown ): array {
		preg_match_all( '/^(#{1,6}) \S/m', $markdown, $found );

		return array_map( 'strlen', $found[1] );
	}

	/**
	 * Every local image path a document references.
	 *
	 * Only local ones. The badge images are remote shields.io URLs whose text is
	 * deliberately localised ("version" / "sürüm"), so comparing those would fail
	 * on a correct translation.
	 *
	 * @param string $markdown Document.
	 * @return array<int, string>
	 */
	private function local_images( string $markdown ): array {
		preg_match_all( '/src="((?!https?:)[^"]+)"/', $markdown, $found );

		$images = array_values( array_unique( $found[1] ) );
		sort( $images );

		return $images;
	}

	/**
	 * Every token a reader is told to type: shortcode tags, WP-CLI subcommands,
	 * and repo scripts.
	 *
	 * @param string $markdown Document.
	 * @return array<int, string>
	 */
	private function code_tokens( string $markdown ): array {
		$tokens = array();

		preg_match_all( '/\[(mhm_[a-z_]+)/', $markdown, $shortcodes );
		$tokens = array_merge( $tokens, $shortcodes[1] );

		preg_match_all( '/wp mhm-cs ([a-z-]+)/', $markdown, $cli );
		$tokens = array_merge( $tokens, $cli[1] );

		preg_match_all( '/(bin\/[a-z-]+\.sh|bin\/[a-z-]+\.py)/', $markdown, $scripts );
		$tokens = array_merge( $tokens, $scripts[1] );

		preg_match_all( '/(composer (?:test|lint|analyze)|npm run [a-z:]+)/', $markdown, $commands );
		$tokens = array_merge( $tokens, $commands[1] );

		$tokens = array_values( array_unique( $tokens ) );
		sort( $tokens );

		return $tokens;
	}

	/**
	 * The version the badge row claims.
	 *
	 * The badge label is localised but the number is not, so the number is what
	 * this reads.
	 *
	 * @param string $markdown Document.
	 * @return string
	 */
	private function badge_version( string $markdown ): string {
		$this->assertSame(
			1,
			preg_match( '#badge/(?:version|s%C3%BCr%C3%BCm)-([0-9.]+)-#', $markdown, $found ),
			'Each README must carry exactly one version badge the gate can read.'
		);

		return $found[1];
	}

	// ─── Structure ───────────────────────────────────────────────────

	public function test_both_readmes_have_the_same_section_structure(): void {
		$en = $this->heading_levels( $this->source( self::EN ) );
		$tr = $this->heading_levels( $this->source( self::TR ) );

		$this->assertNotEmpty( $en, 'Found no headings in README.md — the scan is broken.' );

		$this->assertSame(
			$en,
			$tr,
			'The two READMEs no longer have the same sections in the same order. A section was added, removed or re-nested in one file only.'
		);
	}

	// ─── Images ──────────────────────────────────────────────────────

	public function test_both_readmes_show_the_same_images(): void {
		$en = $this->local_images( $this->source( self::EN ) );
		$tr = $this->local_images( $this->source( self::TR ) );

		$this->assertNotEmpty( $en, 'README.md references no local images — the scan is broken.' );

		$this->assertSame( $en, $tr, 'The two READMEs reference different local images.' );
	}

	public function test_every_referenced_image_exists(): void {
		foreach ( array( self::EN, self::TR ) as $file ) {
			foreach ( $this->local_images( $this->source( $file ) ) as $image ) {
				$this->assertFileExists(
					$this->root() . '/' . $image,
					"{$file} shows `{$image}`, which is not in the repository."
				);
			}
		}
	}

	// ─── Code a reader is told to type ───────────────────────────────

	public function test_both_readmes_document_the_same_commands_and_shortcodes(): void {
		$en = $this->code_tokens( $this->source( self::EN ) );
		$tr = $this->code_tokens( $this->source( self::TR ) );

		$this->assertNotEmpty( $en, 'Found no code tokens in README.md — the scan is broken.' );

		$this->assertSame(
			$en,
			$tr,
			'The two READMEs tell the reader to run different things. A shortcode, WP-CLI subcommand or script is documented in one language only.'
		);
	}

	public function test_every_shortcode_shown_is_registered(): void {
		preg_match_all( "/add_shortcode\(\s*'([a-z_]+)'/", $this->plugin_tree(), $registered );

		$this->assertNotEmpty( $registered[1], 'Found no add_shortcode() calls — the scan is broken.' );

		preg_match_all( '/\[(mhm_[a-z_]+)/', $this->source( self::EN ), $shown );

		foreach ( array_unique( $shown[1] ) as $tag ) {
			$this->assertContains(
				$tag,
				$registered[1],
				"README.md shows [{$tag}], which no add_shortcode() call registers."
			);
		}
	}

	public function test_every_wp_cli_subcommand_shown_exists(): void {
		$cli = $this->source( 'src/CLI/Commands.php' );

		preg_match_all( '/wp mhm-cs ([a-z-]+)/', $this->source( self::EN ), $shown );

		$this->assertNotEmpty( $shown[1], 'README.md documents no WP-CLI subcommands — the scan is broken.' );

		foreach ( array_unique( $shown[1] ) as $subcommand ) {
			// `@subcommand rates-sync` for the hyphenated ones; a plain method
			// name for any that does not need the annotation.
			$declared = 1 === preg_match( '/@subcommand ' . preg_quote( $subcommand, '/' ) . '\b/', $cli )
				|| 1 === preg_match( '/public function ' . preg_quote( str_replace( '-', '_', $subcommand ), '/' ) . '\(/', $cli );

			$this->assertTrue(
				$declared,
				"README.md documents `wp mhm-cs {$subcommand}`, but src/CLI/Commands.php declares no such subcommand."
			);
		}
	}

	// ─── Claimed versions match the plugin ───────────────────────────

	public function test_the_badge_version_matches_the_plugin_header(): void {
		$plugin = $this->source( 'mhm-currency-switcher.php' );

		$this->assertSame(
			1,
			preg_match( '/^ \* Version:\s+([0-9.]+)$/m', $plugin, $header ),
			'Could not read Version from the plugin header — the scan is broken.'
		);

		foreach ( array( self::EN, self::TR ) as $file ) {
			$this->assertSame(
				$header[1],
				$this->badge_version( $this->source( $file ) ),
				"{$file} advertises a different version from the plugin header. Bump both when you bump the plugin."
			);
		}
	}

	public function test_the_declared_floors_match_the_plugin_header(): void {
		$plugin = $this->source( 'mhm-currency-switcher.php' );

		$floors = array(
			'WordPress'   => 'Requires at least',
			'WooCommerce' => 'WC requires at least',
			'PHP'         => 'Requires PHP',
		);

		foreach ( $floors as $badge => $header_field ) {
			$this->assertSame(
				1,
				preg_match( '/^ \* ' . preg_quote( $header_field, '/' ) . ':\s+([0-9.]+)$/m', $plugin, $found ),
				"Could not read `{$header_field}` from the plugin header — the scan is broken."
			);

			foreach ( array( self::EN, self::TR ) as $file ) {
				$this->assertSame(
					1,
					preg_match( '#badge/' . preg_quote( $badge, '#' ) . '-([0-9.]+)%2B-#', $this->source( $file ), $shown ),
					"{$file} must carry a {$badge} floor badge the gate can read."
				);

				$this->assertSame(
					$found[1],
					$shown[1],
					"{$file} advertises {$badge} {$shown[1]}, but the plugin header says {$found[1]}."
				);
			}
		}
	}

	// ─── readme.txt declares exactly the screenshots that exist ──────

	/**
	 * WordPress.org pairs the Nth line under `== Screenshots ==` with
	 * `screenshot-N.png`. Declaring a caption with no file behind it is a
	 * rejection in its own right — the sibling plugin was refused for exactly
	 * that — and an empty heading is the same defect with nothing to point at.
	 * A file with no caption is the quieter half: it simply never appears.
	 *
	 * @return void
	 */
	public function test_readme_txt_captions_match_the_screenshot_files(): void {
		$readme = $this->source( 'readme.txt' );

		$this->assertSame(
			1,
			preg_match( '/^== Screenshots ==\R(.*?)(?=^== )/ms', $readme, $block ),
			'readme.txt must have a `== Screenshots ==` section followed by another section.'
		);

		preg_match_all( '/^(\d+)\. /m', $block[1], $captions );

		$declared = array_map( 'intval', $captions[1] );

		$files = glob( $this->root() . '/.wordpress-org/screenshot-*.png' );
		$this->assertIsArray( $files );

		$present = array();
		foreach ( $files as $file ) {
			if ( 1 === preg_match( '/screenshot-(\d+)\.png$/', $file, $n ) ) {
				$present[] = (int) $n[1];
			}
		}
		sort( $present );

		$this->assertNotEmpty( $present, 'Found no screenshot files — the scan is broken.' );

		$this->assertSame(
			$present,
			$declared,
			'readme.txt must caption exactly the screenshots that exist, numbered 1..N with no gaps: a caption without a file is what WordPress.org rejects, and a file without a caption never gets shown.'
		);
	}

	// ─── The language switcher works both ways ───────────────────────

	public function test_each_readme_links_to_the_other(): void {
		$this->assertStringContainsString(
			'href="README-tr.md"',
			$this->source( self::EN ),
			'README.md must link to the Turkish version.'
		);

		$this->assertStringContainsString(
			'href="README.md"',
			$this->source( self::TR ),
			'README-tr.md must link back to the English version.'
		);
	}

	/**
	 * Concatenated PHP source of src/.
	 *
	 * @return string
	 */
	private function plugin_tree(): string {
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root() . '/src' )
		);

		$all = '';

		foreach ( $files as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$all .= file_get_contents( $file->getPathname() );
			}
		}

		return $all;
	}
}
