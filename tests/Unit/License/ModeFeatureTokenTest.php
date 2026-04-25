<?php
/**
 * Tests for License\Mode v0.6.0+ feature-token gating.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\License
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\License;

use MhmCurrencySwitcher\License\LicenseManager;
use MhmCurrencySwitcher\License\Mode;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;

/**
 * v0.6.0 — Mode::can_use_*() must consult an RSA-signed feature token, not
 * just `is_active()`. A `return true;` patch on `LicenseManager::is_active()`
 * cannot unlock Pro features when the gate also requires a token whose RSA
 * signature verifies, whose site_hash matches the local site, and whose
 * `features['<key>']` is true.
 *
 * Strict enforcement — there is NO legacy `is_pro()`-only fallback any more.
 *
 * @covers \MhmCurrencySwitcher\License\Mode
 */
class ModeFeatureTokenTest extends TestCase {

	/** @var OpenSSLAsymmetricKey */
	private $private_key;

	protected function setUp(): void {
		parent::setUp();
		LicenseManager::reset();
		$GLOBALS['__mhm_cs_test_options'] = array();

		$private_pem = (string) file_get_contents( __DIR__ . '/../../fixtures/test-rsa-private.pem' );
		$private     = openssl_pkey_get_private( $private_pem );
		$this->assertNotFalse( $private, 'Test fixture private key failed to parse' );
		$this->private_key = $private;
	}

	protected function tearDown(): void {
		LicenseManager::reset();
		$GLOBALS['__mhm_cs_test_options'] = array();
		parent::tearDown();
	}

	public function test_can_use_fixed_prices_returns_true_with_valid_token_granting_feature(): void {
		$this->seed_active_license_with_token( array( 'fixed_pricing' => true ) );

		$this->assertTrue( Mode::can_use_fixed_prices() );
	}

	public function test_can_use_fixed_prices_returns_false_when_token_missing(): void {
		$this->seed_active_license_without_token();

		$this->assertFalse( Mode::can_use_fixed_prices() );
	}

	public function test_can_use_fixed_prices_returns_false_when_token_does_not_grant_feature(): void {
		$this->seed_active_license_with_token( array( 'geolocation' => true /* no fixed_pricing */ ) );

		$this->assertFalse( Mode::can_use_fixed_prices() );
	}

	public function test_each_gate_checks_its_own_feature_flag(): void {
		$this->seed_active_license_with_token( array( 'geolocation' => true ) );
		$this->assertTrue( Mode::can_use_geolocation() );
		$this->assertFalse( Mode::can_use_fixed_prices() );
		$this->assertFalse( Mode::can_use_payment_restrictions() );

		$this->seed_active_license_with_token( array( 'payment_restrictions' => true ) );
		$this->assertTrue( Mode::can_use_payment_restrictions() );
		$this->assertFalse( Mode::can_use_geolocation() );

		$this->seed_active_license_with_token( array( 'auto_rate_update' => true ) );
		$this->assertTrue( Mode::can_use_auto_rate_update() );
		$this->assertFalse( Mode::can_use_multilingual() );

		$this->seed_active_license_with_token( array( 'multilingual' => true ) );
		$this->assertTrue( Mode::can_use_multilingual() );
		$this->assertFalse( Mode::can_use_rest_api_filter() );

		$this->seed_active_license_with_token( array( 'rest_api_filter' => true ) );
		$this->assertTrue( Mode::can_use_rest_api_filter() );
		$this->assertFalse( Mode::can_use_fixed_prices() );
	}

	public function test_is_active_alone_is_not_enough_to_unlock_features(): void {
		// Source-edit attack: license option says active but no token.
		update_option(
			LicenseManager::OPTION_KEY,
			array(
				'license_key'   => 'EVIL-PATCH-001',
				'status'        => 'active',
				'plan'          => 'pro',
				'expires_at'    => gmdate( 'c', time() + 86400 ),
				'activation_id' => 'fake-activation',
				// No feature_token — simulating crack.
			)
		);

		$this->assertTrue( LicenseManager::instance()->is_active(), 'Local check should pass (the attack works)' );
		$this->assertFalse( Mode::can_use_fixed_prices(), 'But Mode must NOT grant the feature' );
		$this->assertFalse( Mode::can_use_geolocation() );
		$this->assertFalse( Mode::can_use_payment_restrictions() );
	}

	public function test_no_legacy_fallback_when_token_field_empty(): void {
		// v0.6.0 — even with is_active() returning true, an empty token must
		// fail closed. The v0.5.x legacy `is_pro()` fallback is GONE: clients
		// no longer carry a shared secret, the embedded public key is the
		// single source of truth.
		$this->seed_active_license_without_token();

		$this->assertFalse( Mode::can_use_fixed_prices(), 'No legacy fallback - strict token enforcement' );
		$this->assertFalse( Mode::can_use_geolocation() );
		$this->assertFalse( Mode::can_use_payment_restrictions() );
		$this->assertFalse( Mode::can_use_auto_rate_update() );
		$this->assertFalse( Mode::can_use_multilingual() );
		$this->assertFalse( Mode::can_use_rest_api_filter() );
	}

