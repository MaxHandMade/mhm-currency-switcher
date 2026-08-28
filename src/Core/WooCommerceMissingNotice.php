<?php
/**
 * The WooCommerce-missing admin notice.
 *
 * @package MhmCurrencySwitcher\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells the shop owner WooCommerce is missing — and only a shop owner who
 * can act on it, and only on a screen where they would expect to see it.
 *
 * Pulled out of mhm-currency-switcher.php, where it is wired from
 * `plugins_loaded` before `Plugin::bootstrap()` runs, into its own class so
 * the capability gate and the screen scope can be unit tested directly: a
 * closure registered with `add_action()` in the main plugin file has no
 * seam a test can reach.
 *
 * @since 2.1.0
 */
final class WooCommerceMissingNotice {

	/**
	 * Screens this notice may appear on.
	 *
	 * 🔴 Deliberately core screens, not "this plugin's screens". Without
	 * WooCommerce, `Plugin::bootstrap()` never runs, so `Settings` never
	 * registers its `admin_menu` entry — the plugin has NO screens of its
	 * own at all in this scenario. Scoping to them would scope the notice
	 * to the empty set, and a shop owner missing WooCommerce would never
	 * see the notice that says so. `plugins` and `update-core` are where
	 * an admin goes to fix the problem; `dashboard` is where they land
	 * first and are most likely to notice it.
	 *
	 * @var string[]
	 */
	private const SCREENS = array( 'plugins', 'dashboard', 'update-core' );

	/**
	 * Whether the given capability and screen together permit this notice.
	 *
	 * Pure given its inputs, so the capability gate and the screen scope can
	 * each be exercised directly without a real wp-admin request. Takes the
	 * screen id as a plain nullable string rather than a WP_Screen instance
	 * — the caller already has to null-guard get_current_screen() before it
	 * has an id to read, so that null case is represented here too.
	 *
	 * @param bool        $can_activate_plugins Whether the current user has activate_plugins.
	 * @param string|null $screen_id            Current screen id, or null when unset.
	 * @return bool True when the notice may render.
	 */
	public static function is_visible( bool $can_activate_plugins, ?string $screen_id ): bool {
		if ( ! $can_activate_plugins ) {
			return false;
		}

		if ( null === $screen_id ) {
			return false;
		}

		return in_array( $screen_id, self::SCREENS, true );
	}

	/**
	 * Print the notice, if the current request is allowed to see it.
	 *
	 * `get_current_screen()` returns null before the screen has been set up
	 * (it is not available before `admin_init`), so the id it hands
	 * `is_visible()` is null in that case rather than a fatal error from
	 * reading `->id` off nothing.
	 *
	 * @return void
	 */
	public static function render(): void {
		$screen    = get_current_screen();
		$screen_id = ( null !== $screen ) ? (string) $screen->id : null;

		if ( ! self::is_visible( current_user_can( 'activate_plugins' ), $screen_id ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html__(
				'MHM Currency Switcher requires WooCommerce to be installed and activated.',
				'mhm-currency-switcher'
			)
		);
	}
}
