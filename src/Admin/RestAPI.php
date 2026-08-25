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

use MhmCurrencySwitcher\Admin\PreviewRenderer;
use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\OptionWriter;
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
	 * How many currencies the product price widget may list.
	 *
	 * Mirrored in admin-app/src/components/tabs/DisplayOptions.jsx as
	 * MAX_WIDGET_CURRENCIES; WidgetCapParityTest pins the two together.
	 *
	 * @var int
	 */
	public const PRODUCT_WIDGET_MAX_CURRENCIES = 5;

	/**
	 * How many currency rows one request may carry.
	 *
	 * Finite, but deliberately above anything the panel can produce. Neither the
	 * preview nor the save had a cap, and every row costs a sanitise, a
	 * conversion and a formatted render.
	 *
	 * 🔴 The number matters. The first version of this constant was 100, which
	 * is BELOW the 163 currency codes `get_woocommerce_currencies()` offers
	 * (measured on WooCommerce 10.9.4) — so a shop enabling everything the
	 * plugin's own "New Currency" list shows would have been refused with a
	 * 400, while readme.txt promised no limit at all. 500 puts the guard where
	 * it belongs: reachable only by a request the panel did not build.
	 *
	 * @var int
	 */
	public const MAX_CURRENCY_ROWS = 500;

	/**
	 * Amount the preview samples are rendered for, in base currency.
	 *
	 * @var float
	 */
	private const PREVIEW_AMOUNT = 100.0;

	/**
	 * Accepted values of `rate_update_interval`, "manual" first.
	 *
	 * Read by the sanitiser and by the scheduler below, which used to carry
	 * their own literal copies of the same list.
	 *
	 * @var string[]
	 */
	private const RATE_INTERVALS = array( 'manual', 'hourly', 'twicedaily', 'daily' );

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

		// GET/POST /rates/preview.
		register_rest_route(
			self::NAMESPACE_V1,
			'/rates/preview',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rates_preview' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'preview_rates' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
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

		/*
		 * The same filter `save_settings()` and `uninstall.php` apply, and it
		 * belongs here most of all: this is the READ path. `provider_api_key`
		 * is a user-supplied secret (see LEGACY_SETTING_KEYS) and it is still
		 * sitting in `mhmcs_settings` on every install that has not pressed
		 * Save since the provider control was removed — writing is what
		 * scrubs it, so an untouched install never scrubs.
		 *
		 * Without this line that value goes out over REST to anyone holding
		 * `manage_woocommerce`, which includes shop_manager: a role that is
		 * not an administrator and has no other route to read an option.
		 * Two of the three consumers of this list filtered; the one that
		 * hands data to a user did not.
		 */
		foreach ( self::LEGACY_SETTING_KEYS as $legacy_key ) {
			unset( $settings[ $legacy_key ] );
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
		$this->adjustments = array();

		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Invalid settings data.', 'mhm-currency-switcher' ) ),
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
			$sanitized['rate_update_interval'] = in_array( $interval, self::RATE_INTERVALS, true ) ? $interval : 'manual';
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

				if ( count( $widget['currencies'] ) > self::PRODUCT_WIDGET_MAX_CURRENCIES ) {
					$widget['currencies'] = array_slice(
						$widget['currencies'],
						0,
						self::PRODUCT_WIDGET_MAX_CURRENCIES
					);

					// Not "..._limit": bin/check-no-license-refs.sh's Quota check
					// owns that substring (it watches for a real per-tier
					// currency quota, the licence-gating surface this plugin
					// must never regrow). This cap applies to every install
					// alike, so the reason code stays clear of that pattern.
					$this->note_adjustment(
						'',
						'product_widget.currencies',
						'widget_currencies_too_many',
						self::PRODUCT_WIDGET_MAX_CURRENCIES
					);
				}
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

		/*
		 * Checked, like the currency save beside it. This was the member of
		 * that class an independent audit found still standing after the
		 * reported two were fixed — same defect, same file, one method away.
		 */
		if ( ! OptionWriter::write( self::SETTINGS_KEY, $merged ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Could not save the settings. Please try again.', 'mhm-currency-switcher' ) ),
				500
			);
		}

		$desired_interval = is_string( $merged['rate_update_interval'] ?? null )
			? $merged['rate_update_interval']
			: 'manual';

		$this->reconcile_rate_schedule(
			$desired_interval,
			( $existing['rate_update_interval'] ?? null ) !== $desired_interval
		);

		return new WP_REST_Response(
			array(
				'success'     => true,
				'settings'    => $merged,
				'adjustments' => $this->adjustments,

				/*
				 * The panel holds this in state and does not re-fetch after a
				 * save, so the save that arms the schedule is the only chance
				 * to report it. Without this key, switching a manual shop to
				 * "Hourly" left the Advanced tab claiming nothing was
				 * scheduled — and telling the user to re-save, which re-read
				 * nothing either. Same shape as GET /currencies.
				 */
				'next_sync'   => self::next_sync_payload(),
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
		$last_sync = get_option( RateProvider::LAST_SYNC_OPTION, null );

		return new WP_REST_Response(
			array(
				'base_currency' => $this->store->get_base_currency(),
				'currencies'    => $this->store->get_currencies(),
				// Null, not an empty array: absence is a state the panel renders
				// differently from "never synchronised", and an empty array
				// would blur the two.
				'last_sync'     => is_array( $last_sync ) ? $last_sync : null,
				'next_sync'     => self::next_sync_payload(),
			),
			200
		);
	}

	/**
	 * Make the armed rate-sync event match the interval that is now stored.
	 *
	 * The question deliberately is NOT "did the submitted value change". The
	 * panel POSTs the whole settings object on every save, so asking `isset()`
	 * on the sanitised key was true every time once the key had ever been
	 * stored: saving an unrelated toggle tore the event down and re-anchored it
	 * to `time()`, which both moved a daily shop's update time and made the
	 * next visit spawn a full network sync.
	 *
	 * Asking only about a change is not enough either — a shop can hold
	 * "daily" with no event at all after a deactivate/reactivate, and the one
	 * action a user would take to fix that is a save. So this asks what
	 * OptionWriter::write() asks about options: is the desired state real?
	 *
	 * Bounded on purpose: this reconciles PRESENCE (armed vs not) and re-arms
	 * on a change. It does not compare the recurrence of an already-armed
	 * event, so an event left recurring hourly behind a stored "daily" — only
	 * reachable if a previous change was interrupted between the clear and the
	 * schedule — survives until the interval is changed again.
	 *
	 * @param string $desired Stored interval, one of self::RATE_INTERVALS.
	 * @param bool   $changed Whether this save changed the stored interval.
	 * @return void
	 */
	private function reconcile_rate_schedule( string $desired, bool $changed ): void {
		$recurring = 'manual' !== $desired && in_array( $desired, self::RATE_INTERVALS, true );
		$armed     = is_int( wp_next_scheduled( RateProvider::CRON_HOOK ) );

		if ( ! $changed && $recurring === $armed ) {
			return;
		}

		wp_clear_scheduled_hook( RateProvider::CRON_HOOK );

		if ( $recurring ) {
			wp_schedule_event( time(), $desired, RateProvider::CRON_HOOK );
		}
	}

	/**
	 * When the next automatic rate sync is due, in both forms the panel needs.
	 *
	 * The interval control names a recurrence ("Twice daily") and stops there,
	 * so a shop owner could not tell 02:00 from 14:00, nor whether anything was
	 * scheduled at all.
	 *
	 * 🔴 Formatted HERE rather than in the browser. wp_next_scheduled() answers
	 * a UTC timestamp, while the timezone and the date format a shop expects
	 * are WordPress options; JavaScript rendering that number would quietly
	 * show the VISITOR's timezone instead of the shop's. wp_date() is the one
	 * function that applies both.
	 *
	 * Null when nothing is scheduled — which is every install on the "manual"
	 * interval. Core answers `false` there, and passing that through as 0 would
	 * render as January 1970.
	 *
	 * @return array{time:int,formatted:string}|null
	 */
	private static function next_sync_payload(): ?array {
		$next = wp_next_scheduled( RateProvider::CRON_HOOK );

		if ( ! is_int( $next ) || $next <= 0 ) {
			return null;
		}

		$date_format = (string) get_option( 'date_format', 'Y-m-d' );
		$time_format = (string) get_option( 'time_format', 'H:i' );

		return array(
			'time'      => $next,
			'formatted' => (string) wp_date( trim( $date_format . ' ' . $time_format ), $next ),
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
				array( 'message' => __( 'Invalid currencies data.', 'mhm-currency-switcher' ) ),
				400
			);
		}

		if ( count( $params['currencies'] ) > self::MAX_CURRENCY_ROWS ) {
			return new WP_REST_Response( array( 'message' => __( 'Too many currencies.', 'mhm-currency-switcher' ) ), 400 );
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

		/*
		 * The return has always been there; this endpoint threw it away and
		 * printed success regardless. A settings screen that says "Saved" over
		 * a write that did not happen leaves the shop owner's memory as the
		 * only record that anything was lost.
		 *
		 * CurrencyStore::save() answers "is the requested state stored", not
		 * "did a row change" — pressing Save twice writes nothing and is still
		 * a success. See the test that pins that side.
		 */
		if ( ! $this->store->save() ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Could not save the currencies. Please try again.', 'mhm-currency-switcher' ) ),
				500
			);
		}

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
				array( 'message' => __( 'Failed to fetch exchange rates.', 'mhm-currency-switcher' ) ),
				500
			);
		}

		// Refresh the automatic rates only; a manual rate belongs to the shop
		// owner. See RateProvider::apply_rates().
		$applied = RateProvider::apply_rates( $this->store->get_currencies(), $rates );

		$this->store->set_visible_data( $base, $applied['currencies'] );

		/*
		 * Order matters here more than in save_currencies(). record_sync()
		 * used to run unconditionally on the next line, so a sync whose rates
		 * never reached the database still advanced the "last successful sync"
		 * stamp the panel reads — and the panel then told the shop owner the
		 * rates were synced moments ago while it went on serving the old ones.
		 * The stamp may only move once the rates it describes are stored.
		 */
		if ( ! RateProvider::commit_sync( $this->store, $base ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Fetched the rates but could not store them. Please try again.', 'mhm-currency-switcher' ) ),
				500
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'rates'   => $rates,
			),
			200
		);
	}

	/**
	 * Build the preview payload for a currency list against a converter.
	 *
	 * @param string                           $base       Base currency code.
	 * @param array<int, array<string, mixed>> $currencies Sanitised currency rows.
	 * @param Converter                        $converter  Converter reading the same rows.
	 * @return array<string, mixed>
	 */
	private function build_preview( string $base, array $currencies, Converter $converter ): array {
		$base_format = array(
			'symbol'       => self::default_symbol_for( $base ),
			'position'     => get_option( 'woocommerce_currency_pos', 'left' ),
			'decimals'     => wc_get_price_decimals(),
			'decimal_sep'  => wc_get_price_decimal_separator(),
			'thousand_sep' => wc_get_price_thousand_separator(),
		);

		$sample_from = PreviewRenderer::render( self::PREVIEW_AMOUNT, $base, $base_format );

		$rates = array();

		foreach ( $currencies as $currency ) {
			$code = $currency['code'] ?? '';

			if ( '' === $code ) {
				continue;
			}

			$usable = $converter->has_usable_rate( $code );

			$rates[] = array(
				'code'           => $code,
				'raw_rate'       => $converter->get_raw_rate( $code ),
				'effective_rate' => $converter->get_rate( $code ),
				'usable'         => $usable,
				'sample_from'    => $sample_from,
				// An unusable currency has no honest sample: the converter
				// hands the base amount back unchanged, and dressing that in a
				// foreign symbol is the exact defect FormatFilter exists to
				// stop. The row says "no rate yet" instead.
				'sample_to'      => $usable
					? PreviewRenderer::render(
						$converter->convert_with_rounding( self::PREVIEW_AMOUNT, $code ),
						$code,
						is_array( $currency['format'] ?? null ) ? $currency['format'] : array()
					)
					: '',
			);
		}

		return array(
			'base_currency' => $base,
			'sample_amount' => self::PREVIEW_AMOUNT,
			'rates'         => $rates,
		);
	}

	/**
	 * GET /rates/preview — the saved configuration, in the panel's shape.
	 *
	 * @return WP_REST_Response Preview data.
	 */
	public function get_rates_preview(): WP_REST_Response {
		return new WP_REST_Response(
			$this->build_preview(
				$this->store->get_base_currency(),
				$this->store->get_currencies(),
				$this->converter
			),
			200
		);
	}

	/**
	 * POST /rates/preview — the SUBMITTED configuration, saving nothing.
	 *
	 * 🔴 Non-persistence is a mechanism here, not a promise. The rows are put
	 * into a store of this method's own making and `save()` is never called;
	 * `set_data()` also marks that store loaded, so it never reads the option
	 * either. The request-scoped store the rest of this class uses is not
	 * touched.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response Preview data, or 400.
	 */
	public function preview_rates( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) || ! isset( $params['currencies'] ) || ! is_array( $params['currencies'] ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Invalid preview data.', 'mhm-currency-switcher' ) ), 400 );
		}

		if ( count( $params['currencies'] ) > self::MAX_CURRENCY_ROWS ) {
			return new WP_REST_Response( array( 'message' => __( 'Too many currencies.', 'mhm-currency-switcher' ) ), 400 );
		}

		$base = $this->store->get_base_currency();

		/*
		 * The base is validated, not obeyed. get_base_currency() reads the live
		 * WooCommerce option whenever it is set, so a differing base in the body
		 * could not be honoured even if it were wanted — and answering with
		 * numbers computed against a different base than the caller asked for
		 * is worse than refusing.
		 */
		if ( isset( $params['base_currency'] ) && $params['base_currency'] !== $base ) {
			return new WP_REST_Response(
				array( 'message' => __( 'The submitted base currency is not the shop base currency.', 'mhm-currency-switcher' ) ),
				400
			);
		}

		$currencies = array_values(
			array_filter(
				$params['currencies'],
				function ( $currency ) use ( $base ): bool {
					return is_array( $currency )
						&& isset( $currency['code'] )
						&& 1 === preg_match( '/^[A-Z]{3}$/', $currency['code'] )
						&& $currency['code'] !== $base;
				}
			)
		);

		$this->adjustments = array();
		$currencies        = array_map( array( $this, 'ensure_currency_format' ), $currencies );

		$scratch = new CurrencyStore();
		$scratch->set_data( $base, $currencies );

		return new WP_REST_Response(
			$this->build_preview( $base, $currencies, new Converter( $scratch ) ),
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
	 * Sanitise a thousand or decimal separator, and report what changed.
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
	 * method exists to remove, reproduced one call inside the fix.
	 *
	 * 🔴 Also deliberately not a separate strip_tags() call. The result is
	 * cut to one character by mb_substr() below regardless, and one
	 * character cannot form markup, so stripping `<`/`>` in the same
	 * character-class pass as the control characters is sufficient — an
	 * extra strip_tags() call bought nothing but a WordPress.WP sniff
	 * warning it took a suppression to silence. Answering the sniff
	 * honestly (not needing the discouraged function at all) is preferred
	 * over a reasoned suppression comment here.
	 *
	 * A submission that survives sanitisation but is more than one
	 * character is truncated, and a non-empty submission that sanitises
	 * down to nothing (only markup, only control characters, or invalid
	 * UTF-8 — `preg_replace()` with the `/u` modifier returns null on
	 * malformed input, cast here to '') is invalid. Both are distinct from
	 * an outright empty submission, which is `decimal_sep_empty`'s
	 * business, not this method's.
	 *
	 * @param mixed $raw Submitted value.
	 * @return array{value: string, reason: string|null} Sanitised
	 *         one-character value, and — when the submission needed
	 *         correcting for a reason worth telling the shop owner about —
	 *         the reason code (`separator_truncated` or `separator_invalid`);
	 *         null when there is nothing to report.
	 */
	private static function sanitize_separator( $raw ): array {
		/*
		 * A REST client can send this field as an array or an object, and
		 * `(string)` on either raises "Array to string conversion". On a host
		 * with display_errors on, that warning prepends to the JSON body and
		 * the panel fails to parse a response it would otherwise have handled.
		 * Anyone with `manage_woocommerce` can send it, so it is not a
		 * developer-only path.
		 */
		if ( ! is_scalar( $raw ) && null !== $raw ) {
			return array(
				'value'  => '',
				'reason' => 'separator_invalid',
			);
		}

		$raw    = (string) $raw;
		$value  = (string) preg_replace( '/[\x{0000}-\x{001F}\x{007F}<>]/u', '', $raw );
		$result = mb_substr( $value, 0, 1 );

		if ( '' === $raw ) {
			return array(
				'value'  => $result,
				'reason' => null,
			);
		}

		if ( '' === $result ) {
			return array(
				'value'  => $result,
				'reason' => 'separator_invalid',
			);
		}

		/*
		 * Measured on $raw, not on the stripped $value. Stripping first and
		 * then asking "is it longer than one character" makes an input whose
		 * surviving content is a single character look untouched: "<b>" was
		 * stored as "b" and nothing was reported, inside a feature whose whole
		 * promise is that it clamps AND says so. Any input that arrived longer
		 * than one character is reported, whichever way it lost the rest.
		 */
		if ( mb_strlen( $raw ) > 1 ) {
			return array(
				'value'  => $result,
				'reason' => 'separator_truncated',
			);
		}

		return array(
			'value'  => $result,
			'reason' => null,
		);
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
	 * Currencies whose minor unit is not two digits.
	 *
	 * 🔴 DERIVED, not hand-written, and reproducible. WooCommerce cannot answer
	 * this: its `currency_minor_unit` is `wc_get_price_decimals()`, the shop's
	 * single setting, because WooCommerce assumes one currency. A plugin that
	 * displays several has to carry the data itself, and a table of money
	 * formats typed from memory is a table with a mistake in it.
	 *
	 * Generated by asking ICU for every code `get_woocommerce_currencies()`
	 * offers and keeping the ones that are not 2:
	 *
	 *     $f = new NumberFormatter( 'en_US', NumberFormatter::CURRENCY );
	 *     $f->setTextAttribute( NumberFormatter::CURRENCY_CODE, $code );
	 *     $f->getAttribute( NumberFormatter::FRACTION_DIGITS );
	 *
	 * ICU 76.1, over WooCommerce's 163 currencies: 37 are not 2. The values are
	 * CLDR's, which is what these currencies are actually PRINTED with — for a
	 * handful (the lek, the dinar of Iraq, the rial) that is 0 while ISO 4217
	 * still records 2, because the minor unit buys nothing and nobody quotes
	 * it. Displaying prices is what this table is for, so CLDR is the right
	 * authority; a shop that disagrees edits the number in the format editor,
	 * since this only ever fills a gap.
	 *
	 * The plugin does not require the intl extension — the answer was baked in
	 * here rather than asked at runtime.
	 *
	 * @var array<string, int>
	 */
	private const MINOR_UNITS = array(
		'AFN' => 0,
		'ALL' => 0,
		'BHD' => 3,
		'BIF' => 0,
		'BYR' => 0,
		'CLP' => 0,
		'DJF' => 0,
		'GNF' => 0,
		'IQD' => 0,
		'IRR' => 0,
		'ISK' => 0,
		'JOD' => 3,
		'JPY' => 0,
		'KMF' => 0,
		'KPW' => 0,
		'KRW' => 0,
		'KWD' => 3,
		'LAK' => 0,
		'LBP' => 0,
		'LYD' => 3,
		'MGA' => 0,
		'MMK' => 0,
		'OMR' => 3,
		'PYG' => 0,
		'RSD' => 0,
		'RWF' => 0,
		'SLL' => 0,
		'SOS' => 0,
		'SYP' => 0,
		'TND' => 3,
		'UGX' => 0,
		'VND' => 0,
		'VUV' => 0,
		'XAF' => 0,
		'XOF' => 0,
		'XPF' => 0,
		'YER' => 0,
	);

	/**
	 * How many decimals a currency is normally printed with.
	 *
	 * @param string $code Currency code (ISO 4217).
	 * @return int Digits after the decimal separator.
	 */
	public static function default_decimals_for( string $code ): int {
		return self::MINOR_UNITS[ strtoupper( $code ) ] ?? 2;
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
	 * Public and static so `PreviewRenderer::render()` can fall back to the
	 * same rule for a currency row with no saved symbol, instead of copying
	 * it — a second copy is exactly how the panel and the storefront would
	 * drift apart on the one field this redesign exists to keep in sync.
	 *
	 * @param string $code Currency code.
	 * @return string
	 */
	public static function default_symbol_for( string $code ): string {
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
	 * Sanitise one submitted number before it can reach the option.
	 *
	 * Three ways a number arrives unusable, and only the first was handled
	 * anywhere in this class (for `decimals`):
	 *
	 * - not a number at all — `(float) 'abc'` is 0.0, the same value that
	 *   means "no rate yet", so the mistake becomes indistinguishable from a
	 *   deliberate setting once stored;
	 * - not FINITE — measured, not hypothetical: `json_decode('{"v":1e309}')`
	 *   yields `float(INF)`, and `wp_json_encode()` refuses to encode it, so
	 *   `CurrencyStore::save()` returned false and NOTHING in the request was
	 *   stored. One unusable digit in one field discarded every other currency
	 *   submitted beside it;
	 * - negative where a negative has no meaning (a rate, a rounding step).
	 *
	 * Corrections are returned with a reason rather than applied silently,
	 * because "corrected, and named" is the contract this endpoint already
	 * keeps for `decimals` and the separators.
	 *
	 * @param mixed $raw             Submitted value.
	 * @param float $default         Value to fall back to.
	 * @param bool  $allow_negative  Whether a negative value is meaningful here.
	 * @return array{value: float, reason: string|null}
	 */
	private static function sanitize_numeric( $raw, float $default, bool $allow_negative ): array {
		// Trimmed first so the answer does not depend on the PHP version:
		// `is_numeric( '0.92 ' )` is false on 7.4 — the plugin's floor — and
		// true from 8.0 on. Untrimmed, the same submission would be stored on
		// one host and corrected to 0 on another.
		$raw = is_string( $raw ) ? trim( $raw ) : $raw;

		if ( ! is_numeric( $raw ) ) {
			return array(
				'value'  => $default,
				'reason' => 'not_a_number',
			);
		}

		$value = (float) $raw;

		// is_numeric() answers true for INF and NAN — they are floats. This is
		// the check that keeps them out of the encoder.
		if ( ! is_finite( $value ) ) {
			return array(
				'value'  => $default,
				'reason' => 'not_finite',
			);
		}

		if ( ! $allow_negative && $value < 0.0 ) {
			return array(
				'value'  => $default,
				'reason' => 'negative',
			);
		}

		return array(
			'value'  => $value,
			'reason' => null,
		);
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
			// Every currency arrives here the moment it is added in the panel:
			// the row is sent with no format block and this fills it in. A flat
			// 2 printed the yen as "¥1,234.00" on every price in the shop until
			// somebody noticed and edited it by hand.
			$format['decimals'] = self::default_decimals_for( $code );
		} elseif ( ! is_numeric( $format['decimals'] ) ) {
			// absint() on a non-numeric submission (e.g. 'abc') silently
			// coerces it to 0 decimals — a value the shop owner never chose.
			// Fall back to the standard default instead, and say so.
			$format['decimals'] = self::default_decimals_for( $code );

			$this->note_adjustment( $code, 'decimals', 'decimals_invalid', $format['decimals'] );
		} else {
			// Resolved to its final value FIRST, so at most one adjustment is
			// ever emitted for this field, and it always names the value that
			// was actually stored rather than an intermediate one —
			// note_adjustment()'s own contract is "the value that was stored
			// instead". A negative submission clamps to 0 rather than
			// flipping sign: absint( -3 ) silently inventing "3" is a number
			// the shop owner never expressed, whereas clamping to the
			// nearest valid bound (0-4) is exactly what "the number you
			// typed was not usable as submitted" means on either side of the
			// range.
			$decimals = (int) $format['decimals'];
			$clamped  = max( 0, min( 4, $decimals ) );

			if ( $clamped !== $decimals ) {
				$this->note_adjustment( $code, 'decimals', 'decimals_out_of_range', $clamped );
			}

			$format['decimals'] = $clamped;
		}

		// Tracked explicitly rather than inferred from the resolved value:
		// the collision rules below must treat a separator the shop owner
		// typed differently from one this method filled in with a
		// WooCommerce default, and by the time those rules run, a submitted
		// value and a defaulted value can look identical. Tracked for BOTH
		// fields — the collision this method exists to prevent can originate
		// from either side filling in a default that happens to match what
		// the OTHER side actually submitted.
		$decimal_sep_submitted  = isset( $format['decimal_sep'] );
		$thousand_sep_submitted = isset( $format['thousand_sep'] );

		if ( $decimal_sep_submitted ) {
			$sanitised             = self::sanitize_separator( $format['decimal_sep'] );
			$format['decimal_sep'] = $sanitised['value'];

			if ( null !== $sanitised['reason'] ) {
				$this->note_adjustment( $code, 'decimal_sep', $sanitised['reason'], $sanitised['value'] );
			}
		}

		if ( $thousand_sep_submitted ) {
			$sanitised              = self::sanitize_separator( $format['thousand_sep'] );
			$format['thousand_sep'] = $sanitised['value'];

			if ( null !== $sanitised['reason'] ) {
				$this->note_adjustment( $code, 'thousand_sep', $sanitised['reason'], $sanitised['value'] );
			}
		} else {
			$format['thousand_sep'] = wc_get_price_thousand_separator();
		}

		// decimal_sep is filled in by this method in two situations: it was
		// never submitted at all, or it was submitted but is empty/invalid
		// while decimals are still to show. Both are "this method invented
		// the value" — without this, 1234.56 also prints as 123456 — and in
		// both, a server-invented value must not collide with a thousand
		// separator the shop owner actually typed. Colliding here would
		// blank their explicit choice in the next rule for a collision only
		// the server created. Only report the fallback when decimal_sep was
		// itself submitted; a field nobody touched silently taking the
		// WooCommerce default is not something the shop owner did anything
		// to trigger.
		if ( ! $decimal_sep_submitted || ( '' === $format['decimal_sep'] && $format['decimals'] > 0 ) ) {
			$fallback = wc_get_price_decimal_separator();

			if ( $thousand_sep_submitted && $fallback === $format['thousand_sep'] ) {
				$fallback = ( '.' === $fallback ) ? ',' : '.';
			}

			$format['decimal_sep'] = $fallback;

			if ( $decimal_sep_submitted ) {
				$this->note_adjustment( $code, 'decimal_sep', 'decimal_sep_empty', $fallback );
			}
		}

		// Equal separators render 1.234.56. thousand_sep is the one that
		// yields — but by this point decimal_sep can no longer be a
		// server-invented value that collides with a SUBMITTED thousand_sep
		// (the rule above already prevented that), so reaching this block
		// with thousand_sep submitted means the shop owner's own two choices
		// genuinely conflict, which is worth reporting. A thousand_sep this
		// method filled in itself steps aside silently instead of
		// generating a notice about a field nobody touched (the ordinary
		// "decimal_sep only" European submission would otherwise trigger a
		// notice about thousand_sep on every save).
		if ( '' !== $format['thousand_sep'] && $format['thousand_sep'] === $format['decimal_sep'] ) {
			$format['thousand_sep'] = '';

			if ( $thousand_sep_submitted ) {
				$this->note_adjustment( $code, 'thousand_sep', 'separators_equal', '' );
			}
		}

		if ( ! isset( $format['position'] ) ) {
			$wc_pos             = get_option( 'woocommerce_currency_pos', 'left' );
			$format['position'] = $wc_pos;
		} else {
			$position_submitted = $format['position'];
			$position_valid     = in_array( $position_submitted, array( 'left', 'right', 'left_space', 'right_space' ), true );

			$format['position'] = $position_valid ? $position_submitted : 'left';

			/*
			 * Every other clamp in this method reports. This one used to fall
			 * back silently, which only stayed invisible because the drawer
			 * sends a <select> — a hand-built request setting `position` to
			 * anything else had its value replaced with no word about it, in
			 * the one feature whose contract is "corrected, and named".
			 */
			if ( ! $position_valid ) {
				$this->note_adjustment( $code, 'position', 'position_invalid', 'left' );
			}
		}

		$currency['format'] = $format;

		if ( isset( $currency['fee'] ) && is_array( $currency['fee'] ) ) {
			$fee_type = sanitize_key( (string) ( $currency['fee']['type'] ?? 'none' ) );

			/*
			 * The sign is KEPT here, unlike the rate and the rounding steps
			 * below. A negative percentage fee is a margin a shop owner may
			 * legitimately set, and Converter::has_usable_rate() already
			 * documents and guards the one value that destroys a rate (-100%).
			 * Correcting it here would overwrite a number they meant.
			 */
			$fee       = self::sanitize_numeric( $currency['fee']['value'] ?? 0, 0.0, true );
			$fee_value = $fee['value'];

			if ( null !== $fee['reason'] ) {
				$this->note_adjustment( $code, 'fee', $fee['reason'], $fee_value );
			}

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

			$currency['rounding']['type'] = in_array( $rounding_type, array( 'disabled', 'nearest', 'up', 'down' ), true )
				? $rounding_type
				: 'disabled';
			foreach ( array( 'value', 'subtract' ) as $field ) {
				// Negatives are refused rather than kept: this field is named
				// "subtract", and a negative one silently inverts it into an
				// addition. Nothing downstream catches that. `apply_rounding()`
				// computes `$rounded - $subtract`, which for a negative
				// subtract ADDS, and its safety guard only fires when the
				// result lands at or below zero — an inflated price sails
				// through and is charged. An earlier version of this comment
				// claimed the converter neutralised it; it does not, and the
				// claim was wrong in the direction that costs the customer.
				$sanitised = self::sanitize_numeric( $currency['rounding'][ $field ] ?? 0, 0.0, false );

				$currency['rounding'][ $field ] = $sanitised['value'];

				if ( null !== $sanitised['reason'] ) {
					$this->note_adjustment( $code, 'rounding_' . $field, $sanitised['reason'], $sanitised['value'] );
				}
			}
		}

		if ( isset( $currency['rate'] ) && is_array( $currency['rate'] ) ) {
			$rate_type = sanitize_key( (string) ( $currency['rate']['type'] ?? 'auto' ) );

			$currency['rate']['type'] = in_array( $rate_type, array( 'auto', 'manual' ), true ) ? $rate_type : 'auto';
			$rate                     = self::sanitize_numeric( $currency['rate']['value'] ?? 0, 0.0, false );

			$currency['rate']['value'] = $rate['value'];

			if ( null !== $rate['reason'] ) {
				$this->note_adjustment( $code, 'rate', $rate['reason'], $rate['value'] );
			}

			// Preserved, not (re)invented. `updated_at` is RateProvider::
			// apply_rates()'s per-row sync stamp; the only thing this save
			// path may legitimately do with it is carry it through unchanged
			// when the client echoes it back, and sanitise its TYPE. Writing
			// a value here for a currency that arrived without one would
			// forge a sync this save never performed — save_currencies()
			// never syncs anything, it only stores what was submitted.
			if ( isset( $currency['rate']['updated_at'] ) ) {
				$currency['rate']['updated_at'] = absint( $currency['rate']['updated_at'] );
			}
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
