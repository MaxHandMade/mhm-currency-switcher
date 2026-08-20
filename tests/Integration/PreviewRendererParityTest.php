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
 * 📌 That `&#36;` behaviour was measured on a live shop running WooCommerce
 * 10.9.4 (see project notes). This suite's own harness resolves `WC_VERSION=
 * latest` to 11.0.1, and 11.0.1 does NOT write `&#36;` — it wraps the symbol
 * literally, unescaped, in `<span class="woocommerce-Price-currencySymbol">`.
 * The entity baseline moved between those two versions; nothing here reads
 * the symbol from that entity form, so PreviewRenderer is unaffected, but any
 * FUTURE assertion that expects a literal `&#NN;` in raw wc_price() output
 * must re-measure against whichever WooCommerce version is actually running.
 *
 * 🔴 Ordinary symbols (€, ₺, ¥, $, kr) below do not actually exercise the
 * ORDER of decode-then-strip in PreviewRenderer::render(): none of them
 * decode into `<` or `>`, wp_strip_all_tags() never touches entity text, and
 * PreviewRenderer's own symbol filter always substitutes a literal character
 * anyway — so for this data set the two operations commute and swapping them
 * changes nothing. Measured directly: reversing the two calls in `render()`
 * left every case in `format_provider()` green. The one input where the
 * order is observable is an entity that decodes INTO something tag-shaped —
 * `&lt;b&gt;` becomes the three characters `<b>` — which is why that case is
 * tested separately below rather than folded into `format_provider()`: the
 * parity check above builds its expectation by substituting the symbol with
 * `str_replace()` AFTER decoding, so it never round-trips a symbol through
 * wc_price()'s own (unescaped) HTML the way `PreviewRenderer::render()` does,
 * and a tag-shaped symbol there would fail for reasons that have nothing to
 * do with decode/strip order.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use MhmCurrencySwitcher\Admin\PreviewRenderer;
