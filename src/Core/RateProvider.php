<?php
/**
 * Exchange rate API fetcher with fallback chain.
 *
 * Fetches live exchange rates from external APIs and caches
 * them in WordPress transients for performance.
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
 * RateProvider — exchange rate API with fallback chain.
 *
 * Tries to fetch rates from a transient cache first, then falls
 * back to ExchangeRate-API, and finally to the Fawaz Ahmed API.
 * Successful responses are cached as transients for one day.
 *
 * @since 0.1.0
 */
final class RateProvider {

	/**
	 * Transient key prefix for cached rates.
	 *
	 * @var string
	 */
	const TRANSIENT_KEY_PREFIX = 'mhmcs_rates_';

	/**
	 * Transient cache duration in seconds (1 day = 86400).
	 *
	 * @var int
	 */
	const TRANSIENT_EXPIRY = 86400;

	/**
	 * Option holding the last successful synchronisation.
	 *
	 * Shape: `array( 'time' => int (UTC), 'base' => string (ISO 4217) )`.
	 *
	 * An OPTION, not a transient. A transient that expired would tell a shop
	 * whose rates are perfectly good that no sync has ever been recorded — the
	 * signal would erase itself precisely when it is being trusted.
	 *
	 * Absence is a real state and every reader must render it: on the day this
	 * release lands, every upgraded site has good rates and no record here.
	 *
	 * @var string
	 */
	public const LAST_SYNC_OPTION = 'mhmcs_rates_last_sync';

	/**
	 * Fetch exchange rates for the given base currency.
	 *
	 * Lookup order:
	 *   1. Transient cache (unless `$force`).
	 *   2. ExchangeRate-API (primary).
	 *   3. Fawaz Ahmed API (fallback).
	 *
	 * On success the result is stored in the transient cache.
	 *
	 * 🔴 `$force` separates the two kinds of caller, and the distinction is the
	 * whole reason this parameter exists rather than a shorter expiry.
	 *
	 * An IMPLICIT read — rendering a price, answering a conversion request —
	 * must be served from the transient. That cache is what stops a shop on a
	 * fully cached front page from calling the rate API once per visitor, which
	 * is the workload this plugin is built for.
	 *
	 * An EXPLICIT synchronisation — the panel's "Sync rates" button, the cron
	 * tick, `wp mhm-cs rates sync` — is a request for current numbers and must
	 * go to the network. All three used to come through the implicit door, so
	 * for up to `TRANSIENT_EXPIRY` seconds none of them fetched anything: the
	 * button reported success while handing back the cache it had just been
	 * given, and an "hourly" schedule re-applied one morning's rates around the
	 * clock. The rates were never wrong, which is why no test and no gate
	 * caught it — they were just old, and every surface said they were fresh.
	 *
	 * @param string $base  Base currency code (ISO 4217, e.g. "TRY").
	 * @param bool   $force Skip the cache and fetch from the API. Pass true only
	 *                      for an explicit sync, never for a display path.
	 * @return array<string, float> Currency code => rate map, or empty array on failure.
	 */
	public function fetch_rates( string $base, bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_KEY_PREFIX . strtoupper( $base ) );

			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		// Try primary API.
		$rates = $this->fetch_from_exchangerate_api( $base );

		// Fallback API.
		if ( empty( $rates ) ) {
			$rates = $this->fetch_from_fawaz_api( $base );
		}

		if ( ! empty( $rates ) ) {
			set_transient(
				self::TRANSIENT_KEY_PREFIX . strtoupper( $base ),
				$rates,
				self::TRANSIENT_EXPIRY
			);
		}

