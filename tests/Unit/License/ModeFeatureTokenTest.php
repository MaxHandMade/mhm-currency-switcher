<?php
/**
 * Tests for License\Mode v0.5.0+ feature-token gating.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\License
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\License;

use MhmCurrencySwitcher\License\LicenseManager;
use MhmCurrencySwitcher\License\Mode;
use PHPUnit\Framework\TestCase;

/**
 * Phase C (v0.5.0+) — Mode::can_use_*() must consult the server-issued feature
 * token, not just `is_active()`. A `return true;` patch on `LicenseManager::is_active()`
 * should NOT unlock Pro features when the feature token is missing or tampered.
 *
 * Backward-compat: when no FEATURE_TOKEN_KEY secret is configured (legacy
 * deploy), gates fall back to `is_pro()` so existing customers keep working.
 *
 * @covers \MhmCurrencySwitcher\License\Mode
 */
class ModeFeatureTokenTest extends TestCase {

	private const FEATURE_SECRET = 'test-feature-token-secret';

	protected function setUp(): void {
		parent::setUp();
		LicenseManager::reset();
		$GLOBALS['__mhm_cs_test_options'] = array();

		if ( ! defined( 'MHM_CS_LICENSE_FEATURE_TOKEN_KEY' ) ) {
			putenv( 'MHM_CS_LICENSE_FEATURE_TOKEN_KEY=' . self::FEATURE_SECRET );
		}
	}

	protected function tearDown(): void {
		LicenseManager::reset();
		$GLOBALS['__mhm_cs_test_options'] = array();

		if ( ! defined( 'MHM_CS_LICENSE_FEATURE_TOKEN_KEY' ) ) {
			putenv( 'MHM_CS_LICENSE_FEATURE_TOKEN_KEY=' );
		}
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
		// Simulating source-edit attack: license option says active but no token.
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

	public function test_falls_back_to_is_pro_when_feature_token_secret_not_configured(): void {
		if ( defined( 'MHM_CS_LICENSE_FEATURE_TOKEN_KEY' ) ) {
			$this->markTestSkipped( 'Constant defined; legacy fallback path cannot be asserted.' );
		}

		putenv( 'MHM_CS_LICENSE_FEATURE_TOKEN_KEY=' );
		LicenseManager::reset();

		// Simulating talking to a legacy server (no token) with no client
		// secret configured — gracefully fall back to `is_pro()`.
		$this->seed_active_license_without_token();

		$this->assertTrue( Mode::can_use_fixed_prices(), 'Legacy fallback when no secret' );
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

	public function test_tampered_token_rejected(): void {
		$token = $this->build_feature_token( array( 'fixed_pricing' => true ) );
		// Corrupt the signature.
		[ $b64 ] = explode( '.', $token, 2 );
		$bad_token = $b64 . '.' . str_repeat( '0', 64 );

		update_option(
			LicenseManager::OPTION_KEY,
			array(
				'license_key'   => 'TAMPERED-001',
				'status'        => 'active',
				'feature_token' => $bad_token,
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
	 * @param array<string, bool> $features
	 */
	private function build_feature_token( array $features ): string {
		$payload = array(
			'license_key_hash' => 'h',
			'product_slug'     => 'mhm-currency-switcher',
			'plan'             => 'pro',
			'features'         => $features,
			'site_hash'        => 's',
			'issued_at'        => time(),
			'expires_at'       => time() + 86400,
		);
		$b64     = base64_encode( (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return $b64 . '.' . hash_hmac( 'sha256', $b64, self::FEATURE_SECRET );
	}
}
