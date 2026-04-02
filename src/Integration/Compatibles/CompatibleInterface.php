<?php
/**
 * Compatibility module interface.
 *
 * All third-party plugin compatibility modules must implement
 * this interface to provide a consistent activation and init API.
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
 * CompatibleInterface — contract for compatibility modules.
 *
 * @since 0.4.0
 */
interface CompatibleInterface {

	/**
	 * Check if the target plugin is active.
	 *
	 * @return bool True when the target plugin is loaded.
	 */
	public static function is_active(): bool;

	/**
	 * Initialize compatibility hooks.
	 *
	 * @return void
	 */
	public function init(): void;
}
