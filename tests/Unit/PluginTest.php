<?php
/**
 * Smoke tests for the Plugin class.
 *
 * Global-namespace stubs are defined first so that the main plugin file
 * can call WordPress functions (plugin_dir_path, add_action, etc.)
 * without a real WordPress environment.
 *
 * @package MhmCurrencySwitcher\Tests\Unit
 */

declare(strict_types=1);

/*
 * ---------- Global namespace stubs ----------
 * Must be defined outside any namespace block so they are available
 * as global functions when the main plugin file calls them.
 */
namespace {
	if ( ! function_exists( 'plugin_dir_path' ) ) {
		function plugin_dir_path( string $file ): string {
			return dirname( $file ) . '/';
		}
	}

	if ( ! function_exists( 'plugin_dir_url' ) ) {
		function plugin_dir_url( string $file ): string {
			return 'https://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
		}
	}

	if ( ! function_exists( 'plugin_basename' ) ) {
		function plugin_basename( string $file ): string {
			return basename( dirname( $file ) ) . '/' . basename( $file );
		}
	}

	if ( ! function_exists( 'add_action' ) ) {
		function add_action( ...$args ): void {}
	}

	if ( ! function_exists( 'register_activation_hook' ) ) {
		function register_activation_hook( ...$args ): void {}
	}

	if ( ! function_exists( 'register_deactivation_hook' ) ) {
		function register_deactivation_hook( ...$args ): void {}
	}
}

/*
 * ---------- Test class ----------
 */
namespace MhmCurrencySwitcher\Tests\Unit {

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

				require_once dirname( __DIR__, 2 ) . '/mhm-currency-switcher.php';
			}

			$this->assertTrue( defined( 'MHM_CS_VERSION' ) );
			$this->assertSame( '0.2.0', MHM_CS_VERSION );
		}
	}
}
