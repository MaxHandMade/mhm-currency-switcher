<?php
declare( strict_types=1 );

namespace MhmCurrencySwitcher\Tests\Unit\Core;

use MhmCurrencySwitcher\Core\SettingsStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MhmCurrencySwitcher\Core\SettingsStore
 */
final class SettingsStoreTest extends TestCase {

	/**
	 * The value-shape lock that used to live in LegacyOptionMigratorTest:359.
	 * Activation seeds mhmcs_settings from here; if a key silently changes
	 * shape -- or a boolean flips -- every fresh install gets it and no
	 * other test would say so. Every one of the eight documented values is
	 * asserted individually; 'show_name' is the only `false` among them and
	 * a transcription slip flipping it to `true` must fail here.
	 */
	public function test_default_settings_carries_the_documented_keys(): void {
		$defaults = SettingsStore::default_settings();

		$this->assertIsArray( $defaults );

		$this->assertArrayHasKey( 'auto_detect', $defaults );
		$this->assertTrue( $defaults['auto_detect'], 'auto_detect ships ON by default.' );

		$this->assertArrayHasKey( 'cache_compat', $defaults );
		$this->assertTrue( $defaults['cache_compat'], 'cache_compat ships ON by default.' );

		$this->assertArrayHasKey( 'switcher', $defaults );
		$this->assertIsArray( $defaults['switcher'] );

		$switcher = $defaults['switcher'];

		$this->assertArrayHasKey( 'show_flag', $switcher );
		$this->assertTrue( $switcher['show_flag'], 'show_flag ships ON by default.' );

		$this->assertArrayHasKey( 'show_name', $switcher );
		$this->assertFalse( $switcher['show_name'], 'show_name is the only default that ships OFF.' );

		$this->assertArrayHasKey( 'show_symbol', $switcher );
		$this->assertTrue( $switcher['show_symbol'], 'show_symbol ships ON by default.' );

		$this->assertArrayHasKey( 'show_code', $switcher );
		$this->assertTrue( $switcher['show_code'], 'show_code ships ON by default.' );

		$this->assertArrayHasKey( 'size', $switcher );
		$this->assertSame( 'medium', $switcher['size'], 'size defaults to medium.' );

		// Exactly the documented shape -- no extra top-level or nested keys.
		$this->assertSame( array( 'auto_detect', 'cache_compat', 'switcher' ), array_keys( $defaults ) );
		$this->assertSame(
			array( 'show_flag', 'show_name', 'show_symbol', 'show_code', 'size' ),
			array_keys( $switcher )
		);
	}
}
