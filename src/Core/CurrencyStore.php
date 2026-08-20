<?php
/**
 * Currency data store — CRUD for wp_option JSON.
 *
 * Manages currency data persisted as a single wp_option.
 * This is the data layer: load, query, persist.
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
 * CurrencyStore — wp_option JSON CRUD.
 *
 * Stores an array of currency configurations (code, rate, format, etc.)
 * in a single serialised wp_option row, and exposes query helpers so the
 * rest of the plugin never touches the raw option directly.
 *
 * @since 0.1.0
 */
final class CurrencyStore {

	/**
	 * Option key used in the wp_options table.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'mhmcs_currencies';

	/**
	 * WooCommerce base currency code (ISO 4217).
	 *
	 * @var string
	 */
	private string $base_currency = 'USD';

	/**
	 * Array of currency configuration arrays.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $currencies = array();

	/**
	 * Whether currency data has been loaded.
	 *
	 * @var bool
	 */
	private bool $loaded = false;

	/**
	 * Build the default value for the `mhmcs_currencies` option.
	 *
	 * Base currency comes from the WooCommerce store setting; the currency
	 * list starts empty. load() expects exactly this {base_currency,
	 * currencies} shape — a flat list of codes is not readable by load()
	 * and would silently seed nothing. Used by the plugin's activation
	 * hook; split out here (rather than kept in the entry file) so it is
	 * reachable through the ordinary autoloader, including from tests.
	 *
	 * @return array{base_currency: string, currencies: array<int, array<string, mixed>>}
	 */
	public static function default_option_value(): array {
		return array(
			'base_currency' => get_option( 'woocommerce_currency', 'USD' ),
			'currencies'    => array(),
		);
	}

	/**
	 * Load currency data from the wp_option.
	 *
	 * Reads the option, JSON-decodes it when necessary, and populates
	 * internal state.  After this call `$loaded` is always `true`.
	 *
	 * @return void
	 */
	public function load(): void {
		$raw = get_option( self::OPTION_KEY, '' );

		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );

			if ( is_array( $decoded ) ) {
				$this->base_currency = $decoded['base_currency'] ?? 'USD';
				$this->currencies    = $decoded['currencies'] ?? array();
			}
		} elseif ( is_array( $raw ) ) {
			$this->base_currency = $raw['base_currency'] ?? 'USD';
			$this->currencies    = $raw['currencies'] ?? array();
		}

		$this->loaded = true;
	}

	/**
	 * Set currency data directly (for tests / REST API).
	 *
	 * Bypasses `load()` so unit tests do not need WordPress functions.
	 *
	 * @param string                           $base       Base currency code.
	 * @param array<int, array<string, mixed>> $currencies Array of currency configs.
	 * @return void
	 */
	public function set_data( string $base, array $currencies ): void {
		$this->base_currency = $base;
		$this->currencies    = $currencies;
		$this->loaded        = true;
	}

	/**
	 * Replace the currencies a caller can SEE, keeping the ones it cannot.
	 *
	 * 🔴 Every writer in this plugin reads through `get_currencies()`, which
	 * hides the row whose code matches the current base — correctly, because
	 * the base is not a conversion target and the admin table must not offer
	 * it. Writers then handed that filtered list to `set_data()` and saved it
	 * as the WHOLE option, so the hidden row was written out of existence.
	 *
	 * It only bites after a base-currency change, which makes it rare and
	 * unrecoverable in the same breath: the moment a shop switches WooCommerce
	 * to a currency it had configured, that row goes invisible, and the next
	 * save of any kind — a rate sync, the cron tick, an admin pressing Save on
	 * an unrelated setting — takes its symbol, number format, fee and manually
	 * entered rate with it. Switching the base back brings nothing home.
	 *
	 * Only rows the caller could not see are carried over. A currency the admin
	 * genuinely removed was visible to them and stays removed; merging the
	 * whole previous list back would silently break the Remove button.
	 *
	 * @param string                           $base    Base currency code.
	 * @param array<int, array<string, mixed>> $visible Rows the caller can see.
	 * @return void
	 */
	public function set_visible_data( string $base, array $visible ): void {
		$hidden_code = $this->get_base_currency();

		$present = array();
		foreach ( $visible as $row ) {
			$present[] = (string) ( $row['code'] ?? '' );
		}

		$hidden = array();
		foreach ( $this->get_currencies_raw() as $row ) {
			$code = (string) ( $row['code'] ?? '' );

			if ( $code === $hidden_code && ! in_array( $code, $present, true ) ) {
				$hidden[] = $row;
			}
		}

		$this->set_data( $base, array_merge( array_values( $visible ), $hidden ) );
	}

	/**
	 * Return the base currency code.
	 *
	 * Always reads from WooCommerce settings so it stays in sync.
	 * Falls back to the internally stored value when WooCommerce
	 * is unavailable (e.g. during unit tests with set_data()).
	 *
	 * @return string ISO 4217 currency code.
	 */
	public function get_base_currency(): string {
		if ( function_exists( 'get_option' ) ) {
			$wc_currency = get_option( 'woocommerce_currency', '' );

			if ( '' !== $wc_currency ) {
				return (string) $wc_currency;
			}
		}

		if ( ! $this->loaded ) {
			$this->load();
		}

		return $this->base_currency;
	}

	/**
	 * Return all configured currencies.
	 *
	 * Auto-loads from the database when not yet loaded.
	 * Automatically excludes the current WooCommerce base currency
	 * from the list — the base currency is not a conversion target.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_currencies(): array {
		if ( ! $this->loaded ) {
			$this->load();
		}

		$base = $this->get_base_currency();

		return array_values(
			array_filter(
				$this->currencies,
				static function ( array $currency ) use ( $base ): bool {
					return ( $currency['code'] ?? '' ) !== $base;
				}
			)
		);
	}

	/**
	 * Return all configured currencies including the base currency.
	 *
	 * Unlike get_currencies(), this does not filter out the base.
	 * Used internally for storage and migration operations.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_currencies_raw(): array {
		if ( ! $this->loaded ) {
			$this->load();
		}

		return $this->currencies;
	}

	/**
	 * Return only enabled currencies.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_enabled_currencies(): array {
		return array_values(
			array_filter(
				$this->get_currencies(),
				static function ( array $currency ): bool {
					return ! empty( $currency['enabled'] );
				}
			)
		);
	}

	/**
	 * Find a single currency by its ISO code.
	 *
	 * Searches the raw (unfiltered) list so that format data for
	 * any currency — including the current base — can be retrieved.
	 *
	 * @param string $code ISO 4217 currency code (e.g. "USD").
	 * @return array<string, mixed>|null Currency array or null when not found.
	 */
	public function get_currency( string $code ): ?array {
		foreach ( $this->get_currencies_raw() as $currency ) {
			if ( isset( $currency['code'] ) && $currency['code'] === $code ) {
				return $currency;
			}
		}

		return null;
	}

	/**
	 * Persist current state to the wp_option as JSON.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function save(): bool {
		$data = wp_json_encode(
			array(
				'base_currency' => $this->base_currency,
				'currencies'    => $this->currencies,
			)
		);

		if ( false === $data ) {
			return false;
		}

		return update_option( self::OPTION_KEY, $data );
	}
}
