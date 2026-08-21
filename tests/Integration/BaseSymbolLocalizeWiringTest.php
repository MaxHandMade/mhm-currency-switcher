<?php
/**
 * The base currency's symbol reaching the panel must come from WooCommerce's
 * STATIC symbol table, never from the FILTERED `woocommerce_currency_symbol`
 * hook — and it must actually be wired to that source, not merely sit near
 * source text that mentions it, and not merely agree with one hardcoded
 * currency by coincidence.
 *
 * Implements spec §5.1 / Task 7. The live preview renders the base currency
 * first, because the real switcher always lists it first (Switcher.php:309).
 * `GET /currencies` filters the base row out entirely — it is not a
 * conversion target — so `Settings::enqueue_assets()`'s `baseSymbol` entry is
 * the panel's only source for that glyph. A shop running another currency
 * plugin has already seeded a currency with the wrong sign this way,
 * permanently, with no field to correct it — the incident
 * `RestAPI::default_symbol_for()`'s own docblock names. Without `baseSymbol`
 * carrying the RIGHT value, the implementer hardcodes `"$"`.
 *
 * WHY THIS IS A BEHAVIOURAL TEST, NOT A SOURCE-READING PIN
 * -------------------------------------------------------------------------
 * This started as a pair of source-text assertions (`assertStringContainsString`
 * for `'baseSymbol'` and for `default_symbol_for(`), then a single regex
 * requiring the second inside the first's own assignment, then a window
 * narrowed to stop at the next array entry's `=>`. Each round closed a real
 * hole and opened another:
 *
 * - Two independent `assertStringContainsString()` calls passed for
 *   `'baseSymbol' => get_woocommerce_currency_symbol( ... )` sitting near an
 *   unrelated comment that only MENTIONED `default_symbol_for(`.
 * - A single "is it assigned" regex, bounded only by the next KEY LITERAL
 *   (`'wcCurrencies'`), passed for `'baseSymbol' => '$',` followed by an
 *   entirely different entry that called `default_symbol_for(...)` — the
 *   lazy match crossed the comma into a sibling's value.
 * - Narrowing the window to stop at the next `=>` closed that hole — and was
 *   then defeated anyway:
 *   `'baseSymbol' => ( false ? "unused-marker default_symbol_for( get_option( 'woocommerce_currency', 'USD' ) )" : '$' )`
 *   is a hardcoded `'$'` with the required text sitting in a dead string
 *   literal, entirely inside `baseSymbol`'s own, correctly bounded, window.
 *   The same regex also went RED on two legitimate refactors it had no
 *   business rejecting: moving `baseSymbol` after `wcCurrencies`, and
 *   extracting the call into a local variable in the style this very file
 *   already uses for `$wc_currencies`.
 *
 * A regex over source text cannot tell a function call from a string literal
 * without parsing PHP, and that failure generalises to any pattern written
 * this way — there is no fourth regex that fixes it. This is PHP, not JSX:
 * unlike the sibling source-reading pins in this suite (no React test
 * runner exists, so `PanelUnsavedChangesTest` and
 * `SettingsDefaultParityTest` have no alternative), the actual runtime
 * behaviour here is directly executable inside PHPUnit. So this test runs
 * the real enqueue path — `Settings::add_menu_page()` then
 * `Settings::enqueue_assets()` — with the filtered helper poisoned to answer
 * with the WRONG symbol, then reads the localized JS back out of
 * `wp_scripts()` the way the browser would receive it. It cannot be fooled
 * by dead code, and it does not care whether the real call sits inline, in a
 * different key order, or behind a local variable.
 *
 * ONE CURRENCY IS NOT ENOUGH — THE ARGUMENT CAN BE HARDCODED TOO
 * -------------------------------------------------------------------------
 * The behavioural test above still fixed the shop's base currency to EUR for
 * every case, which proved the VALUE was right but not that it was read from
 * the OPTION: `RestAPI::default_symbol_for( 'EUR' )` — the option read
 * dropped entirely — passed it. A shop on any other base currency, including
 * this plugin's own `'USD'` default, would then show the euro sign for
 * everything.
 *
 * An earlier round's regex caught this by requiring `baseSymbol` to read the
 * identical `get_option( 'woocommerce_currency', 'USD' )` call `baseCurrency`
 * reads four lines above it — a real property, worth keeping, but the regex
 * around it was the same kind of brittle/defeatable pattern this file has
 * already moved away from twice. Replaced here with two behavioural
 * mechanisms instead, both cheap enough to keep together:
 *
 * (a) Two base currencies, EUR and GBP, via a data provider. A hardcoded
 *     argument can satisfy one; it cannot satisfy both at once, so this is
 *     what actually catches `default_symbol_for( 'EUR' )` with the read
 *     dropped — proved by mutation, see
 *     `test_base_symbol_matches_the_configured_base_currency()`.
 * (b) A self-consistency assertion tying `baseSymbol` to whatever
 *     `baseCurrency` says, permanently: `baseSymbol` must equal
 *     `RestAPI::default_symbol_for( $data['baseCurrency'] )`. This does not
 *     independently verify the SYMBOL is correct — it uses the production
 *     function to compute its own expectation, so it would agree with a
 *     wrong `default_symbol_for()` just as readily as a right one — but it
 *     does verify the two localized values can never silently name
 *     different currencies, which (a) does not check on its own.
 *
 * A THIRD FAILURE (a) AND (b) CANNOT SEE: THE FALLBACK DEFAULT ITSELF
 * -------------------------------------------------------------------------
 * `get_option( 'woocommerce_currency', 'USD' )`'s `'USD'` only matters when
 * the option row does not exist at all — WooCommerce's own installer sets it
 * on every real shop, and every scenario in this file sets it explicitly
 * too, so `get_option( 'woocommerce_currency', 'EUR' )` (right call, wrong
 * DEFAULT) is behaviourally identical to the correct code in (a) and (b):
 * the coded default is never actually consulted. Closing that requires
 * forcing the option-absent path on purpose, which
 * `test_base_symbol_falls_back_to_usd_when_the_option_is_absent()` does with
 * `delete_option()`. WP_UnitTestCase wraps each test in its own rolled-back
 * transaction, so this cannot leak the option's absence into another test.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use MhmCurrencySwitcher\Admin\RestAPI;
use MhmCurrencySwitcher\Admin\Settings;
use ReflectionProperty;

/**
 * Class BaseSymbolLocalizeWiringTest
 */
