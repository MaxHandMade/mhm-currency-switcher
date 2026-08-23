<?php
/**
 * `RestAPI::default_symbol_for()` must stay public.
 *
 * `Settings::enqueue_assets()` calls it directly, cross-class, to localize
 * the base currency's symbol for the panel — see Task 7. If visibility ever
 * regressed to `private`, that call site would fatal the moment the real
 * admin page loaded.
 *
 * THIS FILE USED TO ALSO PIN THE WIRING ITSELF — IT NO LONGER DOES
 * -------------------------------------------------------------------------
 * An earlier version of this file asserted, by reading `Settings.php`'s
 * source text, that the `baseSymbol` entry in the localize array was
 * actually built on `default_symbol_for(`. Three rounds of tightening that
 * regex each closed one hole and opened another — the last one defeated by
 * `'baseSymbol' => ( false ? "unused-marker default_symbol_for(...)" : '$' )`,
 * a hardcoded `'$'` with the required text hidden in a dead string literal,
 * which the narrowed regex could not tell from a real call. The same regex
 * also rejected two legitimate refactors (reordering the array, extracting
 * the call into a local variable). A regex over PHP source cannot tell a
 * function call from a string literal without parsing PHP, and no further
 * narrowing fixes that.
 *
 * That coverage now lives in
 * `tests/Integration/BaseSymbolLocalizeWiringTest.php`, which runs the real
 * `Settings::enqueue_assets()` and reads the localized JS back out of
 * WordPress's own script registry — behaviour, not text. See that file's
 * docblock for the full history of what defeated each earlier attempt here.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Admin;

use MhmCurrencySwitcher\Admin\RestAPI;
use PHPUnit\Framework\TestCase;

/**
 * Class SettingsLocalizeTest
 */
class SettingsLocalizeTest extends TestCase {

	/**
	 * @return void
	 */
	public function test_the_symbol_helper_is_reachable(): void {
		$this->assertTrue(
			is_callable( array( RestAPI::class, 'default_symbol_for' ) ),
			'default_symbol_for() must be public for Settings.php to reuse it rather than copy its rule.'
		);
	}
}
