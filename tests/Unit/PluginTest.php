<?php
/**
 * Smoke tests for the Plugin class.
 *
 * @package MhmCurrencySwitcher\Tests\Unit
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit;

use MhmCurrencySwitcher\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Class PluginTest
 *
 * @covers \MhmCurrencySwitcher\Plugin
 */
class PluginTest extends TestCase {

	/**
	 * Test that the Plugin class exists.
	 *
	 * @return void
	 */
	public function test_plugin_class_exists(): void {
		$this->assertTrue( class_exists( Plugin::class ) );
	}

	/**
	 * Test that the version constant is defined after loading the main file.
	 *
	 * @return void
	 */
	public function test_version_constant_is_defined(): void {
		// Load the main plugin file if constants are not yet defined.
		if ( ! defined( 'MHM_CS_VERSION' ) ) {
			// Define ABSPATH so the file doesn't exit early.
			if ( ! defined( 'ABSPATH' ) ) {
				define( 'ABSPATH', sys_get_temp_dir() . '/' );
			}

			// Define stub functions needed by the main plugin file.
			if ( ! function_exists( 'plugin_dir_path' ) ) {
				/**
				 * Stub for plugin_dir_path.
				 *
				 * @param string $file File path.
				 * @return string
				 */
				function plugin_dir_path( string $file ): string { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
					return dirname( $file ) . '/';
				}
			}

			if ( ! function_exists( 'plugin_dir_url' ) ) {
				/**
				 * Stub for plugin_dir_url.
				 *
				 * @param string $file File path.
				 * @return string
				 */
				function plugin_dir_url( string $file ): string { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
					return 'https://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
				}
			}

			if ( ! function_exists( 'plugin_basename' ) ) {
				/**
				 * Stub for plugin_basename.
				 *
				 * @param string $file File path.
				 * @return string
				 */
				function plugin_basename( string $file ): string { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
					return basename( dirname( $file ) ) . '/' . basename( $file );
				}
			}

			if ( ! function_exists( 'add_action' ) ) {
				/**
				 * Stub for add_action.
				 *
				 * @param mixed ...$args Hook arguments.
				 * @return void
				 */
				function add_action( ...$args ): void {} // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
			}

			if ( ! function_exists( 'register_activation_hook' ) ) {
				/**
				 * Stub for register_activation_hook.
				 *
				 * @param mixed ...$args Hook arguments.
				 * @return void
				 */
				function register_activation_hook( ...$args ): void {} // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
			}

			if ( ! function_exists( 'register_deactivation_hook' ) ) {
				/**
				 * Stub for register_deactivation_hook.
				 *
				 * @param mixed ...$args Hook arguments.
				 * @return void
				 */
				function register_deactivation_hook( ...$args ): void {} // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
			}

			require_once dirname( __DIR__, 2 ) . '/mhm-currency-switcher.php';
		}

		$this->assertTrue( defined( 'MHM_CS_VERSION' ) );
		$this->assertSame( '0.1.0', MHM_CS_VERSION );
	}
}
