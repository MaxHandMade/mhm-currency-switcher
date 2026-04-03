<?php
/**
 * Tests for CountryCurrencyMap — country to currency mapping.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use MhmCurrencySwitcher\Core\CountryCurrencyMap;
use PHPUnit\Framework\TestCase;

class CountryCurrencyMapTest extends TestCase {

	public function test_turkey_maps_to_try(): void {
		$this->assertSame( 'TRY', CountryCurrencyMap::get_currency( 'TR' ) );
	}

	public function test_united_states_maps_to_usd(): void {
		$this->assertSame( 'USD', CountryCurrencyMap::get_currency( 'US' ) );
	}

	public function test_germany_maps_to_eur(): void {
		$this->assertSame( 'EUR', CountryCurrencyMap::get_currency( 'DE' ) );
	}

	public function test_france_maps_to_eur(): void {
		$this->assertSame( 'EUR', CountryCurrencyMap::get_currency( 'FR' ) );
	}

	public function test_united_kingdom_maps_to_gbp(): void {
		$this->assertSame( 'GBP', CountryCurrencyMap::get_currency( 'GB' ) );
	}

	public function test_japan_maps_to_jpy(): void {
		$this->assertSame( 'JPY', CountryCurrencyMap::get_currency( 'JP' ) );
	}

	public function test_unknown_country_returns_null(): void {
		$this->assertNull( CountryCurrencyMap::get_currency( 'ZZ' ) );
	}

	public function test_lowercase_input_works(): void {
		$this->assertSame( 'TRY', CountryCurrencyMap::get_currency( 'tr' ) );
	}

	public function test_map_has_at_least_60_entries(): void {
		$map = CountryCurrencyMap::get_map();
		$this->assertGreaterThanOrEqual( 60, count( $map ) );
	}
}