class BaseSymbolLocalizeWiringTest extends MhmcsIntegrationTestCase {

	/**
	 * The symbol a shop running another currency plugin was actually shown:
	 * the Lira-sign incident `RestAPI::default_symbol_for()`'s own docblock
	 * names. Standing in here for "whatever the filtered helper currently
	 * answers with" — deliberately not any currency's real symbol used
	 * anywhere below, and not the `'$'` an unwired implementer would
	 * hardcode.
	 *
	 * @var string
	 */
	private const WRONG_FILTERED_SYMBOL = '₺';

	/**
	 * Drop the poisoning filter and the script registry it fed, so neither
	 * survives into the next test.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'woocommerce_currency_symbol' );
		$GLOBALS['wp_scripts'] = null;
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Make `get_woocommerce_currency_symbol()` — the FILTERED helper this
	 * plugin must never read `baseSymbol` from — answer every currency with
	 * the wrong sign, the way another currency plugin did on the live shop
	 * `default_symbol_for()`'s docblock describes.
	 *
	 * Priority `PHP_INT_MAX` is what makes this decisive regardless of
	 * whether this plugin's own `FormatFilter` (priority 100) also has a
	 * hook registered on the same tag: this filter runs last and ignores
	 * whatever it is handed, so its return value is always final.
	 *
	 * `get_woocommerce_currency_symbols()` — the STATIC, plural table
	 * `default_symbol_for()` actually reads — has no filter hook of the same
	 * name and is entirely untouched by this.
	 *
	 * @return void
	 */
	private function poison_the_filtered_helper(): void {
		add_filter(
			'woocommerce_currency_symbol',
			static function () {
				return self::WRONG_FILTERED_SYMBOL;
			},
			PHP_INT_MAX
		);
	}

	/**
	 * Register the submenu page exactly as `admin_menu` would, then run the
	 * real enqueue path, then read the localized data straight back out of
	 * WordPress's own script registry — not off the `Settings` object, which
	 * has no getter for it and should not grow one only for a test.
	 *
	 * @return array<string, mixed> The decoded `mhmCsAdmin` object.
	 */
	private function localized_admin_data(): array {
		$GLOBALS['wp_scripts'] = null;

		// `add_submenu_page()` calls current_user_can( 'manage_woocommerce' )
		// internally and returns false — silently, no exception — when the
		// current user lacks it. The default PHPUnit user is 0 (anonymous),
		// so without this the hook suffix is always '' and the assertion
		// below is what actually catches it, not a symptom of Settings.php.
		wp_set_current_user( self::$admin_id );

		$settings = new Settings();
		$settings->add_menu_page();

		// `add_submenu_page()` writes the real hook suffix into a private
		// property; reflection reaches it the same way
		// `MhmcsIntegrationTestCase` reaches other private plugin state —
		// there is no production reason for `Settings` to expose a getter
		// purely so a test can drive its own enqueue callback.
		$hook_property = new ReflectionProperty( Settings::class, 'hook_suffix' );
		$hook_property->setAccessible( true );
		$hook_suffix = $hook_property->getValue( $settings );

		$this->assertIsString( $hook_suffix );
		$this->assertNotSame(
			'',
			$hook_suffix,
			'add_menu_page() did not register a hook suffix; enqueue_assets() would never fire on the real admin page.'
		);

		$settings->enqueue_assets( $hook_suffix );

		wp_set_current_user( 0 );

		$raw = wp_scripts()->get_data( 'mhm-cs-admin', 'data' );

		$this->assertIsString( $raw, 'wp_localize_script() attached no data to the mhm-cs-admin handle.' );

		$start = strpos( $raw, '{' );

		$this->assertNotFalse( $start, 'Could not find the JSON object wp_localize_script() emitted for mhm-cs-admin.' );

		$decoded = json_decode( rtrim( trim( substr( $raw, $start ) ), ';' ), true );

		$this->assertIsArray( $decoded, 'Could not decode the localized mhmCsAdmin data as JSON.' );

		return $decoded;
	}

