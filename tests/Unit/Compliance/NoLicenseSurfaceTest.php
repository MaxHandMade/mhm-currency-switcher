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
				'payment_methods' => array( 'all' ),
				'countries'       => array(),
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

			$source = file_get_contents( $file->getPathname() );

			if ( is_string( $source ) && preg_match( '/\bMHM_CS_|\bmhm_cs_|mhm_currency_switcher_/', $source ) ) {
				$offenders[] = str_replace( $root . '/', '', $file->getPathname() );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Legacy split prefix found in:\n" . implode( "\n", $offenders )
		);
	}
}
