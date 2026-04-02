<?php
/**
 * Currency data store — CRUD for wp_option JSON.
 *
 * Manages currency data persisted as a single wp_option.
 * This is the data layer: load, query, enforce limits, persist.
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
 * CurrencyStore — wp_option JSON CRUD with free-tier limit.
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
	const OPTION_KEY = 'mhm_currency_switcher_currencies';

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
	 * Maximum number of extra currencies beyond base for the free tier.
	 *
	 * @var int
	 */
	private int $free_limit = 2;

	/**
	 * Whether currency data has been loaded.
	 *
	 * @var bool
	 */
	private bool $loaded = false;

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
	 * Override the free-tier currency limit.
	 *
	 * @param int $limit Number of extra currencies allowed.
	 * @return void
	 */
	public function set_free_limit( int $limit ): void {
		$this->free_limit = $limit;
	}

	/**
	 * Return the base currency code.
	 *
	 * Auto-loads from the database when not yet loaded.
	 *
	 * @return string ISO 4217 currency code.
	 */
	public function get_base_currency(): string {
		if ( ! $this->loaded ) {
			$this->load();
		}

		return $this->base_currency;
	}

	/**
	 * Return all configured currencies.
	 *
	 * Auto-loads from the database when not yet loaded.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_currencies(): array {
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
	 * @param string $code ISO 4217 currency code (e.g. "USD").
	 * @return array<string, mixed>|null Currency array or null when not found.
	 */
	public function get_currency( string $code ): ?array {
		foreach ( $this->get_currencies() as $currency ) {
			if ( isset( $currency['code'] ) && $currency['code'] === $code ) {
				return $currency;
			}
		}

		return null;
	}

	/**
	 * Enforce the free-tier limit on a currency array.
	 *
	 * Returns at most `$free_limit` elements.
	 *
	 * @param array<int, array<string, mixed>> $currencies Currencies to slice.
	 * @return array<int, array<string, mixed>> Sliced array.
	 */
	public function enforce_limit( array $currencies ): array {
		return array_slice( $currencies, 0, $this->free_limit );
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
