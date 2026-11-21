<?php
/**
 * Translation precedence: the language pack wins, the bundled catalogue fills
 * the keys the pack lacks and is the only catalogue when no pack exists.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- core's textdomain registry is replaced per test and restored in tear_down.

use MhmCurrencySwitcher\I18n\LanguagePackPreference;
use WP_Textdomain_Registry;
use WP_UnitTestCase;

/**
 * Before this class, a locale with a WP.org pack read only the pack, so strings
 * added after its last import showed in English although languages/ had them.
 */
class LanguagePackPreferenceTest extends WP_UnitTestCase {

	private const DOMAIN = 'mhm-currency-switcher';
	private const LOCALE = 'tr_TR';
	private const HANDLE = 'mhmcs-i18n-test';

	/** A source URL outside this site's plugins/content URLs, like a plugin checked out beside the WP install (CI). */
	private const SRC = 'https://assets.example.test/admin-app/build/index.js';

	/**
	 * Fake pack files created by the test (removed in tear_down).
	 *
	 * @var string[]
	 */
	private array $created = array();

	/**
	 * The registry the test replaces.
	 *
	 * @var mixed
	 */
	private $orig_registry;

	private string $locale = self::LOCALE;

	public function set_up(): void {
		parent::set_up();
		$this->orig_registry = $GLOBALS['wp_textdomain_registry'];
		$this->reset_state();
		$this->locale = self::LOCALE;
		add_filter( 'locale', fn() => $this->locale );
		if ( ! is_dir( WP_LANG_DIR . '/plugins' ) ) {
			wp_mkdir_p( WP_LANG_DIR . '/plugins' );
		}
		if ( ! wp_is_writable( WP_LANG_DIR . '/plugins' ) ) {
			// Never a silent skip: WP_LANG_DIR is fixed by the WP test library, so the test cannot relocate it.
			$this->fail( 'WP_LANG_DIR/plugins (' . WP_LANG_DIR . '/plugins) must be writable: these tests write fake language packs there.' );
		}
	}

