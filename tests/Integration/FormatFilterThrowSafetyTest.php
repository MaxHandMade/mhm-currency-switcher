<?php
/**
 * FormatFilter borrows WooCommerce's filter stack twice, and both borrowings
 * have to be returned even when something in between throws.
 *
 * `PreviewRendererParityTest` already pins this shape for PreviewRenderer's own
 * pair. These two were left unpinned: an independent review stripped both
 * `finally` blocks, restoring the pre-fix sequential form, and the unit suite
 * (359), the integration suite (171) and jest (30) all stayed green.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use MhmCurrencySwitcher\Integration\WooCommerce\FormatFilter;

/**
 * Class FormatFilterThrowSafetyTest
 */
class FormatFilterThrowSafetyTest extends MhmcsIntegrationTestCase {

	/**
	 * Callbacks registered on a hook at a given priority, or an empty array.
	 *
	 * @param string $hook     Hook name.
	 * @param int    $priority Priority.
	 * @return array<string, mixed>
	 */
	private function callbacks_at( string $hook, int $priority ): array {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return array();
		}

		return $wp_filter[ $hook ]->callbacks[ $priority ] ?? array();
	}

	/**
	 * 🔴 `price_format_for_position()` registers a PHP_INT_MAX override on
	 * `pre_option_woocommerce_currency_pos` and removes it on the next line.
	 *
	 * PreviewRenderer calls this from INSIDE its own `wc_price_args` closure,
	 * and PreviewRenderer's docblock claims nothing it registers outlives the
	 * call. Without the `finally` that claim was false: a throw from any
	 * third-party `woocommerce_price_format` hook left a PHP_INT_MAX position
	 * override registered for the rest of the request, which decides where the
	 * currency symbol goes for every price rendered afterwards.
	 *
	 * @return void
	 */
	public function test_the_position_override_is_removed_even_when_the_format_lookup_throws(): void {
		$thrower = static function ( $format ) {
			throw new \RuntimeException( 'forced for the finally test' );
		};

		add_filter( 'woocommerce_price_format', $thrower, 10, 1 );

		$threw = false;

		try {
			FormatFilter::price_format_for_position( 'right_space' );
		} catch ( \RuntimeException $e ) {
			$threw = true;
		} finally {
			remove_filter( 'woocommerce_price_format', $thrower, 10 );
		}

		$this->assertTrue(
			$threw,
			'The forced exception did not propagate out of price_format_for_position() -- this test proves nothing without it.'
		);

		$this->assertSame(
			array(),
			$this->callbacks_at( 'pre_option_woocommerce_currency_pos', PHP_INT_MAX ),
			'The PHP_INT_MAX position override survived a throw and now decides symbol placement for the rest of the request.'
		);
	}

	/**
	 * 🔴 `apply_shop_format()` is the storefront path and the more dangerous of
	 * the two: it REMOVES the plugin's own four format filters before reading
	 * the shop's values, then re-adds them.
	 *
	 * Without the `finally`, a throw between the two halves left the plugin's
	 * formatting disabled for the remainder of the request — every later price
	 * on the page rendering in the shop's format instead of the visitor's
	 * currency, silently, on a request that usually survives.
	 *
	 * Reached through the public `wc_price_args` filter rather than by calling
	 * the private method, so this pins the path a page actually takes.
	 *
	 * @return void
	 */
	public function test_the_shop_format_borrow_restores_the_plugin_filters_even_on_a_throw(): void {
		/*
		 * The live instance the plugin registered at bootstrap, driven through
		 * the public filter — a hand-built instance would prove nothing about
		 * the registered one.
		 *
		 * Reaching apply_shop_format() takes three conditions, and the first
		 * version of this test met none of them and passed a `false is true`
		 * assertion instead of a silent green: the visitor's currency must own
		 * the display, the currency NAMED in the args must differ from the
		 * visitor's, and the plugin must hold no format for it. EUR as the
		 * visitor, USD (the base, which has no row of its own) as the named
		 * currency satisfies all three.
		 */
		$this->configure_currency(
			array(
				'code'    => 'EUR',
				'enabled' => true,
				'rate'    => array( 'type' => 'manual', 'value' => 0.9 ),
				'format'  => array( 'decimal_sep' => ',', 'thousand_sep' => '.', 'decimals' => 2, 'position' => 'right_space' ),
			),
			'USD'
		);

		$this->set_visitor_currency( 'EUR' );

		$hooks = array(
			'wc_get_price_decimal_separator',
			'wc_get_price_thousand_separator',
			'wc_get_price_decimals',
			'pre_option_woocommerce_currency_pos',
		);

		foreach ( $hooks as $hook ) {
			$this->assertNotSame(
				array(),
				$this->callbacks_at( $hook, 100 ),
				"init() did not register {$hook} at priority 100 -- the fixture is wrong, not the code."
			);
		}

		$thrower = static function ( $sep ) {
			throw new \RuntimeException( 'forced for the finally test' );
		};

		// Fires while apply_shop_format() has the plugin's own filters removed.
		add_filter( 'wc_get_price_decimal_separator', $thrower, 200, 1 );

		$threw = false;

		try {
			apply_filters( 'wc_price_args', array( 'currency' => 'USD' ) );
		} catch ( \RuntimeException $e ) {
			$threw = true;
		} finally {
			remove_filter( 'wc_get_price_decimal_separator', $thrower, 200 );
		}

		$this->assertTrue(
			$threw,
			'The forced exception did not propagate -- this test proves nothing without it. '
				. 'If apply_shop_format() was not reached, the fixture needs fixing, not the assertion.'
		);

		foreach ( $hooks as $hook ) {
			$this->assertNotSame(
				array(),
				$this->callbacks_at( $hook, 100 ),
				"The plugin's {$hook} filter was removed by apply_shop_format() and never restored. "
					. 'Every price rendered later in this request falls back to the shop format.'
			);
		}
	}
}
