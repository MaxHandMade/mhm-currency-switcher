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
 *    comment AND every string/template literal is stripped out first (see
 *    `component_source_without_literals()`). `amount * rate` is the
 *    natural way to write a conversion, and it is the one arithmetic
 *    operator this file has no other use for today — grep confirms zero
 *    occurrences outside comments and literals even before this test
 *    existed.
 * 2. No reference to `effective_rate`, `raw_rate`, or `sample_amount` — the
 *    three RAW NUMBERS `POST /rates/preview` returns alongside the
 *    pre-formatted `sample_from`/`sample_to` strings. The component has no
 *    legitimate reason to read any of them: it only ever displays the
 *    strings the server already formatted.
 *
 * The literal-stripping step exists because an independent review of an
 * earlier version of check 1 mutated it with the string `'Symbol*'` — an
 * ordinary label with no arithmetic in it — and the check went red with a
 * message that named none of this file's real concern. That is a
 * cry-wolf pin: the failure taught nothing about client-side arithmetic,
 * so the cheapest response to it is to weaken or delete the check, and
 * then it protects nothing. Stripping quoted text before searching closes
 * that specific hole. Scoping the `*` search to only fire near words like
 * `rate` / `amount` / `fee` / `convert` / `.value` was considered and
 * rejected: it is easy to slip past by assigning to a local first (`const
 * r = currency.rate.value; … r * 100;` puts the operator nowhere near the
 * word "rate"), and it would not have closed the false positive that was
 * actually found — literal-stripping already does that on its own.
 *
 * Neither check can catch every way arithmetic could be smuggled back in —
 * division, a helper function imported from elsewhere, `Math.pow`, digits
 * built up with repeated addition, an expression inside a template
 * literal's `${ … }` (removed wholesale along with the literal around it,
 * see `component_source_without_literals()`), or the computation moving to
 * a different file this test does not read would all pass silently.
 * Division (`/`) is deliberately NOT banned: it appears throughout this
 * file in ordinary, unrelated syntax (JSX self-closing tags, import
 * paths), so banning it would make the pin fail on unrelated changes
 * rather than on the defect it exists to catch. This is a narrow pin on
 * the specific shape a conversion is most likely to take here, not a proof
 * that no conversion exists.
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
	 * `ManageCurrencies.jsx`, with every comment AND every string/template
	 * literal removed.
	 *
	 * 🔴 Comments stripped before anything is searched for — the same
	 * discipline `PanelUnsavedChangesTest` uses, and for the same reason.
	 * This file's own explanatory comments talk about "rate → fee →
	 * rounding → format" and would otherwise defeat a naive text search
	 * before the pin ever reached real code; JSDoc blocks alone contain a
	 * `*` on nearly every line, which would make the multiplication check
	 * below fail permanently rather than on an actual regression.
	 *
	 * 🔴 String and template literals stripped for the identical reason, one
	 * layer down. An independent review of this pin mutated it with
	 * `'Symbol*'` — an ordinary label a maintainer might add for an
	 * unrelated reason, with no arithmetic in it at all — and the
	 * multiplication check below went red on it. That is the cry-wolf
	 * failure mode: a maintainer who hits it on an unrelated change gets a
	 * message that says nothing about client-side arithmetic, and the
	 * cheapest way out is to weaken or delete the pin. Stripping quoted
	 * text before searching removes that whole class of false positive,
	 * the same way stripping comments already does.
	 *
	 * Regex-based, not a real JS/template parser, so it inherits the
	 * matching caveat: a template literal's `${ … }` expression is removed
	 * along with the quoted text around it, so a real conversion computed
	 * INSIDE a template literal (`` `${ amount * rate }` ``) would not be
	 * caught either. Nothing in this file does that today — verified by
	 * reading — and it would be an unusual way to write it, but it is a
	 * real gap, not a hidden one.
	 *
	 * @return string
	 */
	private function component_source_without_literals(): string {
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/admin-app/src/components/tabs/ManageCurrencies.jsx'
		);

		$this->assertIsString( $source, 'ManageCurrencies.jsx must be readable.' );

		$stripped = preg_replace( '#/\*[\s\S]*?\*/#', '', $source );
		$stripped = preg_replace( '#(^|[^:])//.*$#m', '$1', (string) $stripped );

		// Template, double-quoted, and single-quoted string literals.
		// `\\.` inside each class matches an escaped character (`\'`, `\"`,
		// `` \` ``, `\\`, …) so an escaped quote inside the literal does not
		// end the match early.
		$stripped = preg_replace( '#`(?:\\\\.|[^`\\\\])*`#', '', (string) $stripped );
		$stripped = preg_replace( '#"(?:\\\\.|[^"\\\\])*"#', '', (string) $stripped );
		$stripped = preg_replace( '#\'(?:\\\\.|[^\'\\\\])*\'#', '', (string) $stripped );

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
		$body = $this->component_source_without_literals();

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
	 * no legitimate use for today — once comments AND string/template
	 * literals are both stripped out (see `component_source_without_literals()`).
	 *
	 * Deliberately a blanket ban rather than scoped to `*` appearing near
	 * `rate` / `amount` / `fee` / `convert` / `.value`: proximity matching
	 * is easy to slip past by assigning to a local first —
	 * `const r = currency.rate.value; const x = r * 100;` puts the `*`
	 * nowhere near the word "rate" — and it would not remove any more of
	 * the real false-positive surface than literal-stripping already does,
	 * since the false positive an independent review actually found
	 * (`'Symbol*'`, an ordinary string) is fixed by stripping literals, not
	 * by keyword scoping.
	 *
	 * Proved by mutation: add so much as `currency.rate.value * 100`
	 * anywhere in `ManageCurrencies.jsx` and this test goes red.
	 *
	 * @return void
	 */
	public function test_the_component_contains_no_multiplication(): void {
		$body = $this->component_source_without_literals();

		$this->assertStringNotContainsString(
			'*',
			$body,
			'The preview component appears to compute an amount itself: ManageCurrencies.jsx now '
				. 'contains a `*` outside of a comment or a string/template literal. Preview '
				. 'samples must come from the server (POST /rates/preview\'s sample_from / '
				. 'sample_to) — a second, client-side implementation of rate → fee → rounding → '
				. 'format would drift from the storefront\'s, and there is nothing here to keep the '
				. 'two in sync. If this `*` is not arithmetic on a price (for example, a plain '
				. 'string that happens to contain one), extend the literal-stripping in '
				. 'component_source_without_literals() rather than deleting this pin.'
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
		$body = $this->component_source_without_literals();

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
