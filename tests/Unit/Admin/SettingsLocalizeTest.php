<?php
/**
 * The panel cannot draw what it was never given.
 *
 * The live preview has to render the base currency first, because the real
 * switcher always lists it first (Switcher.php:309). To render it with a
 * symbol, the panel needs the base symbol — and the localize array carried no
 * symbol for any currency, while GET /currencies filters the base row out
 * entirely. Without this value the implementer hardcodes "$".
 *
 * WHY THIS PIN CHECKS A SHAPE, NOT TWO FACTS
 * -------------------------------------------
 * The first version of this test asserted, independently, that the literal
 * string `'baseSymbol'` appears somewhere in Settings.php and that the literal
 * string `default_symbol_for(` appears somewhere in Settings.php. Both pass
 * for `'baseSymbol' => get_woocommerce_currency_symbol( ... )` sitting near an
 * unrelated comment that merely MENTIONS default_symbol_for() — which is
 * exactly the forbidden, filtered helper this pin exists to keep out, with the
 * correct helper's name only nearby, never wired to anything. The same two
 * assertions would also pass if the right helper were called with the wrong
 * argument.
 *
 * This repository has already shipped that failure mode once, for a different
 * pin: `PanelUnsavedChangesTest` originally searched raw source for the word
 * "dirty" and stayed green after the guard it was pinning was deleted, because
 * the fix's own explanatory comment used the word "dirty" in prose. The fix
 * there — repeated here — is to strip comments before matching, then assert
 * one assignment SHAPE in a single regex: that `'baseSymbol'` is assigned an
 * expression which itself contains `default_symbol_for(`, and that the value
 * handed to it is the SAME option-and-default pair `baseCurrency` reads four
 * lines above, so the two cannot drift apart without this test moving with
 * them.
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
	 * The localize array's body, from the `baseCurrency` entry up to (not
	 * including) the `wcCurrencies` entry that follows `baseSymbol`, with all
	 * comments stripped.
	 *
	 * Bounding the slice by the neighbouring keys — rather than balancing
	 * parentheses — is what keeps this robust: `default_symbol_for()`'s own
	 * argument list contains a comma, so a paren-counting approach would need
	 * to be exactly as careful as a small parser. Two literal key names do the
	 * same job in two lines.
	 *
	 * @return string
	 */
	private function localize_array_body(): string {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/Settings.php' );

		$this->assertIsString( $source, 'Settings.php must be readable.' );

		// Comments stripped before anything is searched for — see the class
		// docblock. A comment that merely mentions default_symbol_for() must
		// not be able to satisfy this pin.
		$stripped = preg_replace( '#/\*[\s\S]*?\*/#', '', $source );
		$stripped = preg_replace( '#(^|[^:])//.*$#m', '$1', (string) $stripped );
		$stripped = (string) $stripped;

		$start = strpos( $stripped, "'baseCurrency'" );
		$this->assertNotFalse(
			$start,
			'Could not find the baseCurrency entry in Settings.php. If it moved or was renamed, '
				. 'move this pin with it — do not delete it.'
		);

		$end = strpos( $stripped, "'wcCurrencies'", $start );
		$this->assertNotFalse(
			$end,
			'Could not find the wcCurrencies entry that bounds baseSymbol in Settings.php. If it '
				. 'moved or was renamed, move this pin with it — do not delete it.'
		);

		return substr( $stripped, $start, $end - $start );
	}

	/**
	 * `baseSymbol` must be ASSIGNED an expression built on default_symbol_for(),
	 * not merely share the file with a mention of it, and that expression must
	 * read the same option-and-default pair baseCurrency reads.
	 *
	 * @return void
	 */
	public function test_the_localize_array_carries_the_base_symbol(): void {
		$body = $this->localize_array_body();

		$symbol_pos = strpos( $body, "'baseSymbol'" );

		$this->assertNotFalse(
			$symbol_pos,
			'The panel has no source for the base currency symbol, so the Display preview cannot render '
				. 'the base row the way the storefront does.'
		);

		$currency_expr = substr( $body, 0, $symbol_pos );
		$symbol_expr   = substr( $body, $symbol_pos );

		// One regex, one shape: baseSymbol's own right-hand side — not the
		// file, not a neighbouring comment — must contain default_symbol_for(.
		$this->assertMatchesRegularExpression(
			"/'baseSymbol'\s*=>[\s\S]*?default_symbol_for\(/",
			$symbol_expr,
			'baseSymbol must be ASSIGNED an expression built on default_symbol_for(). The base symbol '
				. 'must come from the STATIC WooCommerce table via that helper, not from '
				. 'get_woocommerce_currency_symbol() — that helper is filtered, and this plugin hooks '
				. 'it at priority 100, so it answers with whatever the page is showing.'
		);

		$this->assertDoesNotMatchRegularExpression(
			"/'baseSymbol'\s*=>[\s\S]*?get_woocommerce_currency_symbol\(/",
			$symbol_expr,
			'baseSymbol calls get_woocommerce_currency_symbol() — the filtered helper this pin exists '
				. 'to keep out. A shop running another currency plugin has already seeded a currency '
				. 'with the wrong sign this way, permanently, with no field to correct it.'
		);

		// The argument baseSymbol hands to default_symbol_for() must be the
		// same option-and-default pair baseCurrency reads, so a future edit to
		// one cannot silently leave the other reading a different currency.
		$option_call = "/get_option\(\s*(['\"])woocommerce_currency\\1\s*,\s*(['\"])USD\\2\s*\)/";

		$this->assertMatchesRegularExpression(
			$option_call,
			$currency_expr,
			'Could not find get_option( \'woocommerce_currency\', \'USD\' ) in the baseCurrency entry — '
				. 'this pin no longer knows what "the same option and default" means. Move it with the code.'
		);
		$this->assertMatchesRegularExpression(
			$option_call,
			$symbol_expr,
			'baseSymbol must read the option with the exact same call — get_option( \'woocommerce_currency\', '
				. '\'USD\' ) — that baseCurrency reads four lines above it, so the two can never disagree '
				. 'about which currency, or which fallback, is "the base".'
		);
	}

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
