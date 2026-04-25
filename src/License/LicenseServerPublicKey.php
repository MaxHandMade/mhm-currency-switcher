<?php
/**
 * Embedded RSA public key for verifying license-server feature tokens.
 *
 * @package MhmCurrencySwitcher\License
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\License;

use OpenSSLAsymmetricKey;
use RuntimeException;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Embedded RSA public key for verifying license-server feature tokens.
 *
 * Since v0.6.0, the server (`mhm-license-server` v1.10.0+) signs feature
 * tokens with its private key (held only in wp-config). This class ships
 * the matching public key as a class constant so the customer never has
 * to configure anything (zero-config UX preserved). Public keys can verify
 * but cannot mint signatures, so a cracked binary cannot forge a token.
 *
 * Release process:
 * 1. Generate the production key pair on a trusted dev machine ONCE
 *    (`openssl genpkey ... && openssl pkey -in ... -pubout`).
 * 2. Embed the production public PEM into the {@see self::PEM} nowdoc
 *    BEFORE tagging the v0.6.0 release. The fixture key below is the
 *    development-time placeholder paired with `tests/fixtures/`.
 * 3. Deploy the matching private key to the server's wp-config
 *    (`MHM_LICENSE_SERVER_RSA_PRIVATE_KEY_PEM`) at the same time.
 *
 * The {@see tests/fixtures/test-rsa-public.pem} file is byte-identical to
 * this constant during development so the unit suite exercises real
 * `openssl_verify` calls. When the constant is swapped for the production
 * PEM at release time, tests still pass because they inject the fixture
 * public key into {@see FeatureTokenVerifier} directly via constructor DI.
 *
 * @since 0.6.0
 */
final class LicenseServerPublicKey {

	/**
	 * RSA-2048 SubjectPublicKeyInfo PEM.
	 *
	 * @internal Replace with production public.pem contents before tagging.
	 *           Currently paired with tests/fixtures/test-rsa-private.pem
	 *           so the dev suite passes against the test fixture pair.
	 */
	private const PEM = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAg0X/caFG421pZYjWr32K
QFCBZ6IR9jYzDvhBi3XoiBuurlPh3mLe+qFsbd/7i/gQUSuQyR/AFv06E3spWwl4
cCgkGMLAd6C1T3OwzYwEvO3usH/L+BSj46d2fTXRqk8blcyKo6RvVRQQQM6U+5oo
TY659FczWbI1uDhfnlhI9/+ty/+R/r9c5oGU63eN+bBPpGc/qPC2bXFNwkTSLddq
blipBseQXE3RawtO30EE3EwpNTdUQP38356zMmL4nOVBdYMYQJDv0g0LFYKIWICK
yeBShuViW+dPlyKZ4MicYuHFNGI58yicmOgcQ/bmGXCXXq7sorfdqejw9xApdPdj
iwIDAQAB
-----END PUBLIC KEY-----
PEM;

	/**
	 * Test-only PEM override.
	 *
	 * Production paths must never set this. The unit suite pins this to
	 * the fixture public key in {@see tests/bootstrap.php} so the RSA
	 * verify chain (Mode → FeatureTokenVerifier → openssl_verify) can
	 * exercise tokens signed with the paired fixture private key. Without
	 * this override, swapping in the production public key for release
	 * would force every fixture-bound test to re-sign tokens with the
	 * production private key — which we explicitly do NOT ship.
	 *
	 * @internal
	 *
	 * @var string|null
	 */
	private static ?string $test_pem_override = null;

	/**
	 * Cached parsed public key resource.
	 *
	 * @var OpenSSLAsymmetricKey|null
	 */
	private static ?OpenSSLAsymmetricKey $cached_key = null;

	/**
	 * Get the embedded public key parsed into an OpenSSL resource.
	 *
	 * Cached per-request — the gate methods call this on every page load,
	 * we don't want to re-parse the PEM each time.
	 *
	 * @return OpenSSLAsymmetricKey Parsed key resource.
	 *
	 * @throws RuntimeException If the embedded PEM is malformed (release-time
	 *                          regression — the swap-in production key was
	 *                          mangled).
	 */
	public static function resource(): OpenSSLAsymmetricKey {
		if ( null !== self::$cached_key ) {
			return self::$cached_key;
		}

		$pem = self::$test_pem_override ?? self::PEM;
		$key = openssl_pkey_get_public( $pem );
		if ( false === $key ) {
			throw new RuntimeException(
				esc_html(
					'LicenseServerPublicKey: embedded PEM failed to parse - release process bug. '
					. (string) openssl_error_string()
				)
			);
		}

		self::$cached_key = $key;
		return $key;
	}

	/**
	 * Drop the cached resource. Tests use this between cases that swap the
	 * embedded constant view via reflection or compare resource identity.
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$cached_key = null;
	}

	/**
	 * Pin the resource() output to a caller-supplied PEM (test-only).
	 *
	 * The PHPUnit bootstrap calls this once at suite start with the fixture
	 * public key so every fixture-signed token in the suite verifies against
	 * the matching fixture public key — even after the embedded production
	 * PEM constant has been swapped in for release. Production code must
	 * never call this method.
	 *
	 * @param string $pem PEM string to use instead of the embedded constant.
	 * @return void
	 */
	public static function inject_for_testing( string $pem ): void {
		self::$test_pem_override = $pem;
		self::$cached_key        = null;
	}

	/**
	 * Drop the test-only PEM override. Restores the embedded production
	 * constant on the next resource() call.
	 *
	 * @return void
	 */
	public static function clear_test_override(): void {
		self::$test_pem_override = null;
		self::$cached_key        = null;
	}
}