		return $rates;
	}

	/**
	 * Fetch the exchange rate for a single target currency.
	 *
	 * @param string $base   Base currency code (ISO 4217).
	 * @param string $target Target currency code (ISO 4217).
	 * @return float|null Rate or null when unavailable.
	 */
	public function fetch_single_rate( string $base, string $target ): ?float {
		$rates = $this->fetch_rates( $base );

		$target_upper = strtoupper( $target );

		if ( isset( $rates[ $target_upper ] ) ) {
			return (float) $rates[ $target_upper ];
		}

		return null;
	}

	/**
	 * Apply fetched rates to a currency list, leaving manual rates alone.
	 *
	 * 🔴 `rate.type` is the whole point of this method. A currency set to
	 * `manual` carries a number the shop owner typed, and the admin UI disables
	 * the input to say so — the rate is theirs, not the API's. All three sync
	 * paths (the REST sync button, the cron tick and `wp mhmcs rates sync`)
	 * used to write every code the API answered for, so a manual rate survived
	 * exactly until the next sync and then vanished with no notice.
	 *
	 * It lives here, once, because that defect was three copies of the same
	 * five lines: fixing two of them would have left the third to overwrite
	 * what the other two had learned to protect.
	 *
	 * An absent type counts as automatic, matching the sanitiser's own default.
	 * Reading it the other way would freeze every currency stored before the
	 * type field existed.
	 *
	 * 🔴 `rate.updated_at` is a PER-ROW datum, and it exists because the global
	 * `LAST_SYNC_OPTION` cannot answer a per-row question. The panel used to
	 * date a row's "last updated" text with the global sync timestamp, which
	 * is honest only for a row that timestamp actually describes. A row
	 * flipped from manual to auto keeps its hand-typed number until the NEXT
	 * sync touches it — the loop below already skips exactly those rows — so
	 * stamping `updated_at` only on the rows this call rewrites, with the same
	 * "now" for the whole batch, gives the panel the one fact it was missing:
	 * whether THIS row's number came from a sync at all.
	 *
	 * @param array<int, array<string, mixed>> $currencies Stored currency configs.
	 * @param array<string, float|int|string>  $rates      Fetched rates, keyed by code.
	 * @return array{currencies: array<int, array<string, mixed>>, updated: int}
	 *         The list with automatic rates refreshed, and how many changed.
	 */
	public static function apply_rates( array $currencies, array $rates ): array {
		$updated = 0;
		$now     = time();

		foreach ( $currencies as $index => $currency ) {
			$code = $currency['code'] ?? '';

			if ( ! is_string( $code ) || '' === $code || ! isset( $rates[ $code ] ) ) {
				continue;
			}

			$rate = isset( $currency['rate'] ) && is_array( $currency['rate'] ) ? $currency['rate'] : array();

			if ( 'manual' === ( $rate['type'] ?? 'auto' ) ) {
				continue;
			}

			$rate['value']                = (float) $rates[ $code ];
			$rate['updated_at']           = $now;
			$currencies[ $index ]['rate'] = $rate;
			++$updated;
		}

		return array(
			'currencies' => $currencies,
			'updated'    => $updated,
		);
	}

	/**
	 * Store the applied rates and, only if that worked, stamp the sync.
	 *
	 * 🔴 The one below centralised the WRITE. It did not centralise the
	 * ORDERING, and the ordering is where the defect lived: three callers each
	 * remembered to save first, and none of them checked whether the save had
	 * worked before moving the clock. Its own docblock predicted this —
	 * "two get fixed and the third quietly keeps the old behaviour" — one level
	 * too low. So the rule moves up here: a caller can no longer stamp a sync
	 * it did not persist, because it cannot reach the stamp without going
	 * through the save.
	 *
	 * The busiest caller is the one that is easiest to forget: the panel button
	 * is pressed by hand, the cron runs every hour.
	 *
	 * @since 1.3.1
	 *
	 * @param CurrencyStore $store Store holding the applied rates.
	 * @param string        $base  Base currency the rates were fetched against.
	 * @return bool True when the rates were stored and the sync recorded.
	 */
	public static function commit_sync( CurrencyStore $store, string $base ): bool {
		if ( ! $store->save() ) {
			return false;
		}

		self::record_sync( $base );

		return true;
	}

	/**
	 * Record that rates were successfully synchronised against a base.
	 *
	 * 🔴 One writer, three callers. The REST button, the cron tick and the CLI
	 * command all apply rates, and the sync-lie defect was exactly what happens
	 * when the same five lines live in three places: two get fixed and the
	 * third quietly keeps the old behaviour.
	 *
	 * The base is stored alongside the time because the timestamp is only
	 * meaningful against the base it was fetched for. A shop that switches its
	 * WooCommerce base currency has rates that are no longer about anything,
	 * and the panel has to be able to say so.
	 *
	 * @param string $base Base currency code the rates were fetched against.
	 * @return void
	 */
	public static function record_sync( string $base ): void {
		update_option(
			self::LAST_SYNC_OPTION,
			array(
				'time' => time(),
				'base' => strtoupper( $base ),
			)
		);
	}

	/**
	 * Clear the transient cache for one or all base currencies.
	 *
	 * When `$base` is empty, a blanket delete is not possible with
	 * the Transient API, so this is effectively a no-op. Callers
	 * should pass a specific base currency code.
	 *
	 * @param string $base Base currency code, or empty for all.
	 * @return void
	 */
	public function clear_cache( string $base = '' ): void {
		if ( '' !== $base ) {
			delete_transient( self::TRANSIENT_KEY_PREFIX . strtoupper( $base ) );
		}
	}

	/**
	 * Parse the response body from ExchangeRate-API.
	 *
	 * Expected format: `{"rates": {"USD": 0.029, "EUR": 0.025, ...}}`
	 *
	 * @param array<string, mixed> $body Decoded JSON body.
	 * @return array<string, float> Currency code => rate map.
	 */
	public static function parse_exchangerate_response( array $body ): array {
		if ( ! isset( $body['rates'] ) || ! is_array( $body['rates'] ) ) {
			return array();
		}

		$rates = array();

		foreach ( $body['rates'] as $code => $value ) {
			if ( is_numeric( $value ) ) {
				$rates[ strtoupper( (string) $code ) ] = (float) $value;
			}
		}

		return $rates;
	}

	/**
	 * Parse the response body from the Fawaz Ahmed API.
	 *
	 * Expected format: `{"try": {"usd": 0.029, "eur": 0.025, ...}}`
	 * The outer key is the lowercase base currency code.
	 *
	 * @param array<string, mixed> $body Decoded JSON body.
	 * @param string               $base Base currency code.
	 * @return array<string, float> Currency code => rate map (uppercased keys).
	 */
	public static function parse_fawaz_response( array $body, string $base ): array {
		$base_lower = strtolower( $base );

		if ( ! isset( $body[ $base_lower ] ) || ! is_array( $body[ $base_lower ] ) ) {
			return array();
		}

		$rates = array();

		foreach ( $body[ $base_lower ] as $code => $value ) {
			if ( is_numeric( $value ) ) {
				$rates[ strtoupper( (string) $code ) ] = (float) $value;
			}
		}

		return $rates;
	}

	/**
	 * Fetch rates from the ExchangeRate-API (primary source).
	 *
	 * @param string $base Base currency code (ISO 4217).
	 * @return array<string, float> Currency code => rate map.
	 */
	private function fetch_from_exchangerate_api( string $base ): array {
		$url = 'https://api.exchangerate-api.com/v4/latest/' . strtoupper( $base );

		$data = $this->do_request( $url );

		if ( null === $data ) {
			return array();
		}

		return self::parse_exchangerate_response( $data );
	}

	/**
	 * Fetch rates from the Fawaz Ahmed Currency API (fallback).
	 *
	 * @param string $base Base currency code (ISO 4217).
	 * @return array<string, float> Currency code => rate map.
	 */
	private function fetch_from_fawaz_api( string $base ): array {
		$base_lower = strtolower( $base );

		/*
		 * 🔴 Do NOT switch this to the host the upstream README lists first.
		 * That host is a public JavaScript CDN, and WordPress.org keeps a fixed
		 * list of such domains in Plugin Check's offloading sniff: any shipped
		 * source that names one is an ERROR — "Offloading images, js, css, and
		 * other scripts ... is disallowed." It is a domain match, not an
		 * analysis of what the URL fetches, so pulling JSON exchange rates
		 * reads to it exactly like loading a script. Explaining the difference
		 * in a comment does not close the finding; only not naming the domain
		 * does. This plugin shipped that domain for four releases and no gate
		 * of ours could see it, because our PHPCS ruleset is not theirs.
		 *
		 * Cloudflare Pages serves the identical payload and is the fallback the
		 * same README names second. OffloadingHostsTest keeps the disallowed
		 * list out of both the source and readme.txt from now on.
		 *
		 * `latest` is part of the HOSTNAME here rather than a path segment, and
		 * that is the upstream's design: the alternative is pinning a date,
		 * which would freeze the rates on the day it was written.
		 */
		$url = 'https://latest.currency-api.pages.dev/v1/currencies/' . $base_lower . '.json';

		$data = $this->do_request( $url );

		if ( null === $data ) {
			return array();
		}

		return self::parse_fawaz_response( $data, $base );
	}

	/**
	 * Perform an HTTP GET request and return the decoded JSON body.
	 *
	 * @param string $url Full request URL.
	 * @return array<string, mixed>|null Decoded body or null on failure.
	 */
	private function do_request( string $url ): ?array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 10,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			return null;
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return $decoded;
	}
}
