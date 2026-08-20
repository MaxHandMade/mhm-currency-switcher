<?php
/**
 * Admin REST API controller.
 *
 * Registers REST routes for the currency switcher admin panel
 * and a public rates endpoint for third-party consumers.
 *
 * @package MhmCurrencySwitcher\Admin
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\RateProvider;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestAPI — admin settings, currencies, and rates REST endpoints.
 *
 * @since 0.4.0
 */
final class RestAPI {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const NAMESPACE_V1 = 'mhmcs/v1';

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const SETTINGS_KEY = 'mhmcs_settings';

	/**
	 * Setting keys that were removed once their controls turned out to be
	 * dead. Purged from stored settings so they cannot linger in the
	 * database — `provider_api_key` is a user-supplied secret.
	 *
	 * @var array<int, string>
	 */
	public const LEGACY_SETTING_KEYS = array(
		'provider',
		'provider_api_key',
		'cache_duration',
		'round_prices',
		'multilingual_mapping',
		'payment_restrictions',
	);

	/**
	 * Currency data store.
	 *
	 * @var CurrencyStore
	 */
	private CurrencyStore $store;

	/**
	 * Clamps applied while sanitising the current request.
	 *
	 * @var array<int, array{code: string, field: string, reason: string, value: mixed}>
	 */
	private array $adjustments = array();

	/**
	 * Price converter.
	 *
	 * @var Converter
	 */
	private Converter $converter;

	/**
	 * Exchange rate provider.
	 *
	 * @var RateProvider
	 */
	private RateProvider $rate_provider;

