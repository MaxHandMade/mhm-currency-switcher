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
	 * 🔴 A shop whose network blocks the first fallback still gets rates.
	 *
	 * Measured, not assumed: from a Turkish network the Cloudflare Pages host
	 * that serves the first fallback is unreachable at the NETWORK level, not
	 * merely by DNS -- resolving it over DoH and connecting straight to the real
	 * Cloudflare addresses with the right SNI still times out, while other
	 * Cloudflare hosts answer normally. So the block is specific to that domain
	 * and a Turkish server cannot route around it by changing resolvers.
	 *
	 * Restoring the previous host is not an option: it is on WordPress.org's
	 * offloading deny-list, which is what moved this URL in the first place, and
	 * the upstream project documents no third mirror. Hence a second fallback
	 * from a different provider entirely.
	 *
	 * @return void
	 */
	public function test_a_blocked_first_fallback_falls_through_to_the_second(): void {
		$GLOBALS['__mhmcs_test_http_get_urls'] = array();
		$GLOBALS['__mhmcs_test_http_get_map']  = array(
			// Only the last source answers; the first two behave like the
			// blocked host does in practice.
			'frankfurter' => $this->http_ok(
				array(
					'base'  => 'USD',
					'date'  => '2026-08-24',
					'rates' => array( 'EUR' => 0.857, 'TRY' => 41.2 ),
				)
			),
		);

		$provider = ( new \ReflectionClass( RateProvider::class ) )->newInstanceWithoutConstructor();
		$rates    = $provider->fetch_rates( 'USD', true );

		unset( $GLOBALS['__mhmcs_test_http_get_map'] );

		$this->assertEqualsWithDelta( 41.2, $rates['TRY'] ?? 0.0, 0.0001, 'The chain reached a source that answered.' );

		$asked = implode( ' | ', $GLOBALS['__mhmcs_test_http_get_urls'] );
		$this->assertStringContainsString( 'exchangerate-api', $asked, 'Primary is still tried first.' );
		$this->assertStringContainsString( 'currency-api', $asked, 'The existing fallback is still tried before the new one.' );
		$this->assertCount(
			3,
			$GLOBALS['__mhmcs_test_http_get_urls'],
			'Exactly three sources, in order: primary, fallback, second fallback.'
		);
	}

	/**
	 * 🔴 An answer with no rates in it is a failure, whatever the status code.
	 *
	 * The second source replies HTTP 200 with `{"rates":{}}` and a null base
	 * when asked for a currency it does not carry -- measured against a real
	 * request for SAR, which is outside its reference set. Treating that as
	 * success would store an empty rate table and let the chain stop at a
	 * source that gave it nothing.
	 *
	 * @return void
	 */
	public function test_an_empty_rate_set_is_not_a_successful_answer(): void {
		$GLOBALS['__mhmcs_test_http_get_urls'] = array();
		$GLOBALS['__mhmcs_test_http_get_map']  = array(
			'frankfurter' => $this->http_ok( array( 'amount' => 1.0, 'base' => null, 'date' => null, 'rates' => array() ) ),
		);

		$provider = ( new \ReflectionClass( RateProvider::class ) )->newInstanceWithoutConstructor();
		$rates    = $provider->fetch_rates( 'SAR', true );

		unset( $GLOBALS['__mhmcs_test_http_get_map'] );

		$this->assertSame( array(), $rates, 'An empty rate table is nothing, not a result.' );
	}

	/**
	 * Each source in the chain can be redirected independently.
	 *
	 * The filter carries the source slug because a shop that has to move one
	 * host almost never wants to move the others, and a filter that could only
	 * say "the fallback URL" would force a caller to pattern-match the URL it
	 * was handed in order to tell them apart.
	 *
	 * @return void
	 */
	public function test_the_fallback_filter_names_which_source_it_is_filtering(): void {
		$seen = array();

		$GLOBALS['__mhmcs_test_filters']['mhmcs_fallback_rates_url'] = static function ( $url, $base, $source ) use ( &$seen ) {
			$seen[] = $source;

			return 'frankfurter' === $source ? 'https://rates.example.test/' . strtolower( $base ) : $url;
		};

		$GLOBALS['__mhmcs_test_http_get_urls'] = array();
		$GLOBALS['__mhmcs_test_http_get_map']  = array( 'no-host-answers' => $this->http_ok( array() ) );

		$provider = ( new \ReflectionClass( RateProvider::class ) )->newInstanceWithoutConstructor();
		$provider->fetch_rates( 'EUR', true );

		unset( $GLOBALS['__mhmcs_test_filters']['mhmcs_fallback_rates_url'], $GLOBALS['__mhmcs_test_http_get_map'] );

		$this->assertSame(
			array( 'currency-api', 'frankfurter' ),
			$seen,
			'Both fallbacks run through the filter, and each says which one it is.'
		);
		$this->assertContains(
			'https://rates.example.test/eur',
			$GLOBALS['__mhmcs_test_http_get_urls'],
			'Redirecting one source leaves the other where it was.'
		);
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
		$method   = new \ReflectionMethod( RateProvider::class, 'fetch_from_fawaz_api' );
		$method->setAccessible( true );
		$method->invoke( $provider, $base );

		return $GLOBALS['__mhmcs_test_http_get_urls'];
	}

	/**
	 * Guard, and the reason the filter below exists.
	 *
	 * Pins the shipped default so the filter test cannot pass by accident, and
	 * so the host is a deliberate, visible choice rather than a literal buried
	 * mid-method. The default moved to Cloudflare Pages in 2.0.0 because the
	 * previous host is on WordPress.org's offloading deny-list.
	 *
	 * @return void
	 */
	public function test_the_fallback_asks_the_shipped_host_by_default(): void {
		$urls = $this->fallback_urls_requested( 'USD' );

		$this->assertCount( 1, $urls, 'Guard: the recorder saw exactly the one fetch this method makes.' );
		$this->assertStringContainsString(
			'latest.currency-api.pages.dev',
			$urls[0],
			'The shipped fallback host.'
		);
	}

	/**
	 * A shop that cannot reach the fallback host must be able to move it.
	 *
	 * 🔴 Measured, not theorised: from a Turkish network `latest.currency-api
	 * .pages.dev` resolves to 213.14.227.50 -- a national block address -- and
	 * the request times out, while the primary API and the pre-2.0.0 host both
	 * answer 200. The host cannot simply be changed back: the old one is on
	 * WordPress.org's offloading deny-list, which is why 2.0.0 moved off it.
	 *
	 * So the resilience the fallback exists to provide is, on those networks,
	 * absent -- silently, because it only matters on the day the primary API
	 * is down. A filter is the WordPress answer: the shipped default stays
	 * compliant, and a shop behind a block can point it somewhere reachable
	 * without forking the plugin.
	 *
	 * @return void
	 */
	public function test_the_fallback_url_can_be_redirected_by_a_filter(): void {
		$GLOBALS['__mhmcs_test_filters']['mhmcs_fallback_rates_url'] = static function ( $url, $base ) {
			return 'https://rates.example.test/' . strtolower( $base ) . '.json';
		};

		$urls = $this->fallback_urls_requested( 'EUR' );

		unset( $GLOBALS['__mhmcs_test_filters']['mhmcs_fallback_rates_url'] );

		$this->assertCount( 1, $urls );
		$this->assertSame(
			'https://rates.example.test/eur.json',
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
	 * Test parsing a valid Fawaz Ahmed API response.
	 *
	 * Given: {"try": {"usd": 0.029, "eur": 0.025}}
	 * Expect: ['USD' => 0.029, 'EUR' => 0.025] (uppercased keys)
	 *
	 * @return void
	 */
	public function test_parse_fawaz_api_response(): void {
		$body = array(
			'date' => '2026-04-02',
			'try'  => array(
				'usd' => 0.029,
				'eur' => 0.025,
				'gbp' => 0.022,
			),
		);

		$result = RateProvider::parse_fawaz_response( $body, 'TRY' );

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
	 * Test that invalid JSON structure returns an empty array for Fawaz API.
	 *
	 * @return void
	 */
	public function test_parse_fawaz_invalid_returns_empty(): void {
		// Missing base-currency key.
		$result = RateProvider::parse_fawaz_response( array( 'foo' => 'bar' ), 'TRY' );

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
	 * Test that Fawaz API parsing is case-insensitive for the base key.
	 *
	 * @return void
	 */
	public function test_parse_fawaz_case_insensitive_base(): void {
		$body = array(
			'usd' => array(
				'eur' => 0.92,
				'try' => 34.5,
			),
		);

		$result = RateProvider::parse_fawaz_response( $body, 'USD' );

		$this->assertCount( 2, $result );
		$this->assertEqualsWithDelta( 0.92, $result['EUR'], 0.0001 );
		$this->assertEqualsWithDelta( 34.5, $result['TRY'], 0.0001 );
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
	 * XXE must be two-sided: the entity must not expand AND the document
	 * must still parse. A parser that rejects the whole malformed document
	 * would also technically "not expand" the entity -- that would be a
	 * vacuous pass that proves nothing about entity handling specifically.
	 *
	 * @return void
	 */
	public function test_parse_ecb_does_not_expand_entities(): void {
		$evil = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e "PWNED">]>'
			. '<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01"'
			. ' xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">'
			. '<Cube><Cube time="2026-08-28"><Cube currency="&e;" rate="1.0"/>'
			. '</Cube></Cube></gesmes:Envelope>';

		$rates = RateProvider::parse_ecb_response( $evil );

		// The entity must not have expanded into a currency code.
		$this->assertArrayNotHasKey( 'PWNED', $rates );
		// And the document must still have been readable as XML -- a
		// blanket reject would also satisfy the assertion above.
		$this->assertIsArray( $rates );
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
	 * 🔴 libxml_use_internal_errors() and (on PHP < 8)
	 * libxml_disable_entity_loader() are PROCESS-GLOBAL flags, not
	 * per-call settings. parse_ecb_response() flips them to parse safely
	 * and must restore both on every exit path -- including the failure
	 * path exercised here, where simplexml_load_string() itself fails.
	 * Leaving either flipped breaks unrelated XML work elsewhere in the
	 * same request.
	 *
	 * @return void
	 */
	public function test_the_parser_restores_both_libxml_global_states(): void {
		$errors_before = libxml_use_internal_errors( false );
		libxml_use_internal_errors( $errors_before );

		RateProvider::parse_ecb_response( '<broken' );

		$errors_after = libxml_use_internal_errors( false );
		libxml_use_internal_errors( $errors_after );

		$this->assertSame( $errors_before, $errors_after );
	}

}
