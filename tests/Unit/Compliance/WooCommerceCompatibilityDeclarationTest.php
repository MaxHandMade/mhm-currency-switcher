<?php
/**
 * Compliance regression tests — the WooCommerce feature-compatibility
 * declarations in the main plugin file.
 *
 * `FeaturesUtil::declare_compatibility()` is a CLAIM WooCommerce shows to shop
 * owners. Undeclared, WooCommerce warns them away from the feature; falsely
 * declared, it stays silent while the shop breaks. Neither outcome is visible
 * from the plugin's own screens, and no linter reads a promise — it sees a
 * method call.
 *
 * 🔴 WHAT THIS FILE DOES NOT COVER, and why it lives in the unit suite:
 * it asserts the SOURCE, not the runtime registration. WooCommerce only
 * records a declaration for an ACTIVE plugin, and the integration harness
 * `require`s this plugin instead of activating it — `active_plugins` is empty
 * there, so `get_compatible_plugins_for_feature()` returns empty buckets for
 * every feature, including HPOS, which has been declared and working in
 * production for months. An assertion placed there would be permanently red
 * for a reason that has nothing to do with the plugin. Measured 2026-07-28 by
 * probing both buckets plus the `active_plugins` option inside the harness.
 *
 * The runtime half is verified out-of-band on a real WordPress where the
 * plugin is active:
 *
 *     wp eval 'var_dump( Automattic\WooCommerce\Utilities\FeaturesUtil
 *         ::get_compatible_plugins_for_feature( "cart_checkout_blocks", true ) );'
 *
 * `cart_checkout_blocks` is true because the blocks read their amounts from
 * the Store API, and ConversionContext treats a Store API request as a money
 * context (decision 5) and converts server-side — see ConversionContextTest.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;

/**
 * Class WooCommerceCompatibilityDeclarationTest
 *
 * @coversNothing
 */
class WooCommerceCompatibilityDeclarationTest extends TestCase {

	/**
	 * Features the plugin must declare itself compatible with.
	 *
	 * @var string[]
	 */
	private const REQUIRED_FEATURES = array(
		'custom_order_tables',
		'cart_checkout_blocks',
	);

	/**
	 * Read the main plugin file.
	 *
	 * @return string
	 */
	private function plugin_source(): string {
		$path = dirname( __DIR__, 3 ) . '/mhm-currency-switcher.php';

		$this->assertFileExists( $path, 'The main plugin file moved; this test is measuring nothing.' );

		return (string) file_get_contents( $path );
	}

	/**
	 * Every required feature is declared, with the compatibility flag TRUE.
	 *
	 * The `true` argument is asserted explicitly: flipping it to `false` is a
	 * one-character change that still reads like a declaration at the call
	 * site but lands the plugin in WooCommerce's "incompatible" bucket.
	 *
	 * @return void
	 */
	public function test_every_required_feature_is_declared_compatible(): void {
		$source = $this->plugin_source();

		foreach ( self::REQUIRED_FEATURES as $feature ) {
			$pattern = sprintf(
				'/declare_compatibility\(\s*[\'"]%s[\'"]\s*,\s*__FILE__\s*,\s*true\s*\)/',
				preg_quote( $feature, '/' )
			);

			$this->assertMatchesRegularExpression(
				$pattern,
				$source,
				sprintf(
					'The plugin no longer declares compatibility with the %s feature (or declares it false). WooCommerce will warn shop owners about it.',
					$feature
				)
			);
		}
	}

	/**
	 * The declarations run on `before_woocommerce_init`.
	 *
	 * Declaring on any later hook is silently too late: WooCommerce reads the
	 * registry while initialising, so a correct call on the wrong hook has the
	 * same effect as no call at all.
	 *
	 * @return void
	 */
	public function test_declarations_are_registered_on_before_woocommerce_init(): void {
		$source = $this->plugin_source();

		$this->assertSame(
			1,
			preg_match_all( '/add_action\(\s*\R?\s*[\'"]before_woocommerce_init[\'"]/', $source ),
			'Expected exactly one before_woocommerce_init registration in the main plugin file.'
		);

		$offset_hook = strpos( $source, 'before_woocommerce_init' );

		foreach ( self::REQUIRED_FEATURES as $feature ) {
			$offset_decl = strpos( $source, "declare_compatibility( '" . $feature . "'" );

			$this->assertIsInt(
				$offset_decl,
				sprintf( 'No declare_compatibility call found for %s.', $feature )
			);

			$this->assertGreaterThan(
				$offset_hook,
				$offset_decl,
				sprintf(
					'The %s declaration sits outside the before_woocommerce_init callback, so WooCommerce will have read its registry before it runs.',
					$feature
				)
			);
		}
	}

	/**
	 * The declarations are guarded by a FeaturesUtil existence check.
	 *
	 * The plugin supports WooCommerce 7.4, and calling an unconditionally
	 * missing class would fatal on the floor of its own supported range.
	 *
	 * @return void
	 */
	public function test_declarations_are_guarded_by_a_class_exists_check(): void {
		$this->assertMatchesRegularExpression(
			'/class_exists\(\s*\\\\?Automattic\\\\WooCommerce\\\\Utilities\\\\FeaturesUtil::class\s*\)/',
			$this->plugin_source(),
			'The FeaturesUtil guard is gone; on WooCommerce versions without the class the plugin would fatal.'
		);
	}
}
