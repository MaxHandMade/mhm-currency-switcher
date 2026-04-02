<?php
/**
 * MHM Rentiva compatibility module.
 *
 * Provides hooks for converting vehicle rental prices when the
 * MHM Rentiva plugin is active alongside the currency switcher.
 *
 * @package MhmCurrencySwitcher\Integration\Compatibles
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Integration\Compatibles;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MhmRentiva — currency conversion for Rentiva rental prices.
 *
 * Stub implementation. Full conversion hooks will be added when
 * Rentiva exposes price filters for daily/extra pricing.
 *
 * @since 0.4.0
 */
final class MhmRentiva implements CompatibleInterface {

	/**
	 * Check if MHM Rentiva is active.
	 *
	 * @return bool True when the MHM_RENTIVA_VERSION constant is defined.
	 */
	public static function is_active(): bool {
		return defined( 'MHM_RENTIVA_VERSION' );
	}

	/**
	 * Initialize compatibility hooks.
	 *
	 * Placeholder hooks for future Rentiva integration.
	 * Full implementation when Rentiva exposes price filters.
	 *
	 * @return void
	 */
	public function init(): void {
		// Daily rental price conversion.
		// add_filter( 'mhm_rentiva_daily_price', array( $this, 'convert_daily_price' ), 10, 2 );

		// Extra/addon price conversion.
		// add_filter( 'mhm_rentiva_extra_price', array( $this, 'convert_extra_price' ), 10, 2 );
	}
}
