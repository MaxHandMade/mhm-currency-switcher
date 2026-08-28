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
 * back to ExchangeRate-API, and finally to the European Central
 * Bank's daily reference rate feed.
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
	 * Action hook the automatic rate sync is scheduled on.
	 *
	 * 🔴 A constant because three different core calls have to agree on this
	 * string or the schedule leaks: wp_schedule_event() creates it,
	 * wp_clear_scheduled_hook() removes it, and wp_next_scheduled() is what
	 * both of those and the panel ask about. It was a literal in six places
	 * across two files; renaming five of them would have left an event nothing
	 * could find and nothing could clear, and no gate would have said so.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'mhmcs_update_rates';

	/**
	 * Fetch exchange rates for the given base currency.
	 *
	 * Lookup order:
	 *   1. Transient cache (unless `$force`).
	 *   2. ExchangeRate-API (primary).
	 *   3. European Central Bank daily reference rates (fallback).
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
	 * tick, `wp mhmcs rates sync` — is a request for current numbers and must
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

		// Fallback: European Central Bank daily reference rates.
		if ( empty( $rates ) ) {
			$rates = $this->fetch_from_ecb( $base );
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
	 * Let a site move the fallback source without forking the plugin.
	 *
	 * The measurement that first justified this filter was against a host
	 * this plugin no longer contacts: a Turkish network was found to block,
	 * at the network level, the Cloudflare Pages host that used to serve the
	 * first fallback. That host, and the second fallback added because of
	 * it, are both gone — ECB is now the only fallback, and no equivalent
	 * measurement exists for it.
	 *
	 * The filter stays because the need behind it does not depend on which
	 * host is being fetched: any network can block, rate-limit or otherwise
	 * refuse any host, and a shop behind such a block needs a way to point
	 * this one request somewhere reachable without waiting on a plugin
	 * release.
	 *
	 * @since 2.0.0
	 *
	 * @param string $url    Full request URL for this source.
	 * @param string $base   Base currency code, upper case.
	 * @param string $source Which source is being filtered. Always 'ecb' now
	 *                       that the chain has a single fallback.
	 * @return string
	 */
	private static function filter_fallback_url( string $url, string $base, string $source ): string {
		/**
		 * Filters the URL of a fallback exchange-rate source.
		 *
		 * 🔴 TRUST BOUNDARY, and why the request is not made with
		 * `wp_safe_remote_get()`.
		 *
		 * An independent audit noted that a filtered URL reaches
		 * `wp_remote_get()` and suggested the safe variant, which refuses
		 * private and loopback addresses. The audit also said, correctly, that
		 * this is not an anonymous SSRF surface: only code already running on
		 * the site can add a filter, and such code can call `wp_remote_get()`
		 * itself without asking this plugin. The filter grants no privilege
		 * that its caller did not already hold — true of whichever source it
		 * carries, not a property of the one it names today.
		 *
		 * The safe variant is still not adopted, but on a weaker basis than
		 * before: a shop redirecting this fallback might reasonably point it
		 * at an internal mirror or a proxy on a private address, which is
		 * exactly what `wp_http_validate_url()` rejects. Nothing measured
		 * makes that a present necessity — it is a contingency this filter
		 * stays able to serve, not a problem any shop has hit.
		 *
		 * So the boundary is stated rather than enforced: whoever adds this
		 * filter chooses the host, and is trusted to the same degree as any
		 * other code running in the site.
		 *
		 * @since 2.0.0
		 *
		 * @param string $url    Full request URL for this source.
		 * @param string $base   Base currency code, upper case.
		 * @param string $source Which source is being filtered.
		 */
		return (string) apply_filters( 'mhmcs_fallback_rates_url', $url, $base, $source );
	}

	/**
	 * Fetch rates from the European Central Bank daily reference feed
	 * (fallback — the only one left in the chain).
	 *
	 * ECB publishes no document titled "Terms of Service" — its terms of use
	 * are stated in a Disclaimer & Copyright page, and it separately
	 * publishes a privacy statement. The two sources this one replaced
	 * published neither in a form that could be linked. See the class
	 * docblock's lookup order and filter_fallback_url() for what moved and
	 * why.
	 *
	 * The feed itself is EUR-based and carries no EUR row; when $base is not
	 * EUR, cross_rates() re-expresses every value relative to $base instead.
	 * See cross_rates() for the arithmetic and its guards — in particular,
	 * why $base itself never ends up as a key of the result.
	 *
	 * @param string $base Base currency code (ISO 4217).
	 * @return array<string, float> Currency code => rate map, empty on failure.
	 */
	private function fetch_from_ecb( string $base ): array {
		$url = self::filter_fallback_url( self::ECB_FEED_URL, strtoupper( $base ), 'ecb' );

		$doc = $this->do_xml_request( $url );

		if ( null === $doc ) {
			return array();
		}

		return self::cross_rates( self::cube_rates( $doc ), $base );
	}

	/**
	 * European Central Bank daily reference rate feed.
	 *
	 * @var string
	 */
	private const ECB_FEED_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

	/**
	 * European Central Bank vocabulary namespace for the daily reference
	 * rate feed. The `Cube` nodes inherit this as their DEFAULT namespace,
	 * which is the whole reason a plain property walk cannot see them.
	 *
	 * @var string
	 */
	private const ECB_NAMESPACE = 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref';

	/**
	 * Parse a raw XML string with libxml's unsafe defaults switched off,
	 * and restore the changed global flag afterwards regardless of outcome.
	 *
	 * Separate from do_xml_request() so the same safe-parsing path serves
	 * both a live HTTP body and a raw string handed in directly (as the
	 * ECB parser test fixtures do) — the tricky global-state handling
	 * exists in exactly one place.
	 *
	 * 🔴 libxml_use_internal_errors() is a PROCESS-GLOBAL flag, not a
	 * per-call setting. Every exit path — including the `false === $xml`
	 * failure — goes through the `finally` block so it never leaks into
	 * unrelated XML work later in the same request.
	 *
	 * Entity handling, stated plainly rather than defended redundantly:
	 * `LIBXML_NOENT` is deliberately NOT passed, so entity references are
	 * not force-substituted into the tree. `LIBXML_NONET` blocks network
	 * access during the parse, and `LIBXML_DTDLOAD`/`LIBXML_DTDVALID` are
	 * never passed, so an external entity (`SYSTEM "file://..."` or a
	 * remote URL) cannot be fetched — the parse fails outright and
	 * simplexml_load_string() returns `false` (verified: a `SYSTEM
	 * "file:///etc/hosts"` entity makes the whole document unparsable,
	 * it does not leak the file's contents). What this method does NOT
	 * additionally guard against is entity SUBSTITUTION itself: that has
	 * been off by default since libxml 2.9.0 (2012), and PHP 7.4 — this
	 * plugin's floor — shipped in 2019, long after every supported
	 * distribution had moved past libxml < 2.9. An explicit
	 * libxml_disable_entity_loader() call was tried here and removed: it
	 * protects a practically empty set of installs while adding a
	 * deprecated-function finding (PHP 8 removed the function's effect
	 * entirely) that WordPress.org's review tooling flags regardless of
	 * a version guard around the call.
	 *
	 * @param string $body Raw XML document.
	 * @return \SimpleXMLElement|null Parsed document, or null on failure.
	 */
	private static function parse_xml_body( string $body ): ?\SimpleXMLElement {
		if ( ! function_exists( 'simplexml_load_string' ) ) {
			// ext-simplexml is declared in composer.json, but a ZIP
			// install does not enforce composer requirements.
			return null;
		}

		$prev_errors = libxml_use_internal_errors( true );

		try {
			// LIBXML_NOENT is deliberately NOT passed. LIBXML_NONET blocks
			// network access during the parse (no external DTD/entity
			// fetch, regardless of what the document asks for).
			$xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );

			return false === $xml ? null : $xml;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $prev_errors );
		}
	}

	/**
	 * Perform an HTTP GET and return the parsed XML document.
	 *
	 * A sibling of do_request(), not a modification of it: that method
	 * ends in json_decode(), and the European Central Bank feed this
	 * serves is text/xml, not JSON.
	 *
	 * @param string $url Full request URL.
	 * @return \SimpleXMLElement|null Parsed document, or null on failure.
	 */
	private function do_xml_request( string $url ): ?\SimpleXMLElement {
		if ( ! function_exists( 'simplexml_load_string' ) ) {
			return null;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 10,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return null;
		}

		return self::parse_xml_body( $body );
	}

	/**
	 * Parse the European Central Bank daily reference rate feed.
	 *
	 * Expected format (namespaces abbreviated):
	 *   <gesmes:Envelope>
	 *     <Cube><Cube time="...">
	 *       <Cube currency="USD" rate="1.1645"/>
	 *       ...
	 *     </Cube></Cube>
	 *   </gesmes:Envelope>
	 *
	 * 🔴 NAMESPACE TRAP: the `Cube` nodes carry a default namespace
	 * (self::ECB_NAMESPACE) inherited from the root element. A plain
	 * `$xml->Cube` property walk returns nothing for a namespaced
	 * document — no error, just an empty result that looks exactly like
	 * "no rates today". children( self::ECB_NAMESPACE ) is what actually
	 * reaches the rows.
	 *
	 * EUR is not in the list: the feed is EUR-based, EUR is the implicit
	 * base, and there is no `<Cube currency="EUR">` row. Callers that need
	 * EUR as a target currency get there through cross-rate arithmetic
	 * (cross_rates()), not from this parser.
	 *
	 * A currency code is only accepted when it is exactly three letters —
	 * the ISO 4217 shape. That is not merely tidiness: an internal
	 * DOCTYPE entity declared in the document is expanded as part of
	 * ordinary attribute-value normalisation regardless of the LIBXML_NOENT
	 * flag, so a malicious `currency="&e;"` can still surface its expanded
	 * text here even though no external entity was ever fetched. The
	 * three-letter shape check is what keeps that text out of the
	 * returned rate map without relying on the document being rejected
	 * outright.
	 *
	 * @param string $xml Raw XML document (a full HTTP body, or a raw
	 *                     string handed in directly — both go through the
	 *                     same safe parse).
	 * @return array<string, float> Currency code => rate map, or an empty
	 *                              array on any parse failure.
	 */
	public static function parse_ecb_response( string $xml ): array {
		$doc = self::parse_xml_body( $xml );

		if ( null === $doc ) {
			return array();
		}

		return self::cube_rates( $doc );
	}

	/**
	 * Walk an already-parsed ECB document's Cube tree into a rate map.
	 *
	 * Split out of parse_ecb_response() so fetch_from_ecb() can reuse the
	 * \SimpleXMLElement do_xml_request() already parsed from the live HTTP
	 * body, instead of serialising it back to a string only to parse it a
	 * second time. See parse_ecb_response() for the namespace trap and the
	 * ISO-4217 shape guard this walk relies on.
	 *
	 * @param \SimpleXMLElement $doc Parsed ECB document.
	 * @return array<string, float> Currency code => rate map.
	 */
	private static function cube_rates( \SimpleXMLElement $doc ): array {
		$rates = array();

		$outer_cube = $doc->children( self::ECB_NAMESPACE )->Cube ?? null;

		if ( null === $outer_cube ) {
			return array();
		}

		$inner_cube = $outer_cube->children( self::ECB_NAMESPACE )->Cube ?? null;

		if ( null === $inner_cube ) {
			return array();
		}

		foreach ( $inner_cube->children( self::ECB_NAMESPACE ) as $cube ) {
			$attributes = $cube->attributes();
			$code       = isset( $attributes['currency'] ) ? strtoupper( (string) $attributes['currency'] ) : '';
			$rate       = isset( $attributes['rate'] ) ? (string) $attributes['rate'] : '';

			if ( 1 !== preg_match( '/^[A-Z]{3}$/', $code ) || ! is_numeric( $rate ) ) {
				continue;
			}

			$rates[ $code ] = (float) $rate;
		}

		return $rates;
	}

	/**
	 * Turn an EUR-based ECB rate table into one relative to $base.
	 *
	 * The ECB feed is EUR-based and carries no EUR row — EUR is the implicit
	 * base of every value in $ecb. For a caller whose own base IS EUR the
	 * table already is what they asked for, so it passes through unchanged.
	 * For any other base, every value is re-expressed relative to that
	 * base's own EUR rate:
	 *
	 *   rate(X)   = ecb[X] / ecb[base]   for every other X in $ecb
	 *   rate(EUR) = 1 / ecb[base]        -- EUR becomes a normal target
	 *
	 * 🔴 The base itself is NEVER a key of the result, even though the raw
	 * formula above would put it there as ecb[base]/ecb[base] == 1.0. The
	 * fallback source this one replaces never returned the base in its own
	 * rate table, and fetch_single_rate( $base, $base ) depends on that:
	 * preserving it here keeps that call's behaviour exactly what it was
	 * before ECB existed.
	 *
	 * A zero or negative $ecb[$base] is a failure, not a division: it is
	 * rejected before the arithmetic ever runs. A $base absent from $ecb
	 * altogether is the same failure, for the same reason — there is no
	 * rate to divide by.
	 *
	 * @param array<string, float> $ecb  ECB rate table, EUR-based, upper-case codes.
	 * @param string               $base Base currency code (ISO 4217, any case).
	 * @return array<string, float> Currency code => rate map relative to $base,
	 *                              or an empty array when $base cannot be priced.
	 */
	public static function cross_rates( array $ecb, string $base ): array {
		$base = strtoupper( $base );

		if ( 'EUR' === $base ) {
			return $ecb;
		}

		if ( ! isset( $ecb[ $base ] ) || (float) $ecb[ $base ] <= 0.0 ) {
			return array();
		}

		$base_rate = (float) $ecb[ $base ];
		$rates     = array( 'EUR' => 1 / $base_rate );

		foreach ( $ecb as $code => $rate ) {
			if ( $code === $base ) {
				continue;
			}

			$rates[ $code ] = (float) $rate / $base_rate;
		}

		return $rates;
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
