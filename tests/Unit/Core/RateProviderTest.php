<?php
/**
 * Unit tests for RateProvider.
 *
 * Tests the pure parsing logic that does not require WordPress.
 * HTTP and transient calls are WordPress-dependent and are not
 * exercised in these unit tests.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use MhmCurrencySwitcher\Core\RateProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class RateProviderTest
 *
 * Pure unit tests — no WordPress dependency.
 * Tests the static parsing helpers and constant format.
 *
 * @covers \MhmCurrencySwitcher\Core\RateProvider
 */
class RateProviderTest extends TestCase {

	/**
	 * Test parsing a valid ExchangeRate-API response.
	 *
	 * Given: {"rates": {"USD": 0.029, "EUR": 0.025}}
	 * Expect: ['USD' => 0.029, 'EUR' => 0.025]
	 *
	 * @return void
	 */
	/**
	 * A stub HTTP 200 carrying the given JSON body.
	 *
	 * @param array<string, mixed> $body Decoded body to serve.
	 * @return array<string, mixed>
	 */
	private function http_ok( array $body ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( $body ),
		);
	}

	/**
	 * A stub HTTP 200 carrying a raw (non-JSON) body -- the ECB feed is
	 * text/xml, so the JSON-shaped http_ok() above cannot serve it.
	 *
	 * @param string $body Raw body to serve.
	 * @return array<string, mixed>
	 */
	private function http_ok_raw( string $body ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => $body,
		);
	}

	/**
	 * Chain order: the primary succeeding means the fallback is never
	 * reached at all.
	 *
	 * @return void
	 */
	public function test_the_fallback_is_not_called_when_the_primary_succeeds(): void {
		$GLOBALS['__mhmcs_test_http_get_urls'] = array();
		$GLOBALS['__mhmcs_test_http_get_map']  = array(
			'exchangerate-api' => $this->http_ok( array( 'rates' => array( 'EUR' => 0.92, 'TRY' => 34.5 ) ) ),
		);

		$provider = ( new \ReflectionClass( RateProvider::class ) )->newInstanceWithoutConstructor();
		$rates    = $provider->fetch_rates( 'USD', true );

		unset( $GLOBALS['__mhmcs_test_http_get_map'] );

		$this->assertEqualsWithDelta( 34.5, $rates['TRY'] ?? 0.0, 0.0001, 'The primary answered.' );
		$this->assertCount(
			1,
			$GLOBALS['__mhmcs_test_http_get_urls'],
			'The chain stopped at the primary -- no request went out to the fallback.'
		);
		$this->assertStringContainsString( 'exchangerate-api', $GLOBALS['__mhmcs_test_http_get_urls'][0] );
	}

	/**
	 * 🔴 Regression: `rawurlencode()` was lost during the ECB migration.
	 *
	 * `$base` comes from `CurrencyStore::get_base_currency()`, which reads
	 * the `woocommerce_currency` option unvalidated. That makes this
	 * admin-writable rather than an anonymous vector, but the 2.0.0 code
	 * encoded the base before building the URL and the value now goes in raw.
	 * A base containing a path separator proves the difference: encoded, it
	 * cannot escape the `/latest/` path segment it is appended to.
	 *
	 * @return void
	 */
	public function test_the_primary_request_url_encodes_the_base_currency(): void {
		$GLOBALS['__mhmcs_test_http_get_urls'] = array();
		$GLOBALS['__mhmcs_test_http_get_map']  = array(
			'exchangerate-api' => $this->http_ok( array( 'rates' => array( 'EUR' => 0.92 ) ) ),
		);

		$provider = ( new \ReflectionClass( RateProvider::class ) )->newInstanceWithoutConstructor();
		$provider->fetch_rates( 'usd/../evil', true );

		unset( $GLOBALS['__mhmcs_test_http_get_map'] );

		$url = $GLOBALS['__mhmcs_test_http_get_urls'][0];

		$this->assertStringContainsString(
			rawurlencode( 'USD/../EVIL' ),
			$url,
			'The (uppercased) base currency must be rawurlencode()d into the request URL.'
		);

		$this->assertStringNotContainsString(
			'/latest/USD/../EVIL',
			$url,
			'An un-encoded base currency must not appear verbatim in the URL path.'
		);
	}

	/**
	 * 🔴 Negative control for the destructive sweep -- proves the deletion by
	 * behaviour, not merely by reading the diff. If a revert, a bad merge, or
	 * a stray copy-paste ever reintroduces a call to either removed host,
	 * this fails.
	 *
	 * @return void
	 */
	public function test_no_request_ever_goes_to_the_removed_hosts(): void {
		$GLOBALS['__mhmcs_test_http_get_urls'] = array();
		$GLOBALS['__mhmcs_test_http_get_map']  = array(); // Nothing answers; every source in the chain is tried.

		$provider = ( new \ReflectionClass( RateProvider::class ) )->newInstanceWithoutConstructor();
		$provider->fetch_rates( 'USD', true );

		$log = implode( ' | ', $GLOBALS['__mhmcs_test_http_get_urls'] );
		unset( $GLOBALS['__mhmcs_test_http_get_map'] );

		$this->assertStringNotContainsString( 'currency-api.pages.dev', $log );
		$this->assertStringNotContainsString( 'frankfurter.dev', $log );
	}

	/**
	 * The filter is honoured, not merely called: the request must actually
	 * reach the URL the filter returned, and the source argument it receives
	 * must be 'ecb' -- the only fallback left in the chain.
	 *
	 * @return void
	 */
	public function test_the_filter_receives_the_ecb_source_and_its_url_is_used(): void {
		$seen_source = null;

		$GLOBALS['__mhmcs_test_filters']['mhmcs_fallback_rates_url'] = static function ( $url, $base, $source ) use ( &$seen_source ) {
			$seen_source = $source;

			return 'https://mirror.test/eurofxref-daily.xml';
		};

		$GLOBALS['__mhmcs_test_http_get_urls'] = array();
		$GLOBALS['__mhmcs_test_http_get_map']  = array(); // Primary fails, forcing the fallback.

		$provider = ( new \ReflectionClass( RateProvider::class ) )->newInstanceWithoutConstructor();
		$provider->fetch_rates( 'USD', true );

		unset( $GLOBALS['__mhmcs_test_filters']['mhmcs_fallback_rates_url'], $GLOBALS['__mhmcs_test_http_get_map'] );

		$this->assertSame( 'ecb', $seen_source, 'The filter is told which source it is filtering.' );
		$this->assertContains(
			'https://mirror.test/eurofxref-daily.xml',
			$GLOBALS['__mhmcs_test_http_get_urls'],
			'The request actually went to the filtered URL, not merely that the filter fired.'
		);
	}

	/**
	 * 🔴 `fetch_rates()` does not cache emptiness. A failed ECB fetch --
	 * nothing answers, anywhere in the chain -- must leave the transient
	 * store untouched, exactly as an empty answer from the old fallbacks
	 * did before them.
	 *
	 * @return void
	 */
	public function test_an_empty_ecb_answer_is_not_cached(): void {
		$GLOBALS['__mhmcs_test_transients']    = array();
		$GLOBALS['__mhmcs_test_http_get_urls'] = array();
		$GLOBALS['__mhmcs_test_http_get_map']  = array(); // Nothing answers.

		$provider = ( new \ReflectionClass( RateProvider::class ) )->newInstanceWithoutConstructor();
		$rates    = $provider->fetch_rates( 'USD', true );

		unset( $GLOBALS['__mhmcs_test_http_get_map'] );

		$this->assertSame( array(), $rates );
		$this->assertArrayNotHasKey(
			RateProvider::TRANSIENT_KEY_PREFIX . 'USD',
			$GLOBALS['__mhmcs_test_transients'],
			'An empty result must never be written to the transient cache.'
		);

		unset( $GLOBALS['__mhmcs_test_transients'] );
	}

	/**
	 * End-to-end: the primary fails, the chain reaches ECB, and what comes
	 * back is base-relative -- not the raw EUR-based feed. Proves the wiring
	 * between fetch_from_ecb() and cross_rates(), not just each in isolation.
	 *
	 * Values are read from tests/fixtures/ecb-eurofxref-daily.xml, the same
	 * fixture the parser tests use.
	 *
	 * @return void
	 */
	public function test_the_chain_reaches_ecb_and_returns_base_relative_rates(): void {
		$fixture = (string) file_get_contents( __DIR__ . '/../../fixtures/ecb-eurofxref-daily.xml' );

		$GLOBALS['__mhmcs_test_http_get_urls'] = array();
		$GLOBALS['__mhmcs_test_http_get_map']  = array(
			'ecb.europa.eu' => $this->http_ok_raw( $fixture ),
		);

		$provider = ( new \ReflectionClass( RateProvider::class ) )->newInstanceWithoutConstructor();
		$rates    = $provider->fetch_rates( 'USD', true );

		unset( $GLOBALS['__mhmcs_test_http_get_map'] );

		$this->assertEqualsWithDelta( 56.0483 / 1.1645, $rates['TRY'], 0.0001 );
		$this->assertEqualsWithDelta( 1 / 1.1645, $rates['EUR'], 0.0001 );
		$this->assertArrayNotHasKey( 'USD', $rates, 'The base is not a conversion target.' );
	}

	/**
	 * Call the private fallback fetch and report every URL it asked for.
	 *
	 * @param string $base Base currency code.
	 * @return array<int, string> Requested URLs, in order.
	 */
	private function fallback_urls_requested( string $base = 'USD' ): array {
		$GLOBALS['__mhmcs_test_http_get_urls'] = array();

		$provider = ( new \ReflectionClass( RateProvider::class ) )->newInstanceWithoutConstructor();
		$method   = new \ReflectionMethod( RateProvider::class, 'fetch_from_ecb' );
		$method->setAccessible( true );
		$method->invoke( $provider, $base );

		return $GLOBALS['__mhmcs_test_http_get_urls'];
	}

	/**
	 * Guard, and the reason the filter below exists.
	 *
	 * Pins the shipped default so the filter test cannot pass by accident, and
	 * so the host is a deliberate, visible choice rather than a literal buried
	 * mid-method.
	 *
	 * @return void
	 */
	public function test_the_fallback_asks_the_shipped_host_by_default(): void {
		$urls = $this->fallback_urls_requested( 'USD' );

		$this->assertCount( 1, $urls, 'Guard: the recorder saw exactly the one fetch this method makes.' );
		$this->assertStringContainsString(
			'www.ecb.europa.eu',
			$urls[0],
			'The shipped fallback host.'
		);
	}

	/**
	 * A shop that cannot reach the fallback host must be able to move it.
	 *
	 * @return void
	 */
	public function test_the_fallback_url_can_be_redirected_by_a_filter(): void {
		$GLOBALS['__mhmcs_test_filters']['mhmcs_fallback_rates_url'] = static function ( $url, $base ) {
			return 'https://rates.example.test/' . strtolower( $base ) . '.xml';
		};

		$urls = $this->fallback_urls_requested( 'EUR' );

		unset( $GLOBALS['__mhmcs_test_filters']['mhmcs_fallback_rates_url'] );

		$this->assertCount( 1, $urls );
		$this->assertSame(
			'https://rates.example.test/eur.xml',
			$urls[0],
			'The filter receives the base currency too, so one callback can serve every base.'
		);
	}

	public function test_parse_exchangerate_api_response(): void {
		$body = array(
			'rates' => array(
				'USD' => 0.029,
				'EUR' => 0.025,
				'GBP' => 0.022,
			),
		);

		$result = RateProvider::parse_exchangerate_response( $body );

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result );
		$this->assertEqualsWithDelta( 0.029, $result['USD'], 0.0001 );
		$this->assertEqualsWithDelta( 0.025, $result['EUR'], 0.0001 );
		$this->assertEqualsWithDelta( 0.022, $result['GBP'], 0.0001 );
	}

	/**
	 * Test that invalid JSON structure returns an empty array for ExchangeRate-API.
	 *
	 * @return void
	 */
	public function test_parse_exchangerate_invalid_returns_empty(): void {
		// Missing 'rates' key.
		$result = RateProvider::parse_exchangerate_response( array( 'foo' => 'bar' ) );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test that non-numeric values are skipped during ExchangeRate-API parsing.
	 *
	 * @return void
	 */
	public function test_parse_exchangerate_skips_non_numeric(): void {
		$body = array(
			'rates' => array(
				'USD' => 0.029,
				'EUR' => 'invalid',
				'GBP' => null,
			),
		);

		$result = RateProvider::parse_exchangerate_response( $body );

		$this->assertCount( 1, $result );
		$this->assertArrayHasKey( 'USD', $result );
		$this->assertArrayNotHasKey( 'EUR', $result );
		$this->assertArrayNotHasKey( 'GBP', $result );
	}

	/**
	 * Test that a known currency can be looked up from a parsed rates array.
	 *
	 * Simulates the same logic as fetch_single_rate(): parse the response,
	 * then look up a specific currency key.
	 *
	 * @return void
	 */
	public function test_fetch_single_rate_from_array(): void {
		$body = array(
			'rates' => array(
				'USD' => 0.029,
				'EUR' => 0.025,
			),
		);

		$rates  = RateProvider::parse_exchangerate_response( $body );
		$target = strtoupper( 'USD' );
		$result = isset( $rates[ $target ] ) ? (float) $rates[ $target ] : null;

		$this->assertNotNull( $result );
		$this->assertEqualsWithDelta( 0.029, $result, 0.0001 );
	}

	/**
	 * Test that an unknown currency returns null from a parsed rates array.
	 *
	 * Simulates the same logic as fetch_single_rate() for a missing key.
	 *
	 * @return void
	 */
	public function test_fetch_single_rate_unknown_returns_null(): void {
		$body = array(
			'rates' => array(
				'USD' => 0.029,
				'EUR' => 0.025,
			),
		);

		$rates  = RateProvider::parse_exchangerate_response( $body );
		$target = strtoupper( 'XYZ' );
		$result = isset( $rates[ $target ] ) ? (float) $rates[ $target ] : null;

		$this->assertNull( $result );
	}

	/**
	 * Test that the transient key follows the expected format.
	 *
	 * @return void
	 */
	public function test_transient_key_format(): void {
		$key = RateProvider::TRANSIENT_KEY_PREFIX . 'TRY';

		$this->assertSame( 'mhmcs_rates_TRY', $key );
	}

	/**
	 * Test that the transient expiry constant is one day (86400 seconds).
	 *
	 * @return void
	 */
	public function test_transient_expiry_is_one_day(): void {
		$this->assertSame( 86400, RateProvider::TRANSIENT_EXPIRY );
	}

	/**
	 * Test that an empty rates object returns an empty array.
	 *
	 * @return void
	 */
	public function test_parse_exchangerate_empty_rates(): void {
		$body = array( 'rates' => array() );

		$result = RateProvider::parse_exchangerate_response( $body );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * A manual rate survives a sync.
	 *
	 * 🔴 The defect this locks was silent loss of the shop owner's own data.
	 * All three sync paths overwrote `rate.value` for every currency the API
	 * answered for, without ever asking `rate.type`. The admin UI disables the
	 * rate input in manual mode, so the owner is told the number is theirs to
	 * keep — and then the next cron tick replaced it.
	 *
	 * @return void
	 */
	public function test_apply_rates_leaves_a_manual_rate_untouched(): void {
		$currencies = array(
			array(
				'code' => 'EUR',
				'rate' => array(
					'type'  => 'manual',
					'value' => 0.80,
				),
			),
		);

		$result = RateProvider::apply_rates( $currencies, array( 'EUR' => 0.92 ) );

		$this->assertEqualsWithDelta( 0.80, $result['currencies'][0]['rate']['value'], 0.0001 );
		$this->assertSame( 0, $result['updated'] );
	}

	/**
	 * An automatic rate takes the fetched value.
	 *
	 * @return void
	 */
	public function test_apply_rates_updates_an_auto_rate(): void {
		$currencies = array(
			array(
				'code' => 'EUR',
				'rate' => array(
					'type'  => 'auto',
					'value' => 0.80,
				),
			),
		);

		$result = RateProvider::apply_rates( $currencies, array( 'EUR' => 0.92 ) );

		$this->assertEqualsWithDelta( 0.92, $result['currencies'][0]['rate']['value'], 0.0001 );
		$this->assertSame( 1, $result['updated'] );
	}

	/**
	 * A row this call actually rewrites is stamped with the moment it did so.
	 *
	 * This is the datum the panel's per-row freshness text reads — the global
	 * `LAST_SYNC_OPTION` can only describe the batch, not any one row within
	 * it, which is exactly the gap that let a manual-to-auto row wear a
	 * timestamp a sync never produced.
	 *
	 * @return void
	 */
	public function test_apply_rates_stamps_updated_at_on_a_rewritten_row(): void {
		$before = time();

		$currencies = array(
			array(
				'code' => 'EUR',
				'rate' => array(
					'type'  => 'auto',
					'value' => 0.80,
				),
			),
		);

		$result = RateProvider::apply_rates( $currencies, array( 'EUR' => 0.92 ) );
		$after  = time();

		$stamp = $result['currencies'][0]['rate']['updated_at'];

		$this->assertIsInt( $stamp );
		$this->assertGreaterThanOrEqual( $before, $stamp );
		$this->assertLessThanOrEqual( $after, $stamp );
	}

	/**
	 * A manual row is skipped entirely, so it must not gain an `updated_at`
	 * either — that field means "a sync produced this row's value", and this
	 * call never touched it. Stamping it anyway would be the same lie in a
	 * new field: a hand-typed number wearing proof of a sync that skipped it.
	 *
	 * @return void
	 */
	public function test_apply_rates_does_not_stamp_a_manual_row(): void {
		$currencies = array(
			array(
				'code' => 'EUR',
				'rate' => array(
					'type'  => 'manual',
					'value' => 0.80,
				),
			),
		);

		$result = RateProvider::apply_rates( $currencies, array( 'EUR' => 0.92 ) );

		$this->assertArrayNotHasKey( 'updated_at', $result['currencies'][0]['rate'] );
	}

	/**
	 * A currency with no stated type is automatic.
	 *
	 * This is the sanitiser's default (`'auto'` when the key is absent), and
	 * defaulting the other way here would freeze every currency saved before
	 * the type existed.
	 *
	 * @return void
	 */
	public function test_apply_rates_treats_a_missing_type_as_auto(): void {
		$currencies = array(
			array(
				'code' => 'TRY',
				'rate' => array( 'value' => 30.0 ),
			),
		);

		$result = RateProvider::apply_rates( $currencies, array( 'TRY' => 34.5 ) );

		$this->assertEqualsWithDelta( 34.5, $result['currencies'][0]['rate']['value'], 0.0001 );
		$this->assertSame( 1, $result['updated'] );
	}

	/**
	 * Currencies the API did not answer for are passed through untouched, and
	 * a mixed list keeps its order.
	 *
	 * @return void
	 */
	public function test_apply_rates_passes_through_unquoted_currencies(): void {
		$currencies = array(
			array(
				'code' => 'EUR',
				'rate' => array(
					'type'  => 'manual',
					'value' => 0.80,
				),
			),
			array(
				'code' => 'GBP',
				'rate' => array(
					'type'  => 'auto',
					'value' => 0.75,
				),
			),
			array(
				'code' => 'JPY',
				'rate' => array(
					'type'  => 'auto',
					'value' => 150.0,
				),
			),
		);

		$result = RateProvider::apply_rates(
			$currencies,
			array(
				'EUR' => 0.92,
				'GBP' => 0.79,
			)
		);

		$this->assertSame( 'EUR', $result['currencies'][0]['code'] );
		$this->assertSame( 'GBP', $result['currencies'][1]['code'] );
		$this->assertSame( 'JPY', $result['currencies'][2]['code'] );

		$this->assertEqualsWithDelta( 0.80, $result['currencies'][0]['rate']['value'], 0.0001 );
		$this->assertEqualsWithDelta( 0.79, $result['currencies'][1]['rate']['value'], 0.0001 );
		$this->assertEqualsWithDelta( 150.0, $result['currencies'][2]['rate']['value'], 0.0001 );

		$this->assertSame( 1, $result['updated'] );
	}

	/**
	 * A currency without a code is left alone rather than dropped.
	 *
	 * @return void
	 */
	public function test_apply_rates_keeps_a_currency_without_a_code(): void {
		$currencies = array(
			array( 'rate' => array( 'value' => 1.0 ) ),
		);

		$result = RateProvider::apply_rates( $currencies, array( 'EUR' => 0.92 ) );

		$this->assertCount( 1, $result['currencies'] );
		$this->assertSame( 0, $result['updated'] );
	}

	/**
	 * The ECB daily feed wraps its Cube rows in a default namespace
	 * (`http://www.ecb.int/vocabulary/2002-08-01/eurofxref`). A plain
	 * `$xml->Cube` walk returns nothing for a namespaced document -- no
	 * error, just an empty result that reads exactly like "no rates
	 * today". This test proves the namespace-aware walk actually reaches
	 * real rows, not just that parsing did not throw.
	 *
	 * Values are read from tests/fixtures/ecb-eurofxref-daily.xml, pulled
	 * fresh from the live ECB feed on 2026-08-28 -- see the comment at the
	 * top of that file. Two known code=>value pairs are asserted, not a
	 * count: "29 rates" passes with 29 wrong numbers.
	 *
	 * @return void
	 */
	public function test_parse_ecb_reads_the_namespaced_cube_nodes(): void {
		$xml = (string) file_get_contents( __DIR__ . '/../../fixtures/ecb-eurofxref-daily.xml' );

		$rates = RateProvider::parse_ecb_response( $xml );

		$this->assertSame( 1.1645, $rates['USD'] );
		$this->assertSame( 56.0483, $rates['TRY'] );
		$this->assertArrayNotHasKey( 'EUR', $rates, 'EUR is the implicit base; ECB does not list it.' );
	}

	/**
	 * The real XXE threat is an EXTERNAL entity -- one that tries to read a
	 * local file or reach the network from inside the parse. `LIBXML_NONET`
	 * plus never passing `LIBXML_DTDLOAD`/`LIBXML_DTDVALID` means that kind
	 * of entity cannot be resolved at all, so libxml refuses the whole
	 * document: `simplexml_load_string()` returns `false`, not a document
	 * with the entity silently dropped. That is the correct outcome here --
	 * for an external entity there is no parse that both "does not expand
	 * the entity" and "succeeds", because resolving it is a precondition of
	 * finishing the parse. Confirmed empirically before writing this
	 * assertion: the payload below reproducibly makes
	 * simplexml_load_string() return `false` with this file's exact
	 * parsing flags.
	 *
	 * (An earlier version of this test used an INTERNAL entity --
	 * `<!ENTITY e "PWNED">` with no SYSTEM/PUBLIC reference. That variant
	 * is not a useful XXE test: internal general entities are expanded as
	 * part of ordinary attribute-value normalisation regardless of
	 * `LIBXML_NOENT`, so it passed only because of the three-letter
	 * ISO-4217 shape guard in parse_ecb_response()'s Cube loop -- it
	 * measured that guard, not the parser's entity handling. Do not
	 * restore it as a stand-in for this test; the two exercise different
	 * code.)
	 *
	 * Non-vacuity is covered by
	 * test_parse_ecb_reads_the_namespaced_cube_nodes(): the real fixture,
	 * with no DOCTYPE at all, still parses and still yields real rates.
	 * Together the two tests are one argument -- malicious external
	 * entities are refused outright, and that refusal is not blanket
	 * paranoia that also breaks the feed this code exists to read.
	 *
	 * @return void
	 */
	public function test_parse_ecb_rejects_a_document_with_an_external_entity(): void {
		$evil = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/hosts">]>'
			. '<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01"'
			. ' xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">'
			. '<Cube><Cube time="2026-08-28"><Cube currency="XXE" rate="&xxe;"/>'
			. '</Cube></Cube></gesmes:Envelope>';

		$rates = RateProvider::parse_ecb_response( $evil );

		// The document is refused outright -- an empty map, nothing from
		// the referenced file (or any other attribute) leaked into it.
		$this->assertSame( array(), $rates );
	}

	/**
	 * Malformed input is a failure, not a fatal error or a warning that
	 * leaks into test output.
	 *
	 * @return void
	 */
	public function test_parse_ecb_returns_empty_on_invalid_xml(): void {
		$this->assertSame( array(), RateProvider::parse_ecb_response( 'not xml at all' ) );
	}

	/**
	 * 🔴 libxml_use_internal_errors() is a PROCESS-GLOBAL flag, not a
	 * per-call setting. parse_ecb_response() flips it to parse safely and
	 * must restore it on every exit path -- including the failure path
	 * exercised here, where simplexml_load_string() itself fails. Leaving
	 * it flipped breaks unrelated XML work elsewhere in the same request.
	 *
	 * @return void
	 */
	public function test_the_parser_restores_the_libxml_error_state(): void {
		$errors_before = libxml_use_internal_errors( false );
		libxml_use_internal_errors( $errors_before );

		RateProvider::parse_ecb_response( '<broken' );

		$errors_after = libxml_use_internal_errors( false );
		libxml_use_internal_errors( $errors_after );

		$this->assertSame( $errors_before, $errors_after );
	}

	/**
	 * The feed lists rates FROM EUR; EUR itself has no row. For a non-EUR
	 * base, every other currency is re-expressed relative to that base, and
	 * EUR becomes a normal target: rate(EUR) = 1 / ecb[base].
	 *
	 * @return void
	 */
	public function test_cross_rate_from_a_non_eur_base(): void {
		$ecb = array(
			'USD' => 1.1645,
			'TRY' => 56.0483,
		);

		$rates = RateProvider::cross_rates( $ecb, 'USD' );

		$this->assertEqualsWithDelta( 56.0483 / 1.1645, $rates['TRY'], 0.0001 );
		$this->assertEqualsWithDelta( 1 / 1.1645, $rates['EUR'], 0.0001 );
		$this->assertArrayNotHasKey( 'USD', $rates, 'The base is not a conversion target.' );
	}

	/**
	 * EUR is the implicit base of the ECB feed, so when the caller's own
	 * base IS EUR the table is already what was asked for and passes
	 * through unchanged.
	 *
	 * @return void
	 */
	public function test_cross_rate_with_eur_base_passes_through(): void {
		$ecb = array( 'USD' => 1.1645 );

		$this->assertSame( 1.1645, RateProvider::cross_rates( $ecb, 'EUR' )['USD'] );
	}

	/**
	 * A base the ECB feed does not carry cannot be priced -- an empty array
	 * (failure), not a partial or zeroed-out table.
	 *
	 * @return void
	 */
	public function test_a_base_outside_the_ecb_set_is_a_failure(): void {
		$this->assertSame( array(), RateProvider::cross_rates( array( 'USD' => 1.1645 ), 'SAR' ) );
	}

	/**
	 * 🔴 A zero (or negative) base rate must never reach the division. The
	 * raw formula is ecb[X] / ecb[base]; if ecb[base] is zero that is a
	 * division by zero, not a wrong number -- this guard exists so the
	 * method fails cleanly instead of ever attempting it.
	 *
	 * @return void
	 */
	public function test_a_zero_base_rate_is_a_failure_not_a_division(): void {
		$this->assertSame( array(), RateProvider::cross_rates( array( 'USD' => 0.0 ), 'USD' ) );
	}

	/**
	 * The base is looked up case-insensitively, the same way every other
	 * currency code this class handles is normalised.
	 *
	 * @return void
	 */
	public function test_the_base_is_normalised_to_upper_case(): void {
		$rates = RateProvider::cross_rates( array( 'USD' => 1.1645, 'TRY' => 56.0483 ), 'usd' );

		$this->assertArrayHasKey( 'TRY', $rates );
	}
}