	/**
	 * Base currency => that currency's real, static-table symbol.
	 *
	 * Two rows, not one: `RestAPI::default_symbol_for( 'EUR' )` with the
	 * `get_option()` read dropped entirely returns the euro sign regardless
	 * of the shop's actual base currency, so a single-currency scenario
	 * cannot tell "reads the option" from "always answers as if the base
	 * were EUR". A hardcoded argument can satisfy one row here; it cannot
	 * satisfy both.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function base_currency_provider(): array {
		return array(
			'EUR base' => array( 'EUR', '€' ),
			'GBP base' => array( 'GBP', '£' ),
		);
	}

	/**
	 * `baseSymbol` must be the CONFIGURED base currency's static-table
	 * symbol — neither the hardcoded dollar an unwired implementer falls
	 * back to, nor whatever the filtered helper is currently poisoned to
	 * answer with, nor another currency's symbol reached through a
	 * hardcoded argument.
	 *
	 * @dataProvider base_currency_provider
	 *
	 * @param string $base_currency   Shop base currency to configure.
	 * @param string $expected_symbol That currency's real static-table symbol.
	 * @return void
	 */
	public function test_base_symbol_matches_the_configured_base_currency( string $base_currency, string $expected_symbol ): void {
		update_option( 'woocommerce_currency', $base_currency );
		$this->poison_the_filtered_helper();

		// Sanity check on the poison itself: if this does not hold, the
		// filter never took effect and the test below would pass no matter
		// what baseSymbol actually contained.
		$this->assertSame(
			self::WRONG_FILTERED_SYMBOL,
			get_woocommerce_currency_symbol( $base_currency ),
			'The woocommerce_currency_symbol filter did not take effect, so this test cannot tell the static table apart from the filtered one.'
		);

		$data = $this->localized_admin_data();

		$this->assertArrayHasKey(
			'baseSymbol',
			$data,
			'The panel has no source for the base currency symbol, so the Display preview cannot render the base row the way the storefront does.'
		);
		$this->assertSame(
			$base_currency,
			$data['baseCurrency'] ?? null,
			'baseCurrency did not reflect the option this test just set - the harness itself is not doing what it claims.'
		);

		$this->assertSame(
			$expected_symbol,
			$data['baseSymbol'],
			'baseSymbol was not this currency\'s static table symbol. It must be built on RestAPI::default_symbol_for() '
				. 'called with the CONFIGURED base currency - reading get_woocommerce_currency_symbols(), the STATIC '
				. 'table - never on get_woocommerce_currency_symbol(), which this plugin\'s own FormatFilter hooks at '
				. 'priority 100 and which this test additionally poisoned, never a value hardcoded in Settings.php, and '
				. 'never another currency\'s symbol reached through a hardcoded argument.'
		);

		// (b) Self-consistency: whatever baseCurrency says, baseSymbol must
		// agree with it. This alone would not catch a wrong
		// default_symbol_for() - it uses that same function to build its own
		// expectation - but it does mean the two localized values can never
		// silently name different currencies.
		$this->assertSame(
			RestAPI::default_symbol_for( $data['baseCurrency'] ),
			$data['baseSymbol'],
			'baseSymbol and baseCurrency disagree about which currency is "the base" - Settings.php must derive both '
				. 'from the same option read.'
		);
	}

	/**
	 * The `'USD'` in `get_option( 'woocommerce_currency', 'USD' )` is only
	 * reachable when the option row does not exist — forced here with
	 * `delete_option()`, since every other scenario in this file (and every
	 * real WooCommerce shop, which sets this option on install) leaves the
	 * option present and the coded default unconsulted.
	 *
	 * @return void
	 */
	public function test_base_symbol_falls_back_to_usd_when_the_option_is_absent(): void {
		delete_option( 'woocommerce_currency' );
		$this->poison_the_filtered_helper();

		$data = $this->localized_admin_data();

		$this->assertSame(
			'USD',
			$data['baseCurrency'] ?? null,
			'baseCurrency did not fall back to USD with the option absent - this test cannot exercise the default '
				. 'argument if the harness itself does not reach it.'
		);
		$this->assertSame(
			'$',
			$data['baseSymbol'],
			'baseSymbol did not fall back to the dollar sign when woocommerce_currency was unset. If baseSymbol\'s '
				. 'own get_option() call defaults to anything other than \'USD\', a shop where this option is somehow '
				. 'absent shows the wrong currency\'s symbol instead of matching baseCurrency\'s own fallback.'
		);
	}
}