	public function test_returns_false_when_token_signed_by_foreign_key(): void {
		$foreign = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		$this->assertNotFalse( $foreign, 'Foreign key generation failed' );

		$token = $this->build_feature_token( array( 'fixed_pricing' => true ), $foreign );
		LicenseManager::reset();
		update_option(
			LicenseManager::OPTION_KEY,
			array(
				'license_key'   => 'FORGED-001',
				'status'        => 'active',
				'plan'          => 'pro',
				'expires_at'    => gmdate( 'c', time() + 86400 ),
				'activation_id' => 'a1',
				'feature_token' => $token,
			)
		);

		$this->assertFalse( Mode::can_use_fixed_prices(), 'Foreign-key-signed token must NOT verify' );
	}

	public function test_returns_false_when_token_site_hash_does_not_match(): void {
		$token = $this->build_feature_token(
			array( 'fixed_pricing' => true ),
			$this->private_key,
			array( 'site_hash' => 'totally-different-site-hash' )
		);

		LicenseManager::reset();
		update_option(
			LicenseManager::OPTION_KEY,
			array(
				'license_key'   => 'WRONG-SITE-001',
				'status'        => 'active',
				'plan'          => 'pro',
				'expires_at'    => gmdate( 'c', time() + 86400 ),
				'activation_id' => 'a1',
				'feature_token' => $token,
			)
		);

		$this->assertFalse( Mode::can_use_fixed_prices(), 'Site-hash-mismatched token must NOT grant access' );
	}

	public function test_returns_false_when_token_expired(): void {
		$token = $this->build_feature_token(
			array( 'fixed_pricing' => true ),
			$this->private_key,
			array(
				'expires_at' => time() - 60,
				'issued_at'  => time() - 90000,
			)
		);

		LicenseManager::reset();
		update_option(
			LicenseManager::OPTION_KEY,
			array(
				'license_key'   => 'EXPIRED-001',
				'status'        => 'active',
				'plan'          => 'pro',
				'expires_at'    => gmdate( 'c', time() + 86400 ),
				'activation_id' => 'a1',
				'feature_token' => $token,
			)
		);

		$this->assertFalse( Mode::can_use_fixed_prices(), 'Expired token must NOT grant access' );
	}

	public function test_returns_false_when_license_inactive_regardless_of_token(): void {
		$token = $this->build_feature_token( array( 'fixed_pricing' => true ) );
		update_option(
			LicenseManager::OPTION_KEY,
			array(
				'license_key'   => 'INACTIVE-001',
				'status'        => 'inactive',
				'feature_token' => $token,
			)
		);

		$this->assertFalse( Mode::can_use_fixed_prices() );
	}

	/**
	 * @param array<string, bool> $features
	 */
	private function seed_active_license_with_token( array $features ): void {
		LicenseManager::reset();
		update_option(
			LicenseManager::OPTION_KEY,
			array(
				'license_key'   => 'TOKEN-LICENSE-001',
				'status'        => 'active',
				'plan'          => 'pro',
				'expires_at'    => gmdate( 'c', time() + 86400 ),
				'activation_id' => 'a1',
				'feature_token' => $this->build_feature_token( $features ),
			)
		);
	}

	private function seed_active_license_without_token(): void {
		LicenseManager::reset();
		update_option(
			LicenseManager::OPTION_KEY,
			array(
				'license_key'   => 'NO-TOKEN-001',
				'status'        => 'active',
				'plan'          => 'pro',
				'expires_at'    => gmdate( 'c', time() + 86400 ),
				'activation_id' => 'a1',
				// feature_token deliberately omitted.
			)
		);
	}

	/**
	 * @param array<string,bool>          $features
	 * @param OpenSSLAsymmetricKey|null   $signing_key      Defaults to fixture private key.
	 * @param array<string,mixed>         $payload_overrides
	 */
	private function build_feature_token(
		array $features,
		$signing_key = null,
		array $payload_overrides = array()
	): string {
		$signing_key = $signing_key ?? $this->private_key;
		$site_hash   = LicenseManager::instance()->get_site_hash();

		$payload = array_merge(
			array(
				'license_key_hash' => 'h',
				'product_slug'     => 'mhm-currency-switcher',
				'plan'             => 'pro',
				'features'         => $features,
				'site_hash'        => $site_hash,
				'issued_at'        => time(),
				'expires_at'       => time() + 86400,
			),
			$payload_overrides
		);

		$sorted    = $this->recursive_ksort( $payload );
		$canonical = (string) wp_json_encode( $sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$signature = '';
		openssl_sign( $canonical, $signature, $signing_key, OPENSSL_ALGO_SHA256 );

		$encode = static fn( string $bin ): string => rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );

		return $encode( $canonical ) . '.' . $encode( $signature );
	}

	/**
	 * @param array<int|string,mixed> $array
	 * @return array<int|string,mixed>
	 */
	private function recursive_ksort( array $array ): array {
		ksort( $array );
		foreach ( $array as $key => $value ) {
			if ( is_array( $value ) ) {
				$array[ $key ] = $this->recursive_ksort( $value );
			}
		}
		return $array;
	}
}
