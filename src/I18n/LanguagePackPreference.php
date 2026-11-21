<?php
/**
 * Translation precedence: the WP.org language pack wins, the plugin's own
 * catalogue fills the keys the pack lacks — and is the only catalogue when no
 * pack exists. Identical for PHP (.mo / .l10n.php) and JS (JSON).
 *
 * @package MhmCurrencySwitcher
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\I18n;

defined( 'ABSPATH' ) || exit;

/**
 * Just-in-time loading reads ONE catalogue per text domain. Core registers each
 * active plugin's Domain Path (wp-settings.php), so a locale WITHOUT a language
 * pack already falls back to languages/. A locale WITH a pack reads only the
 * pack, though, and a pack carries only the strings of its last GlotPress
 * import: anything added to the plugin since then shows in English even though
 * the bundled catalogue has it (measured on tr_TR, whose 2.3.0 pack lacks
 * "Exchange rates synced successfully."). This class keeps the pack in charge
 * and lets the bundled catalogue answer the keys the pack lacks:
 *
 * - PHP: `lang_dir_for_domain` points the loader at languages/ when no pack
 *   exists (a guard; core's Domain Path registration normally does it); when a
 *   pack is loaded, `pre_load_textdomain` loads it first and the
 *   bundled file right after it (the translation controller searches loaded
 *   files in order, so the pack wins and the bundled file only answers keys the
 *   pack lacks).
 * - JS: core reads a bundled JSON before the pack when a script's translations
 *   carry an explicit path (`wp_set_script_translations( ..., $path )`, as the
 *   admin app does). `load_script_translation_file` swaps in the same-named pack
 *   file; `load_script_translations` then completes it from the bundled JSON.
 *
 * Same policy and shape as MHM Slotiva's class of the same name.
 */
final class LanguagePackPreference {

	public const DOMAIN = 'mhm-currency-switcher';

	/**
	 * Re-entrancy guard for the nested load_textdomain() calls in preload().
	 *
	 * @var bool
	 */
	private static bool $loading = false;

	/**
	 * Hooks the filters.
	 */
	public static function register(): void {
		add_filter( 'lang_dir_for_domain', array( self::class, 'lang_dir' ), 10, 3 );
		add_filter( 'pre_load_textdomain', array( self::class, 'preload' ), 10, 4 );
		add_filter( 'load_script_translation_file', array( self::class, 'prefer_pack_file' ), 10, 3 );
		add_filter( 'load_script_translations', array( self::class, 'complete_from_bundled' ), 10, 4 );
	}

	/**
	 * Directory the JIT loader reads: the pack's when one exists, else the plugin's own languages/.
	 *
	 * @param string|false $path   Directory found by core, or false.
	 * @param string       $domain Text domain.
	 * @param string       $locale Locale.
	 * @return string|false
	 */
	public static function lang_dir( $path, $domain, $locale ) {
		unset( $locale );
		if ( self::DOMAIN !== $domain || ! empty( $path ) ) {
			return $path;
		}
		return trailingslashit( self::bundled_dir() );
	}

	/**
	 * When the pack is about to be loaded, load it first and the bundled catalogue right after it (fallback).
	 *
	 * @param bool|null   $loaded Short-circuit value (null = continue).
	 * @param string      $domain Text domain.
	 * @param string      $mofile Path to the .mo file.
	 * @param string|null $locale Locale, if given.
	 * @return bool|null
	 */
	public static function preload( $loaded, $domain, $mofile, $locale ) {
		if ( self::$loading || null !== $loaded || self::DOMAIN !== $domain || ! is_string( $mofile ) ) {
			return $loaded;
		}
		if ( self::norm( dirname( $mofile ) ) !== self::norm( WP_LANG_DIR . '/plugins' ) ) {
			return $loaded;
		}

		self::$loading = true;
		try {
			$pack = load_textdomain( $domain, $mofile, $locale );
			$name = self::DOMAIN . '-' . ( $locale ? $locale : determine_locale() ) . '.mo';
			$own  = load_textdomain( $domain, self::bundled_dir() . '/' . $name, $locale );
		} finally {
			self::$loading = false;
		}

		return $pack || $own;
	}

	/**
	 * Swaps a bundled script catalogue for the same-named language-pack file when the pack has one.
	 *
	 * @param string|false $file   Candidate file, or false (core's last-resort call).
	 * @param string       $handle Script handle.
	 * @param string       $domain Text domain.
	 * @return string|false
	 */
	public static function prefer_pack_file( $file, $handle, $domain ) {
		unset( $handle );
		if ( self::DOMAIN !== $domain || ! is_string( $file ) || '' === $file ) {
			return $file;
		}
		if ( self::norm( dirname( $file ) ) !== self::bundled_dir() ) {
			return $file;
		}
		$pack = WP_LANG_DIR . '/plugins/' . basename( $file );
		return is_readable( $pack ) ? $pack : $file;
	}

	/**
	 * Fills the keys a pack JSON lacks from the bundled JSON of the same name.
	 * The pack wins wherever it has a key and keeps its header (plural rule).
	 *
	 * @param mixed  $translations JSON string from the file core loaded.
	 * @param mixed  $file         The file core loaded.
	 * @param string $handle       Script handle.
	 * @param string $domain       Text domain.
	 * @return mixed
	 */
	public static function complete_from_bundled( $translations, $file, $handle, $domain ) {
		unset( $handle );
		if ( self::DOMAIN !== $domain || ! is_string( $translations ) || ! is_string( $file ) || '' === $file ) {
			return $translations;
		}
		if ( self::norm( dirname( $file ) ) !== self::norm( WP_LANG_DIR . '/plugins' ) ) {
			return $translations;
		}
		$bundled_file = self::bundled_dir() . '/' . basename( $file );
		if ( ! is_readable( $bundled_file ) ) {
			return $translations;
		}

		$pack    = json_decode( $translations, true );
		$bundled = json_decode( (string) file_get_contents( $bundled_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local translation file.
		$pk      = self::data_key( $pack, $domain );
		$bk      = self::data_key( $bundled, $domain );
		if ( null === $pk || null === $bk ) {
			return $translations;
		}

		$pack['locale_data'][ $pk ] = array_replace( $bundled['locale_data'][ $bk ], $pack['locale_data'][ $pk ] );
		$merged                     = wp_json_encode( $pack );

		return is_string( $merged ) ? $merged : $translations;
	}

	/**
	 * The `locale_data` key core reads strings from: the text domain first, then `messages`.
	 *
	 * @param mixed  $catalogue Decoded JSON.
	 * @param string $domain    Text domain.
	 */
	private static function data_key( $catalogue, string $domain ): ?string {
		if ( ! is_array( $catalogue ) || ! isset( $catalogue['locale_data'] ) || ! is_array( $catalogue['locale_data'] ) ) {
			return null;
		}
		foreach ( array( $domain, 'messages' ) as $key ) {
			if ( isset( $catalogue['locale_data'][ $key ] ) && is_array( $catalogue['locale_data'][ $key ] ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * The plugin's own catalogue directory (normalised, no trailing slash).
	 */
	private static function bundled_dir(): string {
		return untrailingslashit( wp_normalize_path( MHMCS_PATH . 'languages' ) );
	}

	/**
	 * Separator- and trailing-slash-independent path for comparisons.
	 *
	 * @param string $path Path.
	 */
	private static function norm( string $path ): string {
		return untrailingslashit( wp_normalize_path( $path ) );
	}
}
