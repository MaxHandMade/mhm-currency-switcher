<?php
/**
 * Tests for LicenseManager — expires_at normalization.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\License
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\License;

use MhmCurrencySwitcher\License\LicenseManager;
use PHPUnit\Framework\TestCase;

class LicenseManagerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		LicenseManager::reset();
	}

	public function test_normalize_expires_at_with_iso_string(): void {
		$manager = LicenseManager::instance();
		$result  = $this->call_normalize( $manager, '2026-12-31T23:59:59+00:00' );
		$this->assertSame( '2026-12-31T23:59:59+00:00', $result );
	}

	public function test_normalize_expires_at_with_timestamp(): void {
		$manager = LicenseManager::instance();
		$result  = $this->call_normalize( $manager, 1798761600 );
		$this->assertNotEmpty( $result );
		$this->assertStringContainsString( '2027-', $result );
	}

	public function test_normalize_expires_at_with_numeric_string(): void {
		$manager = LicenseManager::instance();
		$result  = $this->call_normalize( $manager, '1798761600' );
		$this->assertNotEmpty( $result );
		$this->assertStringContainsString( '2027-', $result );
	}

	public function test_normalize_expires_at_with_empty_string(): void {
		$manager = LicenseManager::instance();
		$result  = $this->call_normalize( $manager, '' );
		$this->assertSame( '', $result );
	}

	public function test_normalize_expires_at_with_zero(): void {
		$manager = LicenseManager::instance();
		$result  = $this->call_normalize( $manager, 0 );
		$this->assertSame( '', $result );
	}

	public function test_normalize_expires_at_with_null(): void {
		$manager = LicenseManager::instance();
		$result  = $this->call_normalize( $manager, null );
		$this->assertSame( '', $result );
	}

	public function test_normalize_expires_at_with_date_string(): void {
		$manager = LicenseManager::instance();
		$result  = $this->call_normalize( $manager, '2026-06-15' );
		$this->assertNotEmpty( $result );
		$this->assertStringContainsString( '2026-06-15', $result );
	}

	/**
	 * Invoke the private normalize_expires_at method via reflection.
	 *
	 * @param LicenseManager $manager Manager instance.
	 * @param mixed          $value   Raw expires_at value.
	 * @return string Normalized value.
	 */
	private function call_normalize( LicenseManager $manager, $value ): string {
		$ref = new \ReflectionMethod( $manager, 'normalize_expires_at' );
		$ref->setAccessible( true );
		return $ref->invoke( $manager, $value );
	}
}
