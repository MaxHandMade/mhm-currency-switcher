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

}
