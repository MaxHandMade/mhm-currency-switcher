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

		// The trailing `,?` is load-bearing: wp-scripts' prettier config requires
		// a trailing comma after a multi-line array literal, so a sample object
		// whose `attrs` array spans multiple lines ends `],\n\t},` — not `]\n\t}`.
		// Drop the `,?` and this silently matches one sample instead of two; the
		// count assertion in test_the_sample_counts_are_pinned_exactly is what
		// catches that undercount if it ever happens again.
		preg_match_all(
			"/\{\s*shortcode:\s*'([a-z_]+)',\s*attrs:\s*\[([^\]]*)\]\s*,?\s*\}/",
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
	 * The tab's declared sample shortcode tags, in source order.
	 *
	 * samples() keys by tag, which throws the order away. HowToUse.jsx reads
	 * PLACEMENT_SAMPLES by index, so order is part of the contract.
	 *
	 * @return array<int, string>
	 */
	private function sample_tags_in_order(): array {
		$jsx = $this->source( 'admin-app/src/components/tabs/HowToUse.jsx' );

		preg_match_all(
			"/\{\s*shortcode:\s*'([a-z_]+)',\s*attrs:\s*\[([^\]]*)\]\s*,?\s*\}/",
			$jsx,
			$matches
		);

		return $matches[1];
	}

	/**
	 * The attribute names PRICE_LIST_ATTR_DESCRIPTIONS supplies a description for.
	 *
	 * Keys are bare JS identifiers, e.g. `product_id: __( … )`.
	 *
	 * @return array<int, string>
	 */
	private function described_attributes(): array {
		$jsx = $this->source( 'admin-app/src/components/tabs/HowToUse.jsx' );

		$this->assertSame(
			1,
			preg_match( '/const PRICE_LIST_ATTR_DESCRIPTIONS = \{(.*?)\n\};/s', $jsx, $block ),
			'PRICE_LIST_ATTR_DESCRIPTIONS must be an object literal closed by `};` at column 0 — the gate parses it.'
		);

		preg_match_all( '/^\t([a-z_]+):/m', $block[1], $keys );

		$this->assertNotEmpty( $keys[1], 'Found PRICE_LIST_ATTR_DESCRIPTIONS but no keys in it — the scan is broken.' );

		return array_values( array_unique( $keys[1] ) );
	}

	/**
	 * The attribute keys a shortcode declares in its own defaults array, or an
	 * empty list when it has none.
	 *
	 * The asserting variant, shortcode_default_attributes(), fails when the
	 * defaults array is missing — correct for [mhmcs_currency_prices], which has
	 * one, but wrong as a general test: [mhmcs_currency_switcher] reads
	 * $atts['size'] directly and declares no defaults array at all.
	 *
	 * @param string $tag Shortcode tag.
	 * @return array<int, string>
	 */
	private function declared_default_attributes( string $tag ): array {
		$file = $this->file_registering( $tag );

		if ( 1 !== preg_match( '/array_merge\(\s*array\(([^)]*)\),\s*\$atts/s', $file, $block ) ) {
			return array();
		}

		preg_match_all( "/'([a-z_]+)'\s*=>/", $block[1], $found );

		return array_values( array_unique( $found[1] ) );
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

	/**
	 * String literals passed through their own translation call in the tab,
	 * i.e. `__( '<literal>', 'mhm-currency-switcher' )`.
	 *
	 * @return array<int, string>
	 */
	private function translated_literals(): array {
		$jsx = $this->source( 'admin-app/src/components/tabs/HowToUse.jsx' );

		preg_match_all(
			"/__\(\s*'([^']+)'\s*,\s*'mhm-currency-switcher'\s*\)/",
			$jsx,
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
			$file     = $this->file_registering( $tag );
			$defaults = $this->declared_default_attributes( $tag );

			foreach ( $attrs as $attr ) {
				// Two ways a shortcode can genuinely read an attribute, and no
				// third: it subscripts $atts directly (how [mhmcs_currency_switcher]
				// reads `size`), or it names the key in its own defaults array
				// (how [mhmcs_currency_prices] declares all four of its own).
				//
				// This used to also accept `'<attr>' =>` matched anywhere in the
				// registering file, which is not the same claim at all: every
				// ordinary array key in the file satisfied it. Under that rule the
				// tab could document invented switcher attributes named
				// `flag_url`, `symbol`, `code` or `name` and stay green, because
				// Switcher::build_options_list() happens to use those as array
				// keys. The gate said "that shortcode never reads it" while
				// measuring something much weaker.
				$read = 1 === preg_match( "/\\\$atts\[\s*'{$attr}'\s*\]/", $file )
					|| in_array( $attr, $defaults, true );

				$this->assertTrue(
					$read,
					"The help tab documents `{$attr}` for [{$tag}], but that shortcode neither subscripts \$atts['{$attr}'] nor declares it in its defaults array."
				);
			}
		}
	}

	/**
	 * HowToUse.jsx consumes PLACEMENT_SAMPLES positionally — PLACEMENT_SAMPLES[0]
	 * is rendered as the switcher shortcode and [1] as the price list, with [1]'s
	 * attrs driving the attribute table. samples() keys by tag and so cannot see
	 * order at all: swapping the two entries left every other assertion in this
	 * file green while the tab rendered `[mhmcs_currency_prices size="large"]` as
	 * "the currency switcher shortcode" and collapsed the attribute table to one
	 * wrong row.
	 *
	 * @return void
	 */
	public function test_the_samples_are_in_the_order_the_tab_renders_them(): void {
		$this->assertSame(
			array( 'mhmcs_currency_switcher', 'mhmcs_currency_prices' ),
			$this->sample_tags_in_order(),
			'PLACEMENT_SAMPLES order changed. HowToUse.jsx reads index 0 as the switcher and index 1 as the price list, so reordering this array silently mislabels both sections.'
		);
	}

	/**
	 * The attribute table renders one row per PLACEMENT_SAMPLES[1].attrs entry and
	 * fills the description cell from PRICE_LIST_ATTR_DESCRIPTIONS[ attr ]. A
	 * missing key is not an error in JSX — `{ undefined }` renders nothing — so an
	 * attribute added to the list without a description produced a silently empty
	 * "What it does" cell, with nothing in this file to notice.
	 *
	 * @return void
	 */
	public function test_every_documented_attribute_has_a_description(): void {
		$documented   = $this->samples()['mhmcs_currency_prices'] ?? array();
		$described    = $this->described_attributes();

		$this->assertNotEmpty( $documented, 'Found no attributes for [mhmcs_currency_prices] — the scan is broken.' );

		sort( $documented );
		sort( $described );

		$this->assertSame(
			$documented,
			$described,
			'PLACEMENT_SAMPLES[1].attrs and PRICE_LIST_ATTR_DESCRIPTIONS must name exactly the same attributes: an attr without a description renders an empty table cell, and a description without an attr is dead code.'
		);
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

	public function test_every_attribute_read_by_the_price_list_shortcode_is_documented(): void {
		$tag        = 'mhmcs_currency_prices';
		$documented = $this->samples()[ $tag ] ?? array();

		foreach ( $this->shortcode_default_attributes( $tag ) as $attr ) {
			$this->assertContains(
				$attr,
				$documented,
				"[{$tag}] reads the attribute `{$attr}` from its defaults array, but the help tab's PLACEMENT_SAMPLES never documents it. Add a new attribute to the shortcode and forget the tab, and this assertion is what catches it."
			);
		}
	}

	// ─── Direction C: what renders is translatable, not the raw registry ──

	public function test_every_elementor_widget_is_rendered_through_its_own_translation_call(): void {
		$translated = $this->translated_literals();

		foreach ( $this->widgets() as $widget ) {
			$this->assertContains(
				$widget,
				$translated,
				"ELEMENTOR_WIDGETS names '{$widget}', but it never appears as a literal inside its own __( '…', 'mhm-currency-switcher' ) call in HowToUse.jsx — the rendered sentence will not pick up the widget's translated title."
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
	 * The default attribute keys a shortcode's own defaults array declares,
	 * i.e. the `array( 'key' => ..., ... )` merged with the incoming $atts in
	 * a shortcode_atts-style call. This is the reverse direction of
	 * test_every_attribute_shown_is_read_by_its_shortcode(): that test checks
	 * that nothing shown is invented, this checks that nothing real is
	 * omitted.
	 *
	 * @param string $tag Shortcode tag.
	 * @return array<int, string>
	 */
	private function shortcode_default_attributes( string $tag ): array {
		$file = $this->file_registering( $tag );

		$this->assertSame(
			1,
			preg_match( '/array_merge\(\s*array\(([^)]*)\),\s*\$atts/s', $file, $block ),
			"Could not find a shortcode_atts-style defaults array ( array_merge( array( ... ), \$atts ) ) for [{$tag}] — the scan is broken."
		);

		preg_match_all( "/'([a-z_]+)'\s*=>/", $block[1], $found );

		$this->assertNotEmpty( $found[1], "Found the defaults array for [{$tag}] but no attribute keys in it — the scan is broken." );

		return array_values( array_unique( $found[1] ) );
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