use MhmCurrencySwitcher\Admin\RestAPI;

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

	/**
	 * The one input where decode/strip order is observable, per the class
	 * docblock. `wc_price()` embeds the symbol argument into its HTML with no
	 * escaping of its own, so a symbol containing `&lt;b&gt;` reaches the
	 * page as the literal six characters `&lt;b&gt;` sitting inside a real
	 * `<span>`.
	 *
	 * - Decode first (the shipped order): `&lt;b&gt;` becomes the three
	 *   characters `<b>`, a real — if stray — tag, which the subsequent
	 *   strip then removes along with the genuine `<span>` tags around it.
	 * - Strip first: the literal text `&lt;b&gt;` is not `<...>` syntax, so
	 *   stripping does not touch it; only the genuine `<span>` tags around it
	 *   are removed. Decoding afterward then turns the surviving `&lt;b&gt;`
	 *   text into a real, un-stripped `<b>` that reaches the final sample.
	 *
	 * A saved currency symbol reaches `wc_price()` exactly this unescaped, so
	 * this is not a contrived input — it is the shape any symbol takes once
	 * it is stored.
	 *
	 * @return void
	 */
	public function test_an_entity_that_decodes_into_a_tag_does_not_survive_as_markup(): void {
		$sample = PreviewRenderer::render(
			1234.5,
			'USD',
			array( 'symbol' => '&lt;b&gt;', 'position' => 'left', 'decimals' => 2, 'decimal_sep' => '.', 'thousand_sep' => ',' )
		);

		$this->assertStringNotContainsString( '<', $sample, 'An entity that decodes into a tag survived as markup: html_entity_decode() must run before wp_strip_all_tags(), not after.' );
		$this->assertStringNotContainsString( '&lt;', $sample, 'The tag-shaped entity was neither decoded nor stripped.' );
	}

	/**
	 * `CurrencyStore::get_currencies()` returns raw persisted rows with no
	 * normalisation, so a legacy row, a partial save, or a manual import can
	 * reach `render()` with no saved symbol at all. It must fall back the
	 * same way `RestAPI::ensure_currency_format()` does — through
	 * `RestAPI::default_symbol_for()`, the real WooCommerce currency-symbol
	 * table — not the bare ISO code an earlier version of this method
	 * printed literally.
	 *
	 * @return void
	 */
	public function test_a_format_with_no_symbol_falls_back_to_the_currency_table_default(): void {
		$expected_symbol = RestAPI::default_symbol_for( 'GBP' );

		$this->assertNotSame( 'GBP', $expected_symbol, 'Precondition: the WooCommerce table must hold a real symbol for GBP, or this test proves nothing.' );

		$sample = PreviewRenderer::render(
			1234.5,
			'GBP',
			array( 'position' => 'left', 'decimals' => 2, 'decimal_sep' => '.', 'thousand_sep' => ',' )
		);

		$this->assertStringContainsString( $expected_symbol, $sample, 'A currency row with no saved symbol did not fall back to RestAPI::default_symbol_for().' );
		$this->assertStringNotContainsString( 'GBP', $sample, 'The bare ISO code leaked into the sample instead of a real currency symbol.' );
	}

	/**
	 * A currency row that reaches `render()` with decimal_sep, thousand_sep
	 * and position all missing must render in the SHOP's own configured
	 * style — the same one `RestAPI::ensure_currency_format()` would have
	 * filled in from `wc_get_price_decimal_separator()`,
	 * `wc_get_price_thousand_separator()` and the `woocommerce_currency_pos`
	 * option — not the hardcoded US-style defaults ('.', ',', 'left') an
	 * earlier version of this method used regardless of the shop's real
	 * configuration. The shop below is deliberately configured the OPPOSITE
	 * of those old hardcoded defaults, so a regression back to them cannot
	 * pass by coincidence.
	 *
	 * @return void
	 */
	public function test_a_format_missing_separators_and_position_uses_the_shops_own_configuration(): void {
		update_option( 'woocommerce_price_decimal_sep', ',' );
		update_option( 'woocommerce_price_thousand_sep', '.' );
		update_option( 'woocommerce_currency_pos', 'right' );

		$sample = PreviewRenderer::render(
			1234.5,
			'EUR',
			array( 'symbol' => 'E', 'decimals' => 2 )
		);

		$this->assertSame( '1.234,50E', $sample );
	}

	/**
	 * The filter pair must not survive a throw from inside `wc_price()`.
	 * Task 6 calls `PreviewRenderer::render()` in a loop over every currency
	 * row inside one REST dispatch; without a `finally`, one throwing row
	 * would leave the priority-200 symbol override registered for every row
	 * rendered after it, and for anything else in that request.
	 *
	 * Forces the throw with a temporary filter hooked to `wc_price_args` at
	 * priority 150 — lower than PreviewRenderer's own 200, so it runs and
	 * throws WHILE `wc_price()` is still processing, with PreviewRenderer's
	 * own filters already registered — then inspects `$wp_filter` directly,
	 * since the closures `render()` registers are private to the call and
	 * cannot be compared by reference from outside.
	 *
	 * @return void
	 */
	public function test_the_filter_pair_is_removed_even_when_wc_price_throws(): void {
		$thrower = static function ( $args ) {
			throw new \RuntimeException( 'forced for the finally test' );
		};

		add_filter( 'wc_price_args', $thrower, 150, 1 );

		$threw = false;

		try {
			PreviewRenderer::render(
				1234.5,
				'EUR',
				array( 'symbol' => '€', 'position' => 'left', 'decimals' => 2, 'decimal_sep' => '.', 'thousand_sep' => ',' )
			);
		} catch ( \RuntimeException $e ) {
			$threw = true;
		} finally {
			remove_filter( 'wc_price_args', $thrower, 150 );
		}

		$this->assertTrue( $threw, 'The forced exception did not propagate out of render() -- this test proves nothing without it.' );

		global $wp_filter;

		$symbol_callbacks = isset( $wp_filter['woocommerce_currency_symbol'] ) ? $wp_filter['woocommerce_currency_symbol']->callbacks : array();
		$args_callbacks   = isset( $wp_filter['wc_price_args'] ) ? $wp_filter['wc_price_args']->callbacks : array();

		$this->assertArrayNotHasKey( 200, $symbol_callbacks, 'The priority-200 symbol override survived a throw inside wc_price().' );
		$this->assertArrayNotHasKey( 200, $args_callbacks, 'The priority-200 args override survived a throw inside wc_price().' );
	}
}
