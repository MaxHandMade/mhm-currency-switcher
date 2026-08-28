<?php
/**
 * Settings default value owner for the `mhmcs_settings` option.
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
 * SettingsStore — owns the default shape of `mhmcs_settings`.
 *
 * @since 2.1.0
 */
final class SettingsStore {

	/**
	 * Default value for the `mhmcs_settings` option.
	 *
	 * Deliberately the only definition. Activation seeds from here; a second
	 * copy elsewhere is how the two drift apart — the defect that produced the
	 * 1.0.0 migration in the first place. Symmetric with
	 * CurrencyStore::default_option_value(), which owns `mhmcs_currencies`.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings(): array {
		return array(
			'auto_detect'  => true,
			// Read by ConversionContext decision 4; written by the
			// Advanced Settings tab. Same spelling in all three.
			'cache_compat' => true,
			'switcher'     => array(
				'show_flag'   => true,
				'show_name'   => false,
				'show_symbol' => true,
				'show_code'   => true,
				'size'        => 'medium',
			),
		);
	}
}
