<?php
/**
 * Tests for License\ClientSecrets.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\License
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\License;

use MhmCurrencySwitcher\License\ClientSecrets;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MhmCurrencySwitcher\License\ClientSecrets
 */
class ClientSecretsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		putenv( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET=' );
		putenv( 'MHM_CS_LICENSE_PING_SECRET=' );
	}

	protected function tearDown(): void {
		putenv( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET=' );
		putenv( 'MHM_CS_LICENSE_PING_SECRET=' );
		parent::tearDown();
	}

	public function test_returns_empty_strings_when_no_constants_or_env_set(): void {
		if ( defined( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET' ) ) {
			$this->markTestSkipped( 'Environment defines RESPONSE_HMAC_SECRET; empty path cannot be asserted.' );
		}

		$this->assertSame( '', ClientSecrets::get_response_hmac_secret() );
		$this->assertSame( '', ClientSecrets::get_ping_secret() );
	}

	public function test_reads_from_env_when_constants_not_defined(): void {
		if ( defined( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET' ) ) {
			$this->markTestSkipped( 'Environment defines constants; env-only path cannot be asserted.' );
		}

		putenv( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET=env-resp-secret' );
		putenv( 'MHM_CS_LICENSE_PING_SECRET=env-ping-secret' );

		$this->assertSame( 'env-resp-secret', ClientSecrets::get_response_hmac_secret() );
		$this->assertSame( 'env-ping-secret', ClientSecrets::get_ping_secret() );
	}

	public function test_trims_whitespace_from_env_values(): void {
		if ( defined( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET' ) ) {
			$this->markTestSkipped( 'Environment defines constants.' );
		}

		putenv( "MHM_CS_LICENSE_RESPONSE_HMAC_SECRET=  spaced-secret  \n" );
		$this->assertSame( 'spaced-secret', ClientSecrets::get_response_hmac_secret() );
	}

	public function test_two_secrets_resolve_to_distinct_constants(): void {
		// v0.6.0 — FEATURE_TOKEN_KEY removed; only RESPONSE_HMAC + PING remain.
		if ( defined( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET' ) ) {
			$this->markTestSkipped( 'Constants pre-defined.' );
		}

		putenv( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET=A' );
		putenv( 'MHM_CS_LICENSE_PING_SECRET=C' );

		$this->assertSame( 'A', ClientSecrets::get_response_hmac_secret() );
		$this->assertSame( 'C', ClientSecrets::get_ping_secret() );
	}
}
