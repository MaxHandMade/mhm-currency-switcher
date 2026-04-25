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
		$reflect     = new ReflectionClass( LicenseServerPublicKey::class );
		$constant    = $reflect->getReflectionConstant( 'PEM' );
		$embedded    = trim( (string) $constant->getValue() );
		$fixture_pem = trim( (string) file_get_contents( __DIR__ . '/../../fixtures/test-rsa-public.pem' ) );

		$this->assertSame(
			$fixture_pem,
			$embedded,
			'Embedded PEM must match tests/fixtures/test-rsa-public.pem during development. '
			. 'Replace with production public.pem ONLY on the release tag commit.'
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
