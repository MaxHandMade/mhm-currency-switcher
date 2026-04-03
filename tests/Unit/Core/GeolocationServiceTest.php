<?php
/**
 * Tests for GeolocationService — country detection cascade.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use MhmCurrencySwitcher\Core\GeolocationService;
use PHPUnit\Framework\TestCase;

class GeolocationServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
		parent::tearDown();
	}

	public function test_detect_from_cloudflare_header(): void {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'TR';
		$service = new GeolocationService();

		$this->assertSame( 'TR', $service->detect_country() );
	}

	public function test_detect_cloudflare_lowercases_to_upper(): void {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'de';
		$service = new GeolocationService();

		$this->assertSame( 'DE', $service->detect_country() );
	}

	public function test_detect_cloudflare_ignores_xx(): void {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'XX';
		$service = new GeolocationService();

		$this->assertNull( $service->detect_country() );
	}

	public function test_detect_cloudflare_ignores_t1(): void {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'T1';
		$service = new GeolocationService();

		$this->assertNull( $service->detect_country() );
	}

	public function test_detect_returns_null_without_providers(): void {
		$service = new GeolocationService();

		$this->assertNull( $service->detect_country() );
	}

	public function test_detect_cloudflare_validates_format(): void {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'INVALID';
		$service = new GeolocationService();

		$this->assertNull( $service->detect_country() );
	}
}
