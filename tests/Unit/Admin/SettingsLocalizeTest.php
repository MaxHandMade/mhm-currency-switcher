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
	public function test_the_localize_array_carries_the_base_symbol(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/Settings.php' );

		$this->assertIsString( $source, 'Settings.php must be readable.' );

		$this->assertStringContainsString(
			"'baseSymbol'",
			$source,
			'The panel has no source for the base currency symbol, so the Display preview cannot render '
				. 'the base row the way the storefront does.'
		);
		$this->assertStringContainsString(
			'default_symbol_for(',
			$source,
			'The base symbol must come from the STATIC WooCommerce table via default_symbol_for(), not '
				. 'from get_woocommerce_currency_symbol() — that helper is filtered, and this plugin '
				. 'hooks it at priority 100, so it answers with whatever the page is showing.'
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
