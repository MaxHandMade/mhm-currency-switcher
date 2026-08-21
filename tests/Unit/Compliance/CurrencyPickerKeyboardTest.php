<?php
/**
 * The currency picker must be closable and must not strand the keyboard.
 *
 * Measured before the fix: the popover closed on an outside mousedown and on
 * selection, and there was no keydown handler at all — so Escape did nothing.
 * Worse, opening it moves focus INTO the search field, so every close path
 * destroyed the focused element and focus fell back to <body>. A keyboard user
 * did not merely fail to return to the trigger; they lost their place on the
 * page.
 *
 * 🔴 Comments are stripped before anything is searched for, and the search is
 * for a SHAPE. The fix's own docblock contains the word "Escape", and a pin
 * that a comment can satisfy reports on prose rather than on code — this
 * repository has already shipped one of those.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;

/**
 * Class CurrencyPickerKeyboardTest
 */
class CurrencyPickerKeyboardTest extends TestCase {

	/**
	 * Component source with comments removed.
	 *
	 * @return string
	 */
	private function code(): string {
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/admin-app/src/components/shared/CurrencyPicker.jsx'
		);

		$this->assertIsString( $source, 'CurrencyPicker.jsx must be readable.' );

		$source = preg_replace( '#/\*[\s\S]*?\*/#', '', (string) $source );

		return (string) preg_replace( '#(^|[^:])//.*$#m', '$1', (string) $source );
	}

	/**
	 * @return void
	 */
	public function test_escape_closes_the_popover(): void {
		$this->assertMatchesRegularExpression(
			"/'Escape' ===/",
			$this->code(),
			'CurrencyPicker has no Escape branch, so the popover cannot be dismissed from the keyboard.'
		);
	}

	/**
	 * @return void
	 */
	public function test_closing_returns_focus_to_the_trigger(): void {
		$code = $this->code();

		$this->assertStringContainsString(
			'triggerRef',
			$code,
			'There is no reference to the trigger, so focus cannot be returned to it.'
		);
		$this->assertMatchesRegularExpression(
			'/triggerRef\.current\??\.focus\(\)/',
			$code,
			'The popover closes without returning focus. Opening it moves focus into the search field, '
				. 'so closing without restoring leaves focus on <body> and the user loses their place.'
		);
	}
}
