<?php
/**
 * Compliance test: the converted-amount preview must never be computed in
 * the browser.
 *
 * `ManageCurrencies.jsx` shows a per-row strip — "Customer sees: X → Y" —
 * built entirely from `POST /rates/preview`'s response, which the server
 * computes from the exact rate → fee → rounding → format pipeline the
 * storefront itself uses. Reimplementing any part of that arithmetic in
 * JavaScript, even as a "fast path" shown while a request is in flight,
 * would create a second implementation of the one number this plugin
 * exists to get right — and the two are not guaranteed to agree, because
 * nothing keeps a JS copy of a fee/rounding rule in sync with the PHP one
 * it was copied from. The component is required to show nothing (or a
 * placeholder) rather than a number it worked out itself.
 *
 * WHY THIS IS A SOURCE-READING TEST
 * ----------------------------------
 * This repository has no React test runner — `@wordpress/scripts` is the
 * only JS devDependency and `@testing-library/react` is not installed,
 * verified by resolution — so there is no rendered component to feed a
 * rate and a fee and assert on the number it prints. `PanelUnsavedChangesTest`
 * documents the same limitation for the same file.
 *
 * WHAT THIS PIN CAN AND CANNOT PROVE
 * -------------------------------------
 * It proves the absence of two concrete SHAPES in this one file, not the
 * absence of client-side conversion arithmetic in general:
 *
 * 1. No `*` (multiplication) token anywhere in the component, once every
 *    comment is stripped out. `amount * rate` is the natural way to write
 *    a conversion, and it is the one arithmetic operator this file has no
 *    other use for today — grep confirms zero occurrences outside comments
 *    even before this test existed.
 * 2. No reference to `effective_rate`, `raw_rate`, or `sample_amount` — the
 *    three RAW NUMBERS `POST /rates/preview` returns alongside the
 *    pre-formatted `sample_from`/`sample_to` strings. The component has no
 *    legitimate reason to read any of them: it only ever displays the
 *    strings the server already formatted.
 *
 * Neither check can catch every way arithmetic could be smuggled back in —
 * division, a helper function imported from elsewhere, `Math.pow`, digits
 * built up with repeated addition, or the computation moving to a
 * different file this test does not read would all pass silently. Division
 * (`/`) is deliberately NOT banned: it appears throughout this file in
 * ordinary, unrelated syntax (JSX self-closing tags, import paths), so
 * banning it would make the pin fail on unrelated changes rather than on
 * the defect it exists to catch. This is a narrow pin on the specific
 * shape a conversion is most likely to take here, not a proof that no
 * conversion exists.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;

/**
 * Class PreviewNoClientArithmeticTest
 */
class PreviewNoClientArithmeticTest extends TestCase {

	/**
	 * `ManageCurrencies.jsx`, with every comment removed.
	 *
	 * 🔴 Comments stripped before anything is searched for — the same
	 * discipline `PanelUnsavedChangesTest` uses, and for the same reason.
	 * This file's own explanatory comments talk about "rate → fee →
	 * rounding → format" and would otherwise defeat a naive text search
	 * before the pin ever reached real code; JSDoc blocks alone contain a
	 * `*` on nearly every line, which would make the multiplication check
	 * below fail permanently rather than on an actual regression.
	 *
	 * @return string
	 */
	private function component_source_without_comments(): string {
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/admin-app/src/components/tabs/ManageCurrencies.jsx'
		);

		$this->assertIsString( $source, 'ManageCurrencies.jsx must be readable.' );

		$stripped = preg_replace( '#/\*[\s\S]*?\*/#', '', $source );
		$stripped = preg_replace( '#(^|[^:])//.*$#m', '$1', (string) $stripped );

		return (string) $stripped;
	}

	/**
	 * Sanity check that the pin is engaging with real code: the strip and
	 * drawer must actually consume the server's preformatted samples,
	 * otherwise the checks below would pass on a component that does not
	 * render a preview at all.
	 *
	 * @return void
	 */
	public function test_the_component_reads_the_server_formatted_samples(): void {
		$body = $this->component_source_without_comments();

		$this->assertStringContainsString(
			'sample_from',
			$body,
			'ManageCurrencies.jsx no longer reads sample_from from the preview response. If the '
				. 'preview strip moved, move this pin with it.'
		);

		$this->assertStringContainsString(
			'sample_to',
			$body,
			'ManageCurrencies.jsx no longer reads sample_to from the preview response. If the '
				. 'preview strip moved, move this pin with it.'
		);
	}

	/**
	 * 🔴 No multiplication operator anywhere in the component. The one
	 * shape a "fast path" reimplementation of rate × amount would
	 * necessarily introduce, and the one arithmetic operator this file has
	 * no legitimate use for today.
	 *
	 * Proved by mutation: add so much as `currency.rate.value * 100`
	 * anywhere in `ManageCurrencies.jsx` and this test goes red.
	 *
	 * @return void
	 */
	public function test_the_component_contains_no_multiplication(): void {
		$body = $this->component_source_without_comments();

		$this->assertStringNotContainsString(
			'*',
			$body,
			'ManageCurrencies.jsx now contains a `*` outside of a comment. The preview samples are '
				. 'computed by the server; this file must only ever display the strings '
				. 'POST /rates/preview returns (sample_from / sample_to), never compute a '
				. 'converted amount itself.'
		);
	}

	/**
	 * 🔴 No reference to the raw numeric fields a client-side conversion
	 * would need. The component is meant to display only the two
	 * PRE-FORMATTED strings the server already rendered
	 * (`sample_from` / `sample_to`); it has no legitimate reason to read
	 * the numbers those strings were built from.
	 *
	 * @return void
	 */
	public function test_the_component_never_reads_the_raw_preview_numbers(): void {
		$body = $this->component_source_without_comments();

		foreach ( array( 'effective_rate', 'raw_rate', 'sample_amount' ) as $field ) {
			$this->assertStringNotContainsString(
				$field,
				$body,
				sprintf(
					'ManageCurrencies.jsx now references "%s" — one of the raw numbers '
						. 'POST /rates/preview returns alongside the pre-formatted samples. The '
						. 'component has no legitimate reason to read it: doing so is how a '
						. 'client-side reimplementation of the conversion would begin.',
					$field
				)
			);
		}
	}
}
