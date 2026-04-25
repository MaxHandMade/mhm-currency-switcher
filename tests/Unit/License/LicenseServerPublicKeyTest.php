<?php
/**
 * Tests for LicenseServerPublicKey.
 *
 * @package MhmCurrencySwitcher\Tests
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\License;

use MhmCurrencySwitcher\License\LicenseServerPublicKey;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class LicenseServerPublicKeyTest extends TestCase {

	protected function tearDown(): void {
		LicenseServerPublicKey::reset_cache();
		parent::tearDown();
	}

	public function test_resource_returns_parsed_public_key(): void {
		$key = LicenseServerPublicKey::resource();

		$this->assertInstanceOf( OpenSSLAsymmetricKey::class, $key );
	}

	public function test_resource_matches_fixture_public_key(): void {
		// Pre-release this test pinned the embedded PEM to the fixture file
		// as a canary: if someone swapped the constant for production keys
		// mid-development, this would fail loudly. Once the release tag
		// ships with the production public key embedded, the invariant is
		// intentionally broken — fixture-bound tests now reach the fixture
		// key via tests/bootstrap.php inject_for_testing() override instead.
		// The reflection-direct constant read here cannot see that override.
		$this->markTestSkipped(
			'Embedded PEM is the production public key after the v0.6.0 release '
			. 'swap. Fixture-bound suite uses bootstrap.php inject_for_testing() override; '
			. 'reflection-direct constant read here cannot see the override.'
		);
	}

	public function test_resource_reuses_cached_resource_across_calls(): void {
		$first  = LicenseServerPublicKey::resource();
		$second = LicenseServerPublicKey::resource();

		$this->assertSame(
			$first,
			$second,
			'Public key resource must be cached - re-parsing on every gate call would cost 50ms+'
		);
	}

	public function test_reset_cache_forces_reparse(): void {
		$first = LicenseServerPublicKey::resource();
		LicenseServerPublicKey::reset_cache();
		$second = LicenseServerPublicKey::resource();

		$this->assertNotSame(
			$first,
			$second,
			'reset_cache() must force a fresh openssl_pkey_get_public() call'
		);
	}

	public function test_resource_produces_key_usable_for_verify(): void {
		$public_key = LicenseServerPublicKey::resource();

		$private_pem = (string) file_get_contents( __DIR__ . '/../../fixtures/test-rsa-private.pem' );
		$private_key = openssl_pkey_get_private( $private_pem );

		$payload   = 'round-trip-test-payload';
		$signature = '';
		openssl_sign( $payload, $signature, $private_key, OPENSSL_ALGO_SHA256 );

		$verified = openssl_verify( $payload, $signature, $public_key, OPENSSL_ALGO_SHA256 );
		$this->assertSame( 1, $verified, 'Embedded public key must verify signatures from paired private key' );
	}
}
