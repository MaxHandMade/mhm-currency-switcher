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
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAsA2VC900IzZuqS+PDtQE
Fy+6Hc13GGvQu6UbjHCg56ZLF4h2PA0GdwkcpL/pI3RFADPXiVwUHX/qfrt+GcVK
k/enicekPd5/D3HNTOX0jcZ90BielzGNldL3WuQwTZXD8tiOWBGKm3U53aRnoP2P
kYX9pikCOQY5Ylbl3UzSFExp/GcjKj1k6oEmE2LLk11fv/A2iSssqwID/0BLUy9r
/W5Ge559XECvpztqjjbioV0FpueH3C+CuW4lKqGXphJbvPr/DXgujo18ur8ADIGX
qtd/TtHjMZdudOsEraIaZB9VoBDQ7v0ntlZstepIeYMcgEwO6ViaH7lpf7080tG8
8QIDAQAB
-----END PUBLIC KEY-----
PEM;

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

		$key = openssl_pkey_get_public( self::PEM );
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
}
