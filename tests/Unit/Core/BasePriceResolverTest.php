<?php
/**
 * Unit tests for BasePriceResolver.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use MhmCurrencySwitcher\Core\BasePriceResolver;
use PHPUnit\Framework\TestCase;

/**
 * Class BasePriceResolverTest
 *
 * @covers \MhmCurrencySwitcher\Core\BasePriceResolver
 */
class BasePriceResolverTest extends TestCase {

	/**
	 * Reset the stubbed meta store between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['__mhmcs_test_post_meta'] = array();
	}

	/**
	 * Store a raw `_price` value for a product id.
	 *
	 * @param int   $id    Product id.
	 * @param mixed $value Raw stored value.
	 * @return void
	 */
	private function store_price( int $id, $value ): void {
		$GLOBALS['__mhmcs_test_post_meta'][ $id ]['_price'] = $value;
	}

	/**
	 * A normal stored price comes back as a float.
	 *
	 * @return void
	 */
	public function test_a_stored_price_is_returned_as_a_float(): void {
		$this->store_price( 7, '19.90' );

		$this->assertSame( 19.90, BasePriceResolver::for_product( 7 ) );
	}

	/**
	 * Zero is a real price and is preserved.
	 *
	 * Guards the fix below from over-reaching: a product genuinely priced at
	 * zero must not be reported as "no price".
	 *
	 * @return void
	 */
	public function test_a_genuine_zero_price_is_kept(): void {
		$this->store_price( 8, '0' );

		$this->assertSame( 0.0, BasePriceResolver::for_product( 8 ) );
	}

	/**
	 * A product with no stored price answers null.
	 *
	 * @return void
	 */
	public function test_an_absent_price_is_null(): void {
		$this->assertNull( BasePriceResolver::for_product( 999 ) );
	}

	/**
	 * 🔴 An unusable value is null, NOT zero.
	 *
	 * This is the defect class 1.3.1 swept out of the write paths, arriving
	 * from the other direction. `(float) 'INF'` and `(float) 'abc'` are both
	 * `0.0`, and zero is a legitimate price — so a garbage read silently
	 * becomes a free product, and no gate objects because nothing is invalid.
	 * The previous inline reads returned exactly that.
	 *
	 * @param mixed $stored Raw value as WooCommerce might leave it.
	 * @return void
	 *
	 * @dataProvider unusable_values
	 */
	public function test_an_unusable_value_is_null_rather_than_free( $stored ): void {
		$this->store_price( 11, $stored );

		$this->assertNull(
			BasePriceResolver::for_product( 11 ),
			'A value that is not a number must read as "no price", never as 0.0 — zero is a price.'
		);
	}

	/**
	 * Values that are not numbers.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function unusable_values(): array {
		return array(
			'empty string' => array( '' ),
			'text'         => array( 'abc' ),
			'infinity'     => array( 'INF' ),
			'array'        => array( array( '19.90' ) ),
			'null'         => array( null ),
		);
	}

	/**
	 * A non-positive id is refused without touching the meta store.
	 *
	 * @return void
	 */
	public function test_a_non_positive_id_is_null(): void {
		$this->store_price( 0, '19.90' );

		$this->assertNull( BasePriceResolver::for_product( 0 ) );
		$this->assertNull( BasePriceResolver::for_product( -3 ) );
	}
}
