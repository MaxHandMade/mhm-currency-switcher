<?php
/**
 * The help tab must describe the plugin that exists.
 *
 * Two directions, because drift has two directions. Showing a shortcode the
 * plugin does not register misleads the reader; failing to show one it does
 * register is the defect that shipped in readme.txt, where "currency switcher
 * via shortcode" was advertised for releases without the shortcode ever being
 * named. A gate that only checks the first direction would not have caught it.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;

/**
 * Class HelpTabAccuracyTest
 */
class HelpTabAccuracyTest extends TestCase {

	private const EXPECTED_SAMPLE_COUNT = 2;
	private const EXPECTED_WIDGET_COUNT = 2;

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
		$source = file_get_contents( $this->root() . '/' . $relative );

		$this->assertIsString( $source, $relative . ' must be readable.' );

		return $source;
	}

	/**
	 * Concatenated PHP source of a directory tree.
	 *
	 * @param string $relative Directory.
	 * @return string
	 */
	private function tree( string $relative ): string {
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root() . '/' . $relative )
		);

		$all = '';

		foreach ( $files as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$all .= file_get_contents( $file->getPathname() );
			}
		}

		return $all;
	}

	/**
	 * The tab's declared samples: shortcode tag => attribute names.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function samples(): array {
		$jsx = $this->source( 'admin-app/src/components/tabs/HowToUse.jsx' );

		preg_match_all(
			"/\{\s*shortcode:\s*'([a-z_]+)',\s*attrs:\s*\[([^\]]*)\]\s*\}/",
			$jsx,
			$matches,
			PREG_SET_ORDER
		);

		$samples = array();

		foreach ( $matches as $match ) {
			preg_match_all( "/'([a-z_]+)'/", $match[2], $attrs );

			$samples[ $match[1] ] = $attrs[1];
		}

		return $samples;
	}

	/**
	 * The tab's declared Elementor widget titles.
	 *
	 * @return array<int, string>
	 */
	private function widgets(): array {
		$jsx = $this->source( 'admin-app/src/components/tabs/HowToUse.jsx' );

		$this->assertSame(
			1,
			preg_match( '/export const ELEMENTOR_WIDGETS = \[([^\]]*)\]/', $jsx, $block ),
			'ELEMENTOR_WIDGETS must be a flat exported array literal — the gate parses it.'
		);

		preg_match_all( "/'([^']+)'/", $block[1], $titles );

		return $titles[1];
	}

	/**
	 * Shortcode tags the plugin actually registers.
	 *
	 * @return array<int, string>
	 */
	private function registered_shortcodes(): array {
		preg_match_all( "/add_shortcode\(\s*'([a-z_]+)'/", $this->tree( 'src' ), $found );

		return array_values( array_unique( $found[1] ) );
	}

	/**
	 * Elementor widget titles the plugin actually exposes.
	 *
	 * @return array<int, string>
	 */
	private function registered_widget_titles(): array {
		preg_match_all(
			"/function get_title\(\): string \{\s*return __\(\s*'([^']+)'/",
			$this->tree( 'src/Integration/Elementor' ),
			$found
		);

		return array_values( array_unique( $found[1] ) );
	}

	// ─── Direction A: nothing shown is invented ──────────────────────

	public function test_every_shortcode_shown_is_registered(): void {
		$registered = $this->registered_shortcodes();

		$this->assertNotEmpty( $registered, 'Found no add_shortcode() calls — the scan is broken.' );

		foreach ( array_keys( $this->samples() ) as $tag ) {
			$this->assertContains(
				$tag,
				$registered,
				"The help tab shows [{$tag}], which no add_shortcode() call registers."
			);
		}
	}

	public function test_every_attribute_shown_is_read_by_its_shortcode(): void {
		foreach ( $this->samples() as $tag => $attrs ) {
			$file = $this->file_registering( $tag );

			foreach ( $attrs as $attr ) {
				$read = 1 === preg_match( "/\\\$atts\[\s*'{$attr}'\s*\]/", $file )
					|| 1 === preg_match( "/'{$attr}'\s*=>/", $file );

				$this->assertTrue(
					$read,
					"The help tab documents `{$attr}` for [{$tag}], but that shortcode never reads it."
				);
			}
		}
	}

	public function test_every_elementor_widget_shown_has_that_title(): void {
		$titles = $this->registered_widget_titles();

		$this->assertNotEmpty( $titles, 'Found no get_title() literals — the scan is broken.' );

		foreach ( $this->widgets() as $shown ) {
			$this->assertContains(
				$shown,
				$titles,
				"The help tab names the Elementor widget '{$shown}', which no get_title() returns."
			);
		}
	}

	// ─── Direction B: nothing real is omitted ────────────────────────

	public function test_every_registered_shortcode_is_documented(): void {
		foreach ( $this->registered_shortcodes() as $tag ) {
			$this->assertArrayHasKey(
				$tag,
				$this->samples(),
				"[{$tag}] is registered but the help tab never mentions it. This is the readme defect."
			);
		}
	}

	public function test_every_elementor_widget_is_documented(): void {
		foreach ( $this->registered_widget_titles() as $title ) {
			$this->assertContains(
				$title,
				$this->widgets(),
				"The Elementor widget '{$title}' exists but the help tab never mentions it."
			);
		}
	}

	public function test_the_sample_counts_are_pinned_exactly(): void {
		$this->assertCount(
			self::EXPECTED_SAMPLE_COUNT,
			$this->samples(),
			'Sample count changed. Update EXPECTED_SAMPLE_COUNT deliberately — an "at least" floor stops protecting.'
		);

		$this->assertCount(
			self::EXPECTED_WIDGET_COUNT,
			$this->widgets(),
			'Widget count changed. Update EXPECTED_WIDGET_COUNT deliberately.'
		);
	}

	/**
	 * Source of the file that registers a shortcode.
	 *
	 * @param string $tag Shortcode tag.
	 * @return string
	 */
	private function file_registering( string $tag ): string {
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root() . '/src' )
		);

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$source = file_get_contents( $file->getPathname() );

			if ( is_string( $source ) && 1 === preg_match( "/add_shortcode\(\s*'{$tag}'/", $source ) ) {
				return $source;
			}
		}

		$this->fail( "No file registers [{$tag}]." );
	}
}
