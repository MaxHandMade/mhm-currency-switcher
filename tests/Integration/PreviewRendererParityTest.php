<?php
/**
 * The panel's preview must print what the storefront prints.
 *
 * There is no formatter in this plugin to reuse — FormatFilter merges
 * arguments and WooCommerce's wc_price() does the rendering — so the preview
 * calls wc_price() too, rather than growing a second implementation of
 * number formatting. This test is what makes "the same renderer" a fact:
 * every case below is rendered by PreviewRenderer and compared against a real
 * wc_price() call for the same currency and format.
 *
 * 🔴 The entity trap. wc_price() writes the dollar sign as `&#36;` and the
 * left_space/right_space gap as `&nbsp;`, so the comparison decodes entities
 * before it strips tags. A previous round lost a day to an assertion that was
 * matching an entity rather than the character it stands for.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use MhmCurrencySwitcher\Admin\PreviewRenderer;

/**
 * Class PreviewRendererParityTest
 */
class PreviewRendererParityTest extends MhmcsIntegrationTestCase {

	/**
	 * Formats that exercise every position and both decimal extremes.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function format_provider(): array {
		return array(
			'euro left'            => array( array( 'symbol' => '€', 'position' => 'left', 'decimals' => 2, 'decimal_sep' => ',', 'thousand_sep' => '.' ) ),
			'lira right spaced'    => array( array( 'symbol' => '₺', 'position' => 'right_space', 'decimals' => 2, 'decimal_sep' => ',', 'thousand_sep' => '.' ) ),
			'yen zero decimals'    => array( array( 'symbol' => '¥', 'position' => 'left', 'decimals' => 0, 'decimal_sep' => '.', 'thousand_sep' => ',' ) ),
			'dollar left spaced'   => array( array( 'symbol' => '$', 'position' => 'left_space', 'decimals' => 2, 'decimal_sep' => '.', 'thousand_sep' => ',' ) ),
			'krona right, space k' => array( array( 'symbol' => 'kr', 'position' => 'right', 'decimals' => 2, 'decimal_sep' => ',', 'thousand_sep' => ' ' ) ),
		);
	}

	/**
	 * @dataProvider format_provider
	 *
	 * @param array<string, mixed> $format Stored format.
	 * @return void
	 */
	public function test_the_preview_matches_wc_price_for_the_same_format( array $format ): void {
		$expected = trim(
			wp_strip_all_tags(
				html_entity_decode(
					wc_price(
						1234.5,
						array(
							'currency'           => 'EUR',
							'decimals'           => $format['decimals'],
							'decimal_separator'  => $format['decimal_sep'],
							'thousand_separator' => $format['thousand_sep'],
							'price_format'       => \MhmCurrencySwitcher\Integration\WooCommerce\FormatFilter::price_format_for_position( $format['position'] ),
						)
					),
					ENT_QUOTES,
					'UTF-8'
				)
			)
		);

		// wc_price() takes the symbol from get_woocommerce_currency_symbol(),
		// never from its arguments, so the expectation above carries the
		// WooCommerce table's euro sign. Swap it for the one under test.
		$expected = str_replace( '€', $format['symbol'], $expected );

		$this->assertSame( $expected, PreviewRenderer::render( 1234.5, 'EUR', $format ) );
	}

	/**
	 * No markup and no entities reach the panel.
	 *
	 * @return void
	 */
	public function test_the_rendered_sample_is_plain_text(): void {
		$sample = PreviewRenderer::render(
			1234.5,
			'USD',
			array( 'symbol' => '$', 'position' => 'left', 'decimals' => 2, 'decimal_sep' => '.', 'thousand_sep' => ',' )
		);

		$this->assertStringNotContainsString( '<', $sample, 'Markup reached the panel.' );
		$this->assertStringNotContainsString( '&#', $sample, 'An HTML entity reached the panel.' );
		$this->assertStringNotContainsString( '&nbsp;', $sample, 'A non-breaking-space entity reached the panel.' );
		$this->assertSame( '$1,234.50', $sample );
	}
}
