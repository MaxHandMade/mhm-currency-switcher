<?php
/**
 * Compliance regression tests — WordPress.org Guideline 5.
 *
 * These tests fail if any licence gate, quota, or Pro-tier API is
 * reintroduced into the plugin. They are the machine-checkable form of
 * the "no restricted or locked functionality" rule.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use MhmCurrencySwitcher\Core\CurrencyStore;
use PHPUnit\Framework\TestCase;

/**
 * Class NoLicenseSurfaceTest
 *
 * @coversNothing
 */
class NoLicenseSurfaceTest extends TestCase {

	/**
	 * Identifiers WordPress.org's prefix checker reads as the 3-letter
	 * prefix `mhm` and rejects as too short.
	 *
	 * @var string
	 */
	private const LEGACY_PREFIX_PATTERN = '/\bMHM_CS_\w*|\bmhm_cs_\w*|mhm_currency_switcher_\w*/';

	/**
	 * The single file allowed to name the pre-0.3.0 keys, because reading
	 * them is its entire purpose.
	 *
	 * @var string
	 */
	private const MIGRATOR_RELATIVE_PATH = 'src/Core/LegacyOptionMigrator.php';

	/**
	 * CurrencyStore must not expose any free-tier quota API.
	 *
	 * @return void
	 */
	public function test_currency_store_has_no_quota_api(): void {
		$this->assertFalse(
			method_exists( CurrencyStore::class, 'enforce_limit' ),
			'CurrencyStore::enforce_limit() is a free-tier quota — forbidden by WP.org Guideline 5.'
		);

		$this->assertFalse(
			method_exists( CurrencyStore::class, 'set_free_limit' ),
			'CurrencyStore::set_free_limit() is a free-tier quota — forbidden by WP.org Guideline 5.'
		);
	}

	/**
	 * An unlimited number of currencies must survive a store round-trip.
	 *
	 * @return void
	 */
	public function test_store_retains_more_than_two_currencies(): void {
		$store = new CurrencyStore();
		$codes = array( 'EUR', 'GBP', 'TRY', 'JPY', 'CHF' );

		$currencies = array();
		foreach ( $codes as $index => $code ) {
			$currencies[] = array(
				'code'            => $code,
				'enabled'         => true,
				'sort_order'      => $index,
				'rate'            => array(
					'type'  => 'auto',
					'value' => 1.0,
				),
				'fee'             => array(
					'type'  => 'fixed',
					'value' => 0,
				),
				'rounding'        => array(
					'type'     => 'disabled',
					'value'    => 0,
					'subtract' => 0,
				),
				'format'          => array(
					'symbol'       => $code,
					'position'     => 'left',
					'thousand_sep' => ',',
					'decimal_sep'  => '.',
					'decimals'     => 2,
				),
			);
		}

		$store->set_data( 'USD', $currencies );

		$this->assertCount(
			5,
			$store->get_currencies(),
			'All five currencies must be retained — there is no free-tier cap.'
		);
	}

	/**
	 * ProductPricing must register its hooks unconditionally.
	 *
	 * Fixed per-product prices used to be gated behind a licence check;
	 * a `Mode::` reference in this class means the gate came back.
	 *
	 * @return void
	 */
	public function test_product_pricing_is_not_licence_gated(): void {
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Integration/WooCommerce/ProductPricing.php'
		);

		$this->assertIsString( $source, 'ProductPricing.php must be readable.' );

		$this->assertStringNotContainsString(
			'Mode::',
			$source,
			'ProductPricing must not consult a licence gate.'
		);
	}

	/**
	 * All identifiers must use the single-token `mhmcs` prefix.
	 *
	 * WordPress.org's prefix checker splits on the first underscore, so
	 * `mhm_cs_foo` is read as the 3-letter prefix `mhm` and rejected as
	 * too short. A single token of 4+ characters is required.
	 *
	 * @return void
	 */
	public function test_no_legacy_split_prefix_remains(): void {
		$root  = dirname( __DIR__, 3 );
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/src' )
		);

		$offenders = array();

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			/*
			 * The one file whose job is to read the old names. Exempt here
			 * and pinned exactly in the test below, rather than given a
			 * blanket pass: an exemption nobody measures is how this oracle
			 * would stop seeing the thing it was written to catch.
			 */
			if ( self::MIGRATOR_RELATIVE_PATH === self::relative_path( $root, $file->getPathname() ) ) {
				continue;
			}

			$source = file_get_contents( $file->getPathname() );

			if ( is_string( $source ) && preg_match( self::LEGACY_PREFIX_PATTERN, $source ) ) {
				$offenders[] = self::relative_path( $root, $file->getPathname() );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Legacy split prefix found in:\n" . implode( "\n", $offenders )
		);
	}

	/**
	 * The migration may name the legacy keys it carries — and only those.
	 *
	 * Comments are skipped and the check runs over PHP tokens rather than
	 * raw text, so the pin describes what the code does instead of how the
	 * docblocks are worded. Add a legacy name to this file and the oracle
	 * goes red until someone decides, on purpose, that it belongs here.
	 *
	 * @return void
	 */
	public function test_the_migration_names_only_the_legacy_keys_it_carries(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/' . self::MIGRATOR_RELATIVE_PATH );

		$this->assertIsString( $source, 'LegacyOptionMigrator.php must be readable.' );

		$found = array();

		foreach ( token_get_all( $source ) as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}

			if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML ), true ) ) {
				continue;
			}

			if ( preg_match_all( self::LEGACY_PREFIX_PATTERN, $token[1], $matches ) ) {
				$found = array_merge( $found, $matches[0] );
			}
		}

		$found = array_values( array_unique( $found ) );
		sort( $found );

		$expected = array(
			'mhm_currency_switcher_currencies',
			'mhm_currency_switcher_settings',
			'mhm_cs_update_rates',
		);
		sort( $expected );

		$this->assertSame(
			$expected,
			$found,
			'The migration names a legacy identifier that is not one of the keys it carries.'
		);
	}

	/**
	 * Path relative to the repository root, in forward-slash form so the
	 * comparison behaves the same on Windows and Linux.
	 *
	 * @param string $root Repository root.
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function relative_path( string $root, string $path ): string {
		// Normalise the separators BEFORE stripping the root. On Windows
		// the iterator hands back a mix of both, so stripping first leaves
		// the root in place and every comparison below silently misses.
		$root = str_replace( '\\', '/', $root );
		$path = str_replace( '\\', '/', $path );

		return str_replace( $root . '/', '', $path );
	}
}
