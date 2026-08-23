<?php
/**
 * The Display preview must show the list the visitor will actually see.
 *
 * Two defects, both measured in the browser. First: the preview mapped only
 * `currencies.filter( c => c.enabled )`, and the base currency has no row in
 * that array at all — it is not a conversion target — while the real
 * storefront switcher puts the base FIRST, always
 * (`src/Frontend/Switcher.php`, `// Always include the base currency first.`
 * in `build_options_list()`). So the panel showed a list no visitor would
 * ever see. Second: the `show_symbol` toggle had no branch in the preview at
 * all, so switching it changed nothing on screen while changing what the
 * storefront prints.
 *
 * WHY THIS FILE DOES NOT PIN THE BASE-CURRENCY FIX
 * --------------------------------------------------
 * A source-text pin — "does the string 'baseSymbol' appear in this file" —
 * was tried first and rejected. That exact shape has been defeated three
 * times already in this project: twice by a hidden string literal sitting in
 * a comment or an unrelated branch, and once by a hardcoded value
 * (`{ code: 'USD', symbol: '$' }`) that satisfies the grep while being wrong
 * for every shop whose base currency is not USD. The row-ordering logic is a
 * PURE FUNCTION — `buildPreviewRows( currencies, baseCurrency, baseSymbol )`
 * — extracted to `admin-app/src/lib/display-preview.js` specifically so it
 * can be executed and asserted on with real inputs and real outputs. See
 * `tests/js/display-preview.test.js`, which covers: base currency first; no
 * duplicate when the base also appears in the array; disabled currencies
 * excluded; the base's own symbol carried through; a currency with no stored
 * symbol not blanking its row.
 *
 * The `show_symbol` branch stays here because it is genuine JSX rendering —
 * whether a already-computed row's symbol is actually printed — which the
 * source-reading technique this repository uses elsewhere (see
 * PanelUnsavedChangesTest, SettingsDefaultParityTest) is the only option for,
 * since there is no React test runner in this repository
 * (`@testing-library/react` is not installed).
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;

/**
 * Class DisplayPreviewFidelityTest
 */
class DisplayPreviewFidelityTest extends TestCase {

	/**
	 * DisplayOptions.jsx with comments removed.
	 *
	 * @return string
	 */
	private function preview_source(): string {
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/admin-app/src/components/tabs/DisplayOptions.jsx'
		);

		$this->assertIsString( $source, 'DisplayOptions.jsx must be readable.' );

		$source = preg_replace( '#/\*[\s\S]*?\*/#', '', (string) $source );

		return (string) preg_replace( '#(^|[^:])//.*$#m', '$1', (string) $source );
	}

	/**
	 * The preview must delegate its row order to the extracted, unit-tested
	 * pure function rather than re-inlining the base-currency logic where a
	 * hardcoded value could hide again.
	 *
	 * @return void
	 */
	public function test_the_preview_delegates_row_order_to_the_tested_module(): void {
		$this->assertStringContainsString(
			"from '../../lib/display-preview'",
			$this->preview_source(),
			'DisplayOptions.jsx no longer imports the extracted row-order module. If it moved, move '
				. 'this pin with it — the point is that row order is computed by a function ' .
				'tests/js/display-preview.test.js exercises, not re-inlined here where a hardcoded '
				. 'value could satisfy a text search while being wrong for every non-USD shop.'
		);

		$this->assertMatchesRegularExpression(
			'/buildPreviewRows\s*\(/',
			$this->preview_source(),
			'DisplayOptions.jsx no longer calls buildPreviewRows(). If it was renamed, move this pin '
				. 'with it — do not delete it.'
		);
	}

	/**
	 * The show_symbol toggle must actually gate what the preview prints. This
	 * is genuine JSX rendering with no equivalent pure function to unit-test,
	 * so it is pinned by shape, not by a bare identifier — a bare "show_symbol"
	 * search would also match the ToggleControl that merely sets the setting,
	 * proving nothing about whether the preview reads it back.
	 *
	 * 🔴 If this assertion starts failing because the branch's exact
	 * punctuation changed (e.g. `!== false` became `?? true`), MOVE this pin
	 * to match the new shape — do not delete it. The defect it guards
	 * (flipping the toggle changing nothing on screen) is a property of the
	 * feature, not of this exact string.
	 *
	 * @return void
	 */
	public function test_the_preview_branches_on_show_symbol(): void {
		$this->assertMatchesRegularExpression(
			'/switcher\.show_symbol !== false &&/',
			$this->preview_source(),
			'The show_symbol toggle has no branch in the preview, so turning it on and off changes '
				. 'nothing the shop owner can see while changing what the storefront prints. If the '
				. 'branch\'s shape changed, move this pin to match it — do not delete it.'
		);
	}
}