	public function tear_down(): void {
		foreach ( $this->created as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture.
			}
		}
		$this->created = array();
		wp_deregister_script( self::HANDLE );
		$GLOBALS['wp_textdomain_registry'] = $this->orig_registry;
		$this->reset_state();
		parent::tear_down();
	}

	/**
	 * Forgets everything the loader cached about the domain (registry paths, file lists, loaded catalogues).
	 */
	private function reset_state(): void {
		unload_textdomain( self::DOMAIN );
		unset( $GLOBALS['l10n_unloaded'][ self::DOMAIN ] );
		$GLOBALS['wp_textdomain_registry'] = new WP_Textdomain_Registry();
		wp_cache_flush();
	}

	private function write_pack( string $name, string $contents ): string {
		$file            = WP_LANG_DIR . '/plugins/' . $name;
		$this->created[] = $file;
		file_put_contents( $file, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- Test fixture.
		$this->reset_state();
		return $file;
	}

	private function tr( string $text ): string {
		return translate( $text, self::DOMAIN ); // phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction,WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain -- Dynamic by design.
	}

	public function test_php_without_a_pack_uses_the_plugin_catalogue(): void {
		$this->assertFileDoesNotExist( WP_LANG_DIR . '/plugins/' . self::DOMAIN . '-' . self::LOCALE . '.mo', 'Precondition: no pack installed.' );
		$this->assertSame( 'Para Birimi', $this->tr( 'Currency' ) );
		$this->assertSame( 'Otomatik', $this->tr( 'Auto' ) );
	}

	public function test_php_pack_wins_and_plugin_catalogue_fills_missing_keys(): void {
		$this->write_pack(
			self::DOMAIN . '-' . self::LOCALE . '.l10n.php',
			"<?php\nreturn array( 'domain' => 'mhm-currency-switcher', 'language' => 'tr_TR', 'plural-forms' => 'nplurals=2; plural=(n > 1);', 'messages' => array( 'Currency' => 'PACK-Para' ) );\n"
		);
		$this->assertSame( 'PACK-Para', $this->tr( 'Currency' ), 'The pack must win where it has the key.' );
		$this->assertSame( 'Otomatik', $this->tr( 'Auto' ), 'A key missing in the pack falls back to the plugin catalogue.' );
		$this->assertSame( 'Untranslated source string xyz', $this->tr( 'Untranslated source string xyz' ) );
	}

	public function test_php_pack_as_mo_file_also_wins(): void {
		$mo = new \MO();
		$mo->set_header( 'Language', self::LOCALE );
		$mo->set_header( 'Plural-Forms', 'nplurals=2; plural=(n > 1);' );
		$mo->add_entry(
			new \Translation_Entry(
				array(
					'singular'     => 'Currency',
					'translations' => array( 'MO-Para' ),
				)
			)
		);
		$file            = WP_LANG_DIR . '/plugins/' . self::DOMAIN . '-' . self::LOCALE . '.mo';
		$this->created[] = $file;
		$this->assertTrue( $mo->export_to_file( $file ) );
		$this->reset_state();

		$this->assertSame( 'MO-Para', $this->tr( 'Currency' ) );
		$this->assertSame( 'Otomatik', $this->tr( 'Auto' ) );
	}

	public function test_php_pack_still_wins_after_a_locale_switch(): void {
		$this->write_pack(
			self::DOMAIN . '-' . self::LOCALE . '.l10n.php',
			"<?php\nreturn array( 'domain' => 'mhm-currency-switcher', 'language' => 'tr_TR', 'plural-forms' => 'nplurals=2; plural=(n > 1);', 'messages' => array( 'Currency' => 'PACK-Para' ) );\n"
		);
		$this->assertSame( 'PACK-Para', $this->tr( 'Currency' ) );

		// What WP_Locale_Switcher does: unload the domain, then just-in-time loading runs again for the new locale.
		$this->locale = 'en_US';
		unload_textdomain( self::DOMAIN, true );
		$this->assertSame( 'Currency', $this->tr( 'Currency' ) );
		$this->locale = self::LOCALE;
		unload_textdomain( self::DOMAIN, true );

		$this->assertSame( 'PACK-Para', $this->tr( 'Currency' ) );
		$this->assertSame( 'Otomatik', $this->tr( 'Auto' ) );
	}

	/**
	 * Registers a script whose bundled JSON is the real admin-app catalogue; returns [ bundled file, pack file, keys ].
	 *
	 * @return array{0:string,1:string,2:string[]}
	 */
	private function js_fixture(): array {
		$bundled = MHMCS_PATH . 'languages/' . self::DOMAIN . '-' . self::LOCALE . '-' . md5( 'admin-app/build/index.js' ) . '.json';
		$this->assertFileExists( $bundled );
		$keys = array_keys( json_decode( (string) file_get_contents( $bundled ), true )['locale_data']['messages'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		$keys = array_values( array_filter( $keys, static fn( $k ) => '' !== $k ) );

		// Core derives the catalogue name from the src relative to this site's plugins dir; pin it so the test does not
		// depend on where the plugin is installed (CI checks it out beside WP, not under wp-content/plugins).
		add_filter(
			'load_script_textdomain_relative_path',
			static fn( $relative, $src ) => self::SRC === $src ? 'admin-app/build/index.js' : $relative,
			10,
			2
		);
		wp_register_script( self::HANDLE, self::SRC, array(), '1', true );
		wp_set_script_translations( self::HANDLE, self::DOMAIN, MHMCS_PATH . 'languages' );

		return array( $bundled, WP_LANG_DIR . '/plugins/' . basename( $bundled ), $keys );
	}

	public function test_js_without_a_pack_uses_the_plugin_json(): void {
		list( $bundled ) = $this->js_fixture();

		$json = load_script_textdomain( self::HANDLE, self::DOMAIN, MHMCS_PATH . 'languages' );
		$this->assertSame( file_get_contents( $bundled ), $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
	}

	public function test_js_pack_wins_and_plugin_json_fills_missing_keys(): void {
		list( $bundled, $pack_file, $keys ) = $this->js_fixture();
		$this->assertGreaterThan( 2, count( $keys ) );

		$bundled_data                    = json_decode( (string) file_get_contents( $bundled ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		$pack                            = $bundled_data;
		$pack['locale_data']['messages'] = array(
			''       => $bundled_data['locale_data']['messages'][''],
			$keys[0] => array( 'PACK-JS' ),
		);
		$this->write_pack( basename( $pack_file ), (string) wp_json_encode( $pack ) );

		$json = load_script_textdomain( self::HANDLE, self::DOMAIN, MHMCS_PATH . 'languages' );
		$this->assertIsString( $json );
		$data = json_decode( $json, true )['locale_data']['messages'];

		$this->assertSame( array( 'PACK-JS' ), $data[ $keys[0] ], 'The pack wins where it has the key.' );
		$this->assertSame( $bundled_data['locale_data']['messages'][ $keys[1] ], $data[ $keys[1] ], 'A missing key falls back to the plugin JSON.' );
		$this->assertCount( count( $bundled_data['locale_data']['messages'] ), $data );
	}

	public function test_filters_ignore_other_text_domains(): void {
		$file = MHMCS_PATH . 'languages/x.json';
		$this->assertSame( $file, LanguagePackPreference::prefer_pack_file( $file, 'h', 'other-domain' ) );
		$this->assertSame( '{"a":1}', LanguagePackPreference::complete_from_bundled( '{"a":1}', $file, 'h', 'other-domain' ) );
		$this->assertFalse( LanguagePackPreference::prefer_pack_file( false, 'h', self::DOMAIN ) );
		$this->assertSame( '/somewhere/', LanguagePackPreference::lang_dir( '/somewhere/', 'other-domain', self::LOCALE ) );
		$this->assertFalse( LanguagePackPreference::lang_dir( false, 'other-domain', self::LOCALE ) );
	}

	public function test_pack_for_a_locale_the_plugin_does_not_bundle_is_used_alone(): void {
		$this->locale = 'fr_FR';
		$this->assertFileDoesNotExist( MHMCS_PATH . 'languages/' . self::DOMAIN . '-fr_FR.mo', 'Precondition: no bundled fr_FR catalogue.' );
		$this->write_pack(
			self::DOMAIN . '-fr_FR.l10n.php',
			"<?php
return array( 'domain' => 'mhm-currency-switcher', 'language' => 'fr_FR', 'plural-forms' => 'nplurals=2; plural=(n > 1);', 'messages' => array( 'Currency' => 'PACK-Devise' ) );
"
		);
		$this->assertSame( 'PACK-Devise', $this->tr( 'Currency' ) );
		$this->assertSame( 'Auto', $this->tr( 'Auto' ), 'No bundled catalogue to fill from: the source string stays.' );

		list( $bundled ) = $this->js_fixture();
		$fr_pack         = WP_LANG_DIR . '/plugins/' . str_replace( '-' . self::LOCALE . '-', '-fr_FR-', basename( $bundled ) );
		$pack            = array(
			'domain'      => 'messages',
			'locale_data' => array(
				'messages' => array(
					''       => array( 'domain' => 'messages', 'lang' => 'fr', 'plural-forms' => 'nplurals=2; plural=n > 1;' ),
					'Remove' => array( 'PACK-Retirer' ),
				),
			),
		);
		$this->write_pack( basename( $fr_pack ), (string) wp_json_encode( $pack ) );
		$json = load_script_textdomain( self::HANDLE, self::DOMAIN, MHMCS_PATH . 'languages' );
		$this->assertIsString( $json );
		$this->assertSame( $pack['locale_data']['messages'], json_decode( $json, true )['locale_data']['messages'], 'Nothing bundled to merge: the pack JSON is returned as is.' );
	}

	public function test_js_merge_keeps_the_pack_header(): void {
		list( $bundled, $pack_file, $keys ) = $this->js_fixture();
		$bundled_data = json_decode( (string) file_get_contents( $bundled ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		$pack_header  = array( 'domain' => 'messages', 'lang' => 'tr', 'plural-forms' => 'nplurals=2; plural=n > 1;' );
		$this->assertNotSame( $bundled_data['locale_data']['messages'][''], $pack_header, 'Precondition: the two headers differ.' );
		$pack = array(
			'domain'      => 'messages',
			'locale_data' => array(
				'messages' => array(
					''       => $pack_header,
					$keys[0] => array( 'PACK-JS' ),
				),
			),
		);
		$this->write_pack( basename( $pack_file ), (string) wp_json_encode( $pack ) );

		$data = json_decode( (string) load_script_textdomain( self::HANDLE, self::DOMAIN, MHMCS_PATH . 'languages' ), true )['locale_data']['messages'];
		$this->assertSame( $pack_header, $data[''] );
	}

	public function test_preload_ignores_other_text_domains(): void {
		$this->assertNull( LanguagePackPreference::preload( null, 'other-domain', WP_LANG_DIR . '/plugins/other-domain-tr_TR.mo', self::LOCALE ) );
		$this->assertTrue( LanguagePackPreference::preload( true, 'other-domain', WP_LANG_DIR . '/plugins/other-domain-tr_TR.mo', self::LOCALE ) );
		$this->assertNull( LanguagePackPreference::preload( null, self::DOMAIN, MHMCS_PATH . 'languages/' . self::DOMAIN . '-tr_TR.mo', self::LOCALE ), 'A bundled-file load is not a pack load.' );
		$this->assertFalse( is_textdomain_loaded( 'other-domain' ) );
	}

	public function test_plugin_does_not_call_load_plugin_textdomain(): void {
		$this->assertStringNotContainsString( 'load_plugin_textdomain(', (string) file_get_contents( MHMCS_PATH . 'mhm-currency-switcher.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
	}
}
