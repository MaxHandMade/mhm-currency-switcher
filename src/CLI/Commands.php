<?php
/**
 * WP-CLI commands for MHM Currency Switcher.
 *
 * Provides command-line access to rate syncing, cache management,
 * currency listing, and plugin status reporting.
 *
 * @package MhmCurrencySwitcher\CLI
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\CLI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\RateProvider;
use MhmCurrencySwitcher\License\Mode;
use WP_CLI;

/**
 * Commands — WP-CLI subcommands for mhm-cs.
 *
 * Registered as: wp mhm-cs <subcommand>
 *
 * @since 0.4.0
 */
final class Commands {

	/**
	 * Currency data store.
	 *
	 * @var CurrencyStore
	 */
	private CurrencyStore $store;

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
	 * Sync exchange rates from API.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mhm-cs rates-sync
	 *
	 * @subcommand rates-sync
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function rates_sync( array $args, array $assoc_args ): void {
		$base = $this->store->get_base_currency();

		WP_CLI::line( "Fetching rates for base currency: {$base}..." );

		$rates = $this->rate_provider->fetch_rates( $base );

		if ( empty( $rates ) ) {
			WP_CLI::error( 'Failed to fetch exchange rates from API.' );
			return;
		}

		// Update rates in store currencies.
		$currencies = $this->store->get_currencies();
		$updated    = 0;

		foreach ( $currencies as &$currency ) {
			$code = $currency['code'] ?? '';

			if ( '' !== $code && isset( $rates[ $code ] ) ) {
				$currency['rate']['value'] = $rates[ $code ];
				++$updated;
			}
		}
		unset( $currency );

		$this->store->set_data( $base, $currencies );
		$this->store->save();

		WP_CLI::success( "Synced {$updated} exchange rates successfully." );
	}

	/**
	 * Get rate for a specific currency.
	 *
	 * ## OPTIONS
	 *
	 * <currency>
	 * : Currency code (ISO 4217, e.g. USD, EUR).
	 *
	 * ## EXAMPLES
	 *
	 *     wp mhm-cs rates-get USD
	 *     wp mhm-cs rates-get EUR
	 *
	 * @subcommand rates-get
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function rates_get( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Please provide a currency code. Example: wp mhm-cs rates-get USD' );
			return;
		}

		$code     = strtoupper( $args[0] );
		$raw_rate = $this->converter->get_raw_rate( $code );

		if ( 0.0 === $raw_rate ) {
			WP_CLI::error( "Currency '{$code}' not found or has no rate configured." );
			return;
		}

		$effective_rate = $this->converter->get_rate( $code );

		WP_CLI::line( "Currency:       {$code}" );
		WP_CLI::line( "Raw rate:       {$raw_rate}" );
		WP_CLI::line( "Effective rate: {$effective_rate}" );

		WP_CLI::success( "Rate retrieved for {$code}." );
	}

	/**
	 * Flush transient cache.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mhm-cs cache-flush
	 *
	 * @subcommand cache-flush
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function cache_flush( array $args, array $assoc_args ): void {
		$base = $this->store->get_base_currency();

		$this->rate_provider->clear_cache( $base );

		WP_CLI::success( "Rate cache flushed for base currency: {$base}." );
	}

	/**
	 * List configured currencies.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mhm-cs currencies-list
	 *
	 * @subcommand currencies-list
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function currencies_list( array $args, array $assoc_args ): void {
		$base       = $this->store->get_base_currency();
		$currencies = $this->store->get_currencies();

		WP_CLI::line( "Base currency: {$base}" );
		WP_CLI::line( '' );

		if ( empty( $currencies ) ) {
			WP_CLI::warning( 'No currencies configured.' );
			return;
		}

		$items = array();

		foreach ( $currencies as $currency ) {
			$items[] = array(
				'Code'    => $currency['code'] ?? '—',
				'Rate'    => $currency['rate']['value'] ?? '—',
				'Enabled' => ! empty( $currency['enabled'] ) ? 'Yes' : 'No',
				'Symbol'  => $currency['format']['symbol'] ?? '—',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $items, array( 'Code', 'Rate', 'Enabled', 'Symbol' ) );
	}

	/**
	 * Show plugin status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mhm-cs status
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void {
		$version = defined( 'MHM_CS_VERSION' ) ? MHM_CS_VERSION : 'unknown';
		$mode    = class_exists( '\MhmCurrencySwitcher\License\Mode' ) && Mode::is_pro() ? 'Pro' : 'Lite';
		$base    = $this->store->get_base_currency();
		$count   = count( $this->store->get_currencies() );
		$enabled = count( $this->store->get_enabled_currencies() );

		WP_CLI::line( "MHM Currency Switcher v{$version}" );
		WP_CLI::line( "Mode:               {$mode}" );
		WP_CLI::line( "Base currency:      {$base}" );
		WP_CLI::line( "Total currencies:   {$count}" );
		WP_CLI::line( "Enabled currencies: {$enabled}" );

		WP_CLI::success( 'Status check complete.' );
	}
}
