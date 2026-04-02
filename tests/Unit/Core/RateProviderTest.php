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

		$this->assertSame( 'mhm_cs_rates_TRY', $key );
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

}