	/**
	 * Constructor.
	 *
	 * @param CurrencyStore $store         Currency data store.
	 * @param Converter     $converter     Price conversion engine.
	 * @param RateProvider  $rate_provider Exchange rate fetcher.
	 */
	public function __construct( CurrencyStore $store, Converter $converter, RateProvider $rate_provider ) {
		$this->store         = $store;
		$this->converter     = $converter;
		$this->rate_provider = $rate_provider;
	}

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// GET /settings.
		register_rest_route(
			self::NAMESPACE_V1,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		// GET/POST /currencies.
		register_rest_route(
			self::NAMESPACE_V1,
			'/currencies',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_currencies' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_currencies' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		// POST /rates/sync.
		register_rest_route(
			self::NAMESPACE_V1,
			'/rates/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync_rates' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// GET /rates/preview.
		register_rest_route(
			self::NAMESPACE_V1,
			'/rates/preview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_rates_preview' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// GET /rates (public).
		register_rest_route(
			self::NAMESPACE_V1,
			'/rates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_public_rates' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Check if the current user can manage WooCommerce.
	 *
	 * @return bool True when the user has manage_woocommerce capability.
	 */
	public function check_admin_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * GET /settings — return plugin settings.
	 *
	 * @return WP_REST_Response Settings data.
	 */
	public function get_settings(): WP_REST_Response {
		$settings = get_option( self::SETTINGS_KEY, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return new WP_REST_Response( $settings, 200 );
	}

	/**
	 * POST /settings — save plugin settings.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response Success response.
	 */
	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			return new WP_REST_Response(
				array( 'message' => 'Invalid settings data.' ),
				400
			);
		}

		// Sanitise known keys.
		$sanitized = array();

		if ( isset( $params['auto_detect'] ) ) {
			$sanitized['auto_detect'] = (bool) $params['auto_detect'];
		}

		// Read by ConversionContext decision 4. The key name is shared with
		// admin-app/src/components/tabs/AdvancedSettings.jsx and with the
		// activation defaults in mhm-currency-switcher.php; all three must
		// spell it identically or the setting silently drops or is reborn.
		if ( isset( $params['cache_compat'] ) ) {
			$sanitized['cache_compat'] = (bool) $params['cache_compat'];
		}

		// Read by uninstall.php, which cannot autoload this class and therefore
		// reads the raw option. Absent means false: the shop's sales history
		// survives unless someone deliberately asks otherwise.
		if ( isset( $params['delete_all_data'] ) ) {
			$sanitized['delete_all_data'] = (bool) $params['delete_all_data'];
		}

		if ( isset( $params['rate_update_interval'] ) ) {
			$interval                          = sanitize_text_field( $params['rate_update_interval'] );
			$allowed                           = array( 'manual', 'hourly', 'twicedaily', 'daily' );
			$sanitized['rate_update_interval'] = in_array( $interval, $allowed, true ) ? $interval : 'manual';
		}

		if ( isset( $params['product_widget'] ) && is_array( $params['product_widget'] ) ) {
			$widget = array();

			if ( isset( $params['product_widget']['enabled'] ) ) {
				$widget['enabled'] = (bool) $params['product_widget']['enabled'];
			}

			if ( isset( $params['product_widget']['show_flags'] ) ) {
				$widget['show_flags'] = (bool) $params['product_widget']['show_flags'];
			}

			if ( isset( $params['product_widget']['currencies'] ) && is_array( $params['product_widget']['currencies'] ) ) {
				$widget['currencies'] = array_values(
					array_filter(
						array_map( 'sanitize_text_field', $params['product_widget']['currencies'] ),
						function ( string $code ): bool {
							return 1 === preg_match( '/^[A-Z]{3}$/', $code );
						}
					)
				);
			}

			$sanitized['product_widget'] = $widget;
		}

		if ( isset( $params['switcher'] ) && is_array( $params['switcher'] ) ) {
			$switcher = array();

			foreach ( array( 'show_flag', 'show_name', 'show_symbol', 'show_code' ) as $toggle ) {
				if ( isset( $params['switcher'][ $toggle ] ) ) {
					$switcher[ $toggle ] = (bool) $params['switcher'][ $toggle ];
				}
			}

			if ( isset( $params['switcher']['size'] ) ) {
				$size = sanitize_key( (string) $params['switcher']['size'] );

				$switcher['size'] = in_array( $size, array( 'small', 'medium', 'large' ), true )
					? $size
					: 'medium';
			}

			$sanitized['switcher'] = $switcher;
		}

		// Merge with existing settings.
		$existing = get_option( self::SETTINGS_KEY, array() );

		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$merged = array_merge( $existing, $sanitized );

		// Drop keys whose controls no longer exist; array_merge would
		// otherwise carry them forward from $existing forever.
		foreach ( self::LEGACY_SETTING_KEYS as $legacy_key ) {
			unset( $merged[ $legacy_key ] );
		}

		update_option( self::SETTINGS_KEY, $merged );

		// Reschedule cron if rate_update_interval changed.
		if ( isset( $sanitized['rate_update_interval'] ) ) {
			wp_clear_scheduled_hook( 'mhmcs_update_rates' );

			$new_interval = $sanitized['rate_update_interval'];

			if ( 'manual' !== $new_interval
				&& in_array( $new_interval, array( 'hourly', 'twicedaily', 'daily' ), true )
			) {
				wp_schedule_event( time(), $new_interval, 'mhmcs_update_rates' );
			}
		}

		return new WP_REST_Response(
			array(
				'success'  => true,
				'settings' => $merged,
			),
			200
		);
	}

	/**
	 * GET /currencies — return all configured currencies.
	 *
	 * @return WP_REST_Response Currency data.
	 */
	public function get_currencies(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'base_currency' => $this->store->get_base_currency(),
				'currencies'    => $this->store->get_currencies(),
			),
			200
		);
	}

	/**
	 * POST /currencies — save currencies.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response Success response.
	 */
	public function save_currencies( WP_REST_Request $request ): WP_REST_Response {
		$this->adjustments = array();

		$params = $request->get_json_params();

		if ( ! is_array( $params ) || ! isset( $params['currencies'] ) || ! is_array( $params['currencies'] ) ) {
			return new WP_REST_Response(
				array( 'message' => 'Invalid currencies data.' ),
				400
			);
		}

		$currencies = $params['currencies'];

		/*
		 * Held to the same shape as every currency code in the list below.
		 * Without this the base code reached the saved option, and from there
		 * the rate-provider URL, unvalidated — the one entry point of this
		 * class that trusted its input because it is not an array member.
		 */
		$base = $params['base_currency'] ?? $this->store->get_base_currency();

		if ( ! is_string( $base ) || 1 !== preg_match( '/^[A-Z]{3}$/', $base ) ) {
			$base = $this->store->get_base_currency();
		}

		// Validate currency codes and exclude base currency.
		$currencies = array_filter(
			$currencies,
			function ( $currency ) use ( $base ): bool {
				return is_array( $currency )
					&& isset( $currency['code'] )
					&& 1 === preg_match( '/^[A-Z]{3}$/', $currency['code'] )
					&& $currency['code'] !== $base;
			}
		);
		$currencies = array_values( $currencies );

		// Fill missing format data from WooCommerce defaults.
		$currencies = array_map( array( $this, 'ensure_currency_format' ), $currencies );

		// Visible-only write: the panel never shows the row whose code matches
		// the base, so saving must not delete it. See CurrencyStore::set_visible_data().
		$this->store->set_visible_data( $base, $currencies );
		$this->store->save();

		return new WP_REST_Response(
			array(
				'success'     => true,
				'currencies'  => $currencies,
				'adjustments' => $this->adjustments,
			),
			200
		);
	}

	/**
	 * POST /rates/sync — trigger exchange rate synchronisation.
	 *
	 * @return WP_REST_Response Updated rates.
	 */
	public function sync_rates(): WP_REST_Response {
		$base = $this->store->get_base_currency();

		// Explicit sync: the shop owner pressed a button that says "sync", so
		// this must reach the API rather than re-serve the day-long transient.
		$rates = $this->rate_provider->fetch_rates( $base, true );

		if ( empty( $rates ) ) {
			return new WP_REST_Response(
				array( 'message' => 'Failed to fetch exchange rates.' ),
				500
			);
		}

		// Refresh the automatic rates only; a manual rate belongs to the shop
		// owner. See RateProvider::apply_rates().
		$applied = RateProvider::apply_rates( $this->store->get_currencies(), $rates );

		$this->store->set_visible_data( $base, $applied['currencies'] );
		$this->store->save();
		RateProvider::record_sync( $base );

		return new WP_REST_Response(
			array(
				'success' => true,
				'rates'   => $rates,
			),
			200
		);
	}

	/**
	 * GET /rates/preview — preview rates for admin.
	 *
	 * Returns raw and effective (with fee) rates for all currencies.
	 *
	 * @return WP_REST_Response Rate preview data.
	 */
	public function get_rates_preview(): WP_REST_Response {
		$currencies = $this->store->get_currencies();
		$preview    = array();

		foreach ( $currencies as $currency ) {
			$code = $currency['code'] ?? '';

			if ( '' === $code ) {
				continue;
			}

			$preview[] = array(
				'code'           => $code,
				'raw_rate'       => $this->converter->get_raw_rate( $code ),
				'effective_rate' => $this->converter->get_rate( $code ),
			);
		}

		return new WP_REST_Response(
			array(
				'base_currency' => $this->store->get_base_currency(),
				'rates'         => $preview,
			),
			200
		);
	}

	/**
	 * GET /rates (public) — return base currency and enabled rates.
	 *
	 * @return WP_REST_Response Public rate data.
	 */
	public function get_public_rates(): WP_REST_Response {
		$base    = $this->store->get_base_currency();
		$enabled = $this->store->get_enabled_currencies();
		$rates   = array();

		foreach ( $enabled as $currency ) {
			$code = $currency['code'] ?? '';

			if ( '' === $code ) {
				continue;
			}

			$rates[ $code ] = $this->converter->get_rate( $code );
		}

		return new WP_REST_Response(
			array(
				'base'  => $base,
				'rates' => $rates,
			),
			200
		);
	}

	/**
	 * Sanitise a thousand or decimal separator.
	 *
	 * 🔴 Deliberately NOT sanitize_text_field(). That helper collapses
	 * whitespace runs and then trims, so a plain-space thousand separator —
	 * the stock `1 234,56` grouping, which WooCommerce itself accepts —
	 * becomes an empty string one step before any clamp could notice, and the
	 * shop owner is never told. Strip markup and control characters, then take
	 * one character with mb_substr() because the real-world separators U+00A0,
	 * U+202F and U+066B are multibyte and a byte-wise cut produces invalid
	 * UTF-8 rather than a separator.
	 *
	 * 🔴 Also deliberately NOT wp_strip_all_tags(). Its own source
	 * (wp-includes/formatting.php) ends with an UNCONDITIONAL `return
	 * trim( $text );` regardless of its $remove_breaks argument, so
	 * wp_strip_all_tags( ' ' ) returns '' — the exact same defect this
	 * method exists to remove, reproduced one call inside the fix. PHP's
	 * native strip_tags() removes markup without trimming the result, which
	 * is all a value that is about to be cut to one character needs.
	 *
	 * @param mixed $raw Submitted value.
	 * @return string
	 */
	private static function sanitize_separator( $raw ): string {
		$value = strip_tags( (string) $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- wp_strip_all_tags() unconditionally trim()s its result, which would delete a legitimate one-space separator; see docblock above.
		$value = (string) preg_replace( '/[\x{0000}-\x{001F}\x{007F}]/u', '', $value );

		return mb_substr( $value, 0, 1 );
	}

	/**
	 * Record a clamp so the response can name it.
	 *
	 * @param string $code   Currency code.
	 * @param string $field  Field that was changed.
	 * @param string $reason Machine-readable reason.
	 * @param mixed  $value  Value that was stored instead.
	 * @return void
	 */
	private function note_adjustment( string $code, string $field, string $reason, $value ): void {
		$this->adjustments[] = array(
			'code'   => $code,
			'field'  => $field,
			'reason' => $reason,
			'value'  => $value,
		);
	}

	/**
	 * The symbol to store for a currency that arrives without one.
	 *
	 * 🔴 Read from WooCommerce's STATIC symbol table, never from
	 * `get_woocommerce_currency_symbol()`. That helper runs the
	 * `woocommerce_currency_symbol` filter, which exists so that a plugin
	 * can answer with the currency the visitor is looking at — and this
	 * plugin hooks it too, at priority 100. Asking a display filter what a
	 * currency's symbol is, and then writing the answer into stored
	 * configuration, records whatever the page happened to be showing.
	 *
	 * Measured on a live TRY shop that also ran YayCurrency: the helper
	 * returned the Lira sign for USD, EUR, GBP and JPY alike, so adding USD
	 * through the panel produced a USD currency that prints Lira signs on
	 * the storefront. Nothing reports it, and the panel has no symbol field
	 * to correct it with.
	 *
	 * The table holds HTML entities (`&#36;`); every consumer here escapes
	 * on output, so an entity would be printed literally. Decode once, at
	 * the point the value is stored.
	 *
	 * @param string $code Currency code.
	 * @return string
	 */
	private static function default_symbol_for( string $code ): string {
		if ( ! function_exists( 'get_woocommerce_currency_symbols' ) ) {
			return $code;
		}

		$symbols = get_woocommerce_currency_symbols();

		if ( ! is_array( $symbols ) || ! isset( $symbols[ $code ] ) || '' === $symbols[ $code ] ) {
			return $code;
		}

		return html_entity_decode( (string) $symbols[ $code ], ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Fill missing format properties from WooCommerce currency defaults
	 * and sanitize every field of a currency config array on input.
	 *
	 * Defense-in-depth: the endpoint already requires manage_woocommerce
	 * and every echoed value is escaped at output, but each field is
	 * sanitized here as well according to its real type.
	 *
	 * @param array<string, mixed> $currency Currency config array.
	 * @return array<string, mixed> Currency with populated, sanitized format.
	 */
	private function ensure_currency_format( array $currency ): array {
		$code = $currency['code'] ?? '';

		if ( '' === $code ) {
			return $currency;
		}

		$format = $currency['format'] ?? array();

		if ( ! is_array( $format ) || empty( $format ) ) {
			$format = array();
		}

		if ( ! isset( $format['symbol'] ) || '' === $format['symbol'] ) {
			$format['symbol'] = self::default_symbol_for( $code );
		} else {
			$format['symbol'] = sanitize_text_field( (string) $format['symbol'] );
		}

		if ( ! isset( $format['decimals'] ) ) {
			$format['decimals'] = 2;
		} else {
			$decimals = absint( $format['decimals'] );

			if ( $decimals > 4 ) {
				$this->note_adjustment( $code, 'decimals', 'decimals_out_of_range', 4 );
				$decimals = 4;
			}

			$format['decimals'] = $decimals;
		}

		if ( ! isset( $format['decimal_sep'] ) ) {
			$format['decimal_sep'] = wc_get_price_decimal_separator();
		} else {
			$format['decimal_sep'] = self::sanitize_separator( $format['decimal_sep'] );
		}

		if ( ! isset( $format['thousand_sep'] ) ) {
			$format['thousand_sep'] = wc_get_price_thousand_separator();
		} else {
			$format['thousand_sep'] = self::sanitize_separator( $format['thousand_sep'] );
		}

		// A decimal separator is only optional when there are no decimals to
		// separate. Without this, 1234.56 prints as 123456.
		if ( '' === $format['decimal_sep'] && $format['decimals'] > 0 ) {
			$format['decimal_sep'] = wc_get_price_decimal_separator();
			$this->note_adjustment( $code, 'decimal_sep', 'decimal_sep_empty', $format['decimal_sep'] );
		}

		// Equal separators render 1.234.56. The grouping one is the one that
		// can be dropped without making the number unreadable.
		if ( '' !== $format['thousand_sep'] && $format['thousand_sep'] === $format['decimal_sep'] ) {
			$format['thousand_sep'] = '';
			$this->note_adjustment( $code, 'thousand_sep', 'separators_equal', '' );
		}

		if ( ! isset( $format['position'] ) ) {
			$wc_pos             = get_option( 'woocommerce_currency_pos', 'left' );
			$format['position'] = $wc_pos;
		} else {
			$format['position'] = in_array( $format['position'], array( 'left', 'right', 'left_space', 'right_space' ), true )
				? $format['position']
				: 'left';
		}

		$currency['format'] = $format;

		if ( isset( $currency['fee'] ) && is_array( $currency['fee'] ) ) {
			$fee_type  = sanitize_key( (string) ( $currency['fee']['type'] ?? 'none' ) );
			$fee_value = (float) ( $currency['fee']['value'] ?? 0 );

			// The admin UI sends `percent`; the canonical stored name is
			// `percentage`. Without this alias the option was coerced to
			// `fixed` and the fee was added to the rate instead of applied
			// as a percentage of it.
			if ( 'percent' === $fee_type ) {
				$fee_type = 'percentage';
			}

			if ( ! in_array( $fee_type, array( 'none', 'fixed', 'percentage' ), true ) ) {
				$fee_type = 'none';
			}

			// A disabled fee must not carry a stale value forward.
			if ( 'none' === $fee_type ) {
				$fee_value = 0.0;
			}

			$currency['fee']['type']  = $fee_type;
			$currency['fee']['value'] = $fee_value;
		}

		if ( isset( $currency['rounding'] ) && is_array( $currency['rounding'] ) ) {
			$rounding_type = sanitize_key( (string) ( $currency['rounding']['type'] ?? 'disabled' ) );

			$currency['rounding']['type']     = in_array( $rounding_type, array( 'disabled', 'nearest', 'up', 'down' ), true )
				? $rounding_type
				: 'disabled';
			$currency['rounding']['value']    = (float) ( $currency['rounding']['value'] ?? 0 );
			$currency['rounding']['subtract'] = (float) ( $currency['rounding']['subtract'] ?? 0 );
		}

		if ( isset( $currency['rate'] ) && is_array( $currency['rate'] ) ) {
			$rate_type = sanitize_key( (string) ( $currency['rate']['type'] ?? 'auto' ) );

			$currency['rate']['type']  = in_array( $rate_type, array( 'auto', 'manual' ), true ) ? $rate_type : 'auto';
			$currency['rate']['value'] = (float) ( $currency['rate']['value'] ?? 0 );
		}

		// The per-currency gateway restriction feature never existed (no
		// consumer ever read this field); drop it so stale client payloads
		// cannot resurrect it in stored currency configs.
		unset( $currency['payment_methods'] );

		// Same story for the per-currency country list: it was sanitised
		// but never had a UI or a reader (CountryCurrencyMap is a static
		// map, not a per-currency setting); drop it too.
		unset( $currency['countries'] );

		if ( isset( $currency['enabled'] ) ) {
			$currency['enabled'] = (bool) $currency['enabled'];
		}

		return $currency;
	}
}
