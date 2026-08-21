<?php
/**
 * The base currency's symbol reaching the panel must come from WooCommerce's
 * STATIC symbol table, never from the FILTERED `woocommerce_currency_symbol`
 * hook — and it must actually be wired to that source, not merely sit near
 * source text that mentions it, not merely agree with one hardcoded
 * currency by coincidence, and not merely agree with a small, fixed lookup
 * table built to match exactly what this file happens to test.
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
 * A version of this test still fixed the shop's base currency to EUR for
 * every case, which proved the VALUE was right but not that it was read from
 * the OPTION: `RestAPI::default_symbol_for( 'EUR' )` — the option read
 * dropped entirely — passed it. A shop on any other base currency, including
 * this plugin's own `'USD'` default, would then show the euro sign for
 * everything. Fixed by testing more than one base currency, and separately,
 * by a dedicated test that forces `get_option( 'woocommerce_currency',
 * 'USD' )`'s DEFAULT-argument branch with `delete_option()` — the one path
 * no currency-scenario test can reach, since every real WooCommerce shop
 * (and every scenario here) leaves that option set.
 *
 * EVERY EXPECTATION STILL TRACED BACK TO OUR OWN HELPER — A LOOKUP TABLE PASSED
 * -------------------------------------------------------------------------
 * Testing two currencies (EUR, GBP) closed the single-currency hole, but not
 * the general one: `baseSymbol` implemented as
 * `array( 'EUR' => '€', 'GBP' => '£' )[ $base_currency ] ?? '$'` — calling
 * NEITHER `default_symbol_for()` nor the forbidden filtered helper, never
 * touching WooCommerce's real symbol table at all — passed this file
 * unchanged. It even satisfied the self-consistency assertion, which
 * recomputed its own expectation via `RestAPI::default_symbol_for(
 * $data['baseCurrency'] )` — for exactly the two currencies under test, the
 * real value and the hardcoded one were identical, so nothing here was an
 * independent source of truth.
 *
 * Adding a third currency does not fix this: a table sized to two becomes a
 * table sized to three, and any finite example-based suite can be satisfied
 * by a finite lookup table built to match it. That is a property of
 * example-based testing itself, not a bug in a particular attempt at this
 * file. The only fix that generalises is changing WHERE the expectation
 * comes from:
 *
 * `static_table_symbol_for()` below reads `get_woocommerce_currency_symbols()`
 * — WooCommerce's OWN static table, the actual source
 * `default_symbol_for()` is required to read — directly in the test, and
 * decodes it the same way `default_symbol_for()` does
 * (`html_entity_decode( ..., ENT_QUOTES, 'UTF-8' )`, since the table holds
 * HTML entities). That decode call is the one piece of logic legitimately
 * shared with the production code — decoding an entity is not "the table",
 * it is a standard operation both sides must perform on whatever the table
 * hands back. The VALUES themselves are read fresh, never through
 * `default_symbol_for()`.
 *
 * The currency sample is then widened from two hand-picked codes to twenty,
 * fixed and named (`CURRENCY_CODES` below) rather than random or
 * "the whole table" — deterministic because this repository values
 * reproducible runs over shuffled ones, and twenty because faking this now
 * means hand-writing a duplicate of twenty entries from WooCommerce's own
 * table, and a duplicate of the table IS the table: it breaks the moment
 * WooCommerce changes an entry, which is exactly the signal this test
 * exists to raise. `html_entity_decode()` normalises named entities
 * (`&euro;`), numeric entities (`&#36;`) and already-literal UTF-8 alike, so
 * the twenty codes were chosen for being major, unambiguous world
 * currencies rather than for any particular encoding form in the table —
 * the decode call handles whichever form each entry happens to use.
 * `TRY` is deliberately excluded: its real symbol IS the Lira sign, the same
 * value `WRONG_FILTERED_SYMBOL` poisons every OTHER currency with below, so
 * including it would make the poisoned and the correct answer
 * indistinguishable for that one row.
 *
 * Cost: driving the full enqueue path once per currency adds nineteen extra
 * iterations over the previous two-currency version. Measured directly —
 * the whole integration suite's wall time did not move outside normal
 * run-to-run variance (all of this file's work is in-memory: option reads,
 * one `add_submenu_page()` call, and a script-registry read; no HTTP, no
 * filesystem, no dispatched REST request) — so twenty was kept rather than
 * trimmed.
 *
 * THE SELF-CONSISTENCY ASSERTION, HONESTLY
 * -------------------------------------------------------------------------
 * Each case below still asserts `baseSymbol === RestAPI::default_symbol_for(
 * $data['baseCurrency'] )`. What this catches: `baseSymbol` and
 * `baseCurrency` naming two DIFFERENT currencies — a real bug class Settings.php
 * could still introduce (say, by localizing `baseSymbol` for a stale value
 * read before a filter changed the option). What this CANNOT catch: whether
 * `default_symbol_for()` itself is correct, because it shares that exact
 * function with the code under test — a `default_symbol_for()` that was
 * wrong in the same way `baseSymbol` was wrong would make this specific
 * assertion agree with itself. That is what
 * `test_base_symbol_matches_the_configured_base_currency()`'s comparison
 * against `static_table_symbol_for()` — the independent read — is for.
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
	 * anywhere below (see CURRENCY_CODES: TRY is excluded specifically
	 * because its real symbol IS this constant), and not the `'$'` an
	 * unwired implementer would hardcode.
	 *
	 * @var string
	 */
	private const WRONG_FILTERED_SYMBOL = '₺';

	/**
	 * A fixed, deterministic slice of ISO 4217 codes — twenty major world
	 * currencies, not two, and not random. See the class docblock's "EVERY
	 * EXPECTATION STILL TRACED BACK TO OUR OWN HELPER" section for why the
	 * width and the fixed ordering both matter. `TRY` is excluded: it is the
	 * one code whose real symbol equals `WRONG_FILTERED_SYMBOL`.
	 *
	 * @var array<int, string>
	 */
	private const CURRENCY_CODES = array(
		'USD', 'EUR', 'GBP', 'JPY', 'CAD',
		'AUD', 'CHF', 'CNY', 'INR', 'BRL',
		'MXN', 'RUB', 'KRW', 'SEK', 'NOK',
		'DKK', 'PLN', 'ZAR', 'NZD', 'THB',
	);

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
	 * The real symbol for a currency, read directly from WooCommerce's OWN
	 * static table — never through `RestAPI::default_symbol_for()`. This is
	 * the independent oracle: see the class docblock for why computing the
	 * expectation via the function under test made this whole file blind to
	 * a hardcoded lookup table that happened to agree with it.
	 *
	 * The decode call duplicates one line of `default_symbol_for()` on
	 * purpose — decoding an HTML entity is a standard operation, not "the
	 * table" itself, and both sides legitimately have to perform it on
	 * whatever `get_woocommerce_currency_symbols()` hands back.
	 *
	 * @param string $code Currency code.
	 * @return string
	 */
	private function static_table_symbol_for( string $code ): string {
		$this->assertTrue(
			function_exists( 'get_woocommerce_currency_symbols' ),
			'get_woocommerce_currency_symbols() is unavailable; WooCommerce is not loaded in this environment.'
		);

		$symbols = get_woocommerce_currency_symbols();

		$this->assertIsArray( $symbols, 'get_woocommerce_currency_symbols() did not return an array.' );
		$this->assertArrayHasKey(
			$code,
			$symbols,
			"WooCommerce's own symbol table has no entry for {$code}; pick a different currency for CURRENCY_CODES."
		);

		return html_entity_decode( (string) $symbols[ $code ], ENT_QUOTES, 'UTF-8' );
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
	 * `default_symbol_for()` and `static_table_symbol_for()` above both
	 * actually read — has no filter hook of the same name and is entirely
	 * untouched by this.
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
	 * One row per CURRENCY_CODES entry. Only the code travels through the
	 * data provider — the expected SYMBOL is computed at test-run time via
	 * `static_table_symbol_for()`, not hardcoded here, so this provider
	 * itself can never become the fixed lookup table the round-5 attack
	 * relied on.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function base_currency_provider(): array {
		$cases = array();

		foreach ( self::CURRENCY_CODES as $code ) {
			$cases[ $code ] = array( $code );
		}

		return $cases;
	}

	/**
	 * `baseSymbol` must be the CONFIGURED base currency's REAL, independently
	 * read, static-table symbol — neither the hardcoded dollar an unwired
	 * implementer falls back to, nor whatever the filtered helper is
	 * currently poisoned to answer with, nor another currency's symbol
	 * reached through a hardcoded argument, nor a small lookup table sized
	 * to match exactly this test.
	 *
	 * @dataProvider base_currency_provider
	 *
	 * @param string $base_currency Shop base currency to configure.
	 * @return void
	 */
	public function test_base_symbol_matches_the_configured_base_currency( string $base_currency ): void {
		// Computed BEFORE anything below runs, and never through
		// RestAPI::default_symbol_for() — see the class docblock.
		$expected_symbol = $this->static_table_symbol_for( $base_currency );

		$this->assertNotSame(
			self::WRONG_FILTERED_SYMBOL,
			$expected_symbol,
			"{$base_currency}'s real static-table symbol collides with this test's poison symbol; either drop it "
				. 'from CURRENCY_CODES or change WRONG_FILTERED_SYMBOL.'
		);

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
			"baseSymbol for {$base_currency} did not match WooCommerce's own static symbol table, read independently "
				. 'of RestAPI::default_symbol_for(). It must be built on that helper - reading '
				. 'get_woocommerce_currency_symbols(), the STATIC table - never on get_woocommerce_currency_symbol() '
				. '(filtered, and poisoned by this test), never a value hardcoded in Settings.php, and never a lookup '
				. 'table sized to whichever currencies this test happens to check.'
		);

		// Self-consistency: baseSymbol and baseCurrency must never name
		// different currencies. See the class docblock ("THE SELF-CONSISTENCY
		// ASSERTION, HONESTLY") for what this does and does not prove on its
		// own - it shares default_symbol_for() with the code under test, so
		// the assertSame() above, against the independently-read table, is
		// what actually establishes correctness.
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
	 * option present and the coded default unconsulted. `'USD'` itself is
	 * this plugin's own established convention for that fallback, matching
	 * `baseCurrency`'s identical default four lines above it in
	 * Settings.php and the same literal used elsewhere in this codebase
	 * wherever `woocommerce_currency` is read defensively — not a value
	 * invented for this test.
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
