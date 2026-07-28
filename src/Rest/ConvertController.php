<?php
/**
 * Public price-conversion endpoint.
 *
 * @package MhmCurrencySwitcher\Rest
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Rest;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MhmCurrencySwitcher\Core\ConversionContext;
use MhmCurrencySwitcher\Core\DetectionService;
use WC_Product;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST mhmcs/v1/convert` — price a batch of products in one currency.
 *
 * In cache-compatibility mode a catalogue page is rendered, and cached, in the
 * shop's base currency for every visitor alike, with each price marked by
 * PriceDisplayMarker. The browser collects those markers and asks this
 * endpoint for the same prices in the visitor's own currency. This class is
 * therefore the one place where the server deliberately converts on a request
 * that its own decision table resolves to "base" — a REST call that is not the
 * Store API is decision 2, so without the forced scope below the endpoint
 * would answer with exactly the base prices the caller already had.
 *
 * ## It is unauthenticated on purpose
 *
 * The pages it serves are cached pages, whose readers are by definition logged
 * out and carry no nonce that would survive caching. A logged-in visitor never
 * takes this path at all: decision 6 converts their prices server-side and no
 * marker is emitted for them. So the endpoint is public, and the design work
 * went into bounding what "public" can reach rather than into a check that
 * could not have worked:
 *
 * - It answers with `price_html` only, for posts that are already publicly
 *   readable — the same strings the same anonymous caller can read off the
 *   shop pages, in a currency the shop publishes exchange rates for.
 * - Anything not published, password-protected, or not a product is skipped
 *   SILENTLY. An error naming the ID would confirm that the post exists and
 *   is hidden, which the site itself does not tell them.
 * - A currency the shop does not offer resolves to the base currency instead
 *   of erroring, so the endpoint cannot be used to enumerate configuration.
 * - The batch is capped server-side, on the count the caller SENT.
 * - Nothing is written. No cookie, no option, no post meta; the request
 *   override and the forced-conversion scope are both undone before the
 *   response leaves.
 *
 * @since 1.1.0
 */
final class ConvertController {

	/**
	 * REST namespace.
	 *
	 * Restated rather than imported from Admin\RestAPI: this controller is a
	 * front-end surface and must not depend on the admin one, and the string
	 * is a published URL either way.
	 *
	 * @var string
	 */
	const NAMESPACE_V1 = 'mhmcs/v1';

	/**
	 * Route path within the namespace.
	 *
	 * @var string
	 */
	const ROUTE = '/convert';

	/**
	 * Maximum product IDs accepted in a single request.
	 *
	 * The client chunks its markers to this size (design spec §5.1), but this
	 * constant is the SERVER's limit, not a restatement of the client's
	 * convention: a caller that ignores the chunking must not be able to buy
	 * an unbounded number of post lookups and WooCommerce price renders with
	 * one anonymous request.
	 *
	 * @var int
	 */
	const MAX_PRODUCT_IDS = 50;

	/**
	 * Post types this endpoint will price.
	 *
	 * `product_variation` is included because the marker addresses a variation
	 * by its own ID (round 2 / H4); its parent is validated separately.
	 *
	 * @var array<int, string>
	 */
	const ALLOWED_POST_TYPES = array( 'product', 'product_variation' );

	/**
	 * Requests one address may make per window before being refused.
	 *
	 * Every other axis of this endpoint is already bounded — 50 IDs a request,
	 * a validated currency, a visibility check per product — and the number of
	 * requests was the one that was not. The cost of a request is about the
	 * cost of a shop page, so this is not an amplification primitive; it is
	 * simply the last unbounded axis, and an unauthenticated route should not
	 * have one.
	 *
	 * Generous on purpose. A page makes one request, or a few when blocks
	 * hydrate late, and behind a corporate NAT or a mobile carrier a great many
	 * real visitors arrive as one address. A tight limit would take the feature
	 * away from exactly those people; `mhmcs_convert_rate_limit` is there for
	 * the shops that need it different.
	 *
	 * @var int
	 */
	const RATE_LIMIT_REQUESTS = 120;

	/**
	 * Length of the rate-limit window, in seconds.
	 *
	 * @var int
	 */
	const RATE_LIMIT_WINDOW = 60;

	/**
	 * Transient key prefix for the per-address counters.
	 *
	 * @var string
	 */
	const RATE_LIMIT_PREFIX = 'mhmcs_rl_';

	/**
	 * Shared conversion-context resolver.
	 *
	 * @var ConversionContext
	 */
	private ConversionContext $context;

	/**
	 * Shared currency-detection service.
	 *
	 * @var DetectionService
	 */
	private DetectionService $detection;

	/**
	 * Constructor.
	 *
	 * Both collaborators must be the request's shared instances, the same ones
	 * every price filter received. The endpoint pins a currency on the
	 * detection service and forces the context; a filter reading a different
	 * instance would price the response in the visitor's own currency instead
	 * of the requested one.
	 *
	 * @param ConversionContext $context   The request's context resolver.
	 * @param DetectionService  $detection The request's detection service.
	 */
	public function __construct( ConversionContext $context, DetectionService $detection ) {
		$this->context   = $context;
		$this->detection = $detection;
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
	 * Register the route.
	 *
	 * Registered unconditionally, including when cache compatibility is
	 * switched off. Turning the mode off does not purge the pages already
	 * sitting in caches, and their scripts keep calling this endpoint; a route
	 * that vanished with the setting would answer those with a 404 and freeze
	 * them in the base currency.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'convert' ),

				/*
				 * Public by design, and stated explicitly rather than omitted.
				 * An omitted permission_callback is a WordPress.org rejection
				 * on its own, and it reads as an oversight even when the route
				 * is meant to be public — which this one is, for the reasons
				 * in the class docblock: it serves cached pages, whose readers
				 * are logged out and carry no nonce that could survive being
				 * cached. There is a precedent in this plugin, the public
				 * `/rates` route. The safety of this route is in the input
				 * validation and the visibility checks below, not here.
				 */
				'permission_callback' => '__return_true',
				'args'                => array(
					'currency'    => array(
						'description'       => __( 'ISO 4217 currency code to price in. Omit it, or send null, to have the server detect the visitor currency.', 'mhm-currency-switcher' ),
						'type'              => array( 'string', 'null' ),
						'required'          => false,
						'default'           => null,
						'validate_callback' => array( $this, 'validate_currency' ),
						'sanitize_callback' => array( $this, 'sanitize_currency' ),
					),
					'product_ids' => array(
						'description'       => __( 'Product or variation IDs to price.', 'mhm-currency-switcher' ),
						'type'              => 'array',
						'required'          => true,
						'items'             => array( 'type' => 'integer' ),

						/*
						 * Declared so the limit is discoverable in the route
						 * schema. It is NOT the enforcement: validate_callback
						 * replaces schema validation, and the check there is
						 * what actually bounds the request.
						 */
						'maxItems'          => self::MAX_PRODUCT_IDS,
						'validate_callback' => array( $this, 'validate_product_ids' ),
						'sanitize_callback' => array( $this, 'sanitize_product_ids' ),
					),
				),
			)
		);
	}

	/**
	 * Validate the `currency` parameter's shape.
	 *
	 * Deliberately permissive about the VALUE: a well-formed code the shop
	 * does not offer, and a malformed one, both resolve to the base currency
	 * further down rather than erroring here. Rejecting them would let an
	 * anonymous caller enumerate which currencies the shop has enabled by
	 * comparing status codes.
	 *
	 * @param mixed $value Raw parameter value.
	 * @return true|WP_Error True when the shape is acceptable.
	 */
	public function validate_currency( $value ) {
		if ( null === $value || is_string( $value ) ) {
			return true;
		}

		return new WP_Error(
			'rest_invalid_param',
			__( 'The currency parameter must be a string or null.', 'mhm-currency-switcher' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Normalise the `currency` parameter.
	 *
	 * Uses DetectionService::sanitize_currency_code(), the same rule the
	 * detection chain enforces on cookies and URL parameters, rather than a
	 * second regex written here — that method was made public and static in
	 * Task 3 precisely so this endpoint could share it, and two copies of an
	 * ISO-4217 rule are two things that can drift apart.
	 *
	 * Three outcomes, and the difference between the last two matters:
	 *
	 * - null  — nothing was sent: "detect the visitor's currency for me".
	 * - CODE  — a well-formed code; whether the shop offers it is decided later.
	 * - ''    — something was sent but it is not a currency code. Mapped to the
	 *           empty string and NOT to null, because a caller who sent
	 *           rubbish is not asking to be geolocated, and silently starting
	 *           a lookup they did not request would be the wrong answer to
	 *           their mistake.
	 *
	 * @param mixed $value Raw parameter value.
	 * @return string|null Sanitised code, the empty string, or null.
	 */
	public function sanitize_currency( $value ) {
		if ( null === $value ) {
			return null;
		}

		$code = DetectionService::sanitize_currency_code( $value );

		return null === $code ? '' : $code;
	}

	/**
	 * Validate the `product_ids` parameter.
	 *
	 * 🔴 The cap is applied to the array the caller SENT, before any
	 * de-duplication. Collapsing duplicates first and counting afterwards
	 * would bound the response but not the work: five thousand IDs would still
	 * be five thousand values to walk, and a batch of repeats would slip
	 * inside the limit while a batch of distinct IDs did not.
	 *
	 * @param mixed $value Raw parameter value.
	 * @return true|WP_Error True when the batch is acceptable.
	 */
	public function validate_product_ids( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'The product_ids parameter must be an array of IDs.', 'mhm-currency-switcher' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $value ) > self::MAX_PRODUCT_IDS ) {
			return new WP_Error(
				'mhmcs_too_many_products',
				__( 'Too many product IDs in a single request.', 'mhm-currency-switcher' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Reduce `product_ids` to a list of unique, positive integer IDs.
	 *
	 * Entries that are not numeric are dropped rather than rejected: the batch
	 * has already been bounded, and a caller who sends one odd value in an
	 * otherwise valid list is better served by the prices they can have than
	 * by an error.
	 *
	 * Negative values are dropped BEFORE absint() rather than passed through
	 * it. absint( -5 ) is 5, so a stray minus sign would quietly become a
	 * request for a different, real product.
	 *
	 * @param mixed $value Raw parameter value.
	 * @return array<int, int> Unique positive IDs.
	 */
	public function sanitize_product_ids( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$unique = array();

		foreach ( $value as $raw ) {
			if ( ! is_int( $raw ) && ! ( is_string( $raw ) && is_numeric( $raw ) ) ) {
				continue;
			}

			$number = (int) $raw;

			if ( $number < 1 ) {
				continue;
			}

			$unique[ absint( $number ) ] = true;
		}

		return array_keys( $unique );
	}

	/**
	 * Count this request against the caller's address and say whether it is over.
	 *
	 * The window is stored with its own expiry inside the transient rather than
	 * relying on the transient's TTL, because set_transient() resets that TTL
	 * on every write — an address that kept knocking would push its own window
	 * forward for ever and never come out of it.
	 *
	 * The address comes from WooCommerce when it is available. That reads the
	 * proxy headers WooCommerce is configured to trust, which is a deliberate
	 * choice with a real trade-off: those headers can be forged, so a
	 * determined attacker rotates them and walks past this. Using REMOTE_ADDR
	 * instead would be unforgeable and would also, on any site behind
	 * Cloudflare or a load balancer, make every visitor share one counter and
	 * take the feature down for the whole shop at once. This limit exists to
	 * bound accidental and naive hammering; a determined attacker has a botnet
	 * and no per-address limit stops that anyway. Documented in readme.txt.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when this caller has exceeded its allowance.
	 */
	public static function is_rate_limited(): bool {
		/**
		 * Filters the convert endpoint's rate limit.
		 *
		 * A limit of zero or less switches rate limiting off. A shop behind a
		 * reverse proxy sees every visitor as one address, so the default can
		 * be wrong for reasons the plugin cannot detect from the inside.
		 *
		 * @since 1.1.0
		 *
		 * @param array{limit: int, window: int} $args Requests allowed, and the
		 *                                             window in seconds.
		 */
		$args = apply_filters(
			'mhmcs_convert_rate_limit',
			array(
				'limit'  => self::RATE_LIMIT_REQUESTS,
				'window' => self::RATE_LIMIT_WINDOW,
			)
		);

		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : self::RATE_LIMIT_REQUESTS;
		$window = isset( $args['window'] ) ? (int) $args['window'] : self::RATE_LIMIT_WINDOW;

		if ( $limit <= 0 || $window <= 0 ) {
			return false;
		}

		$key = self::RATE_LIMIT_PREFIX . md5( self::client_address() );
		$now = time();

		$bucket = get_transient( $key );

		if ( ! is_array( $bucket ) || ! isset( $bucket['count'], $bucket['expires'] ) || $bucket['expires'] <= $now ) {
			$bucket = array(
				'count'   => 0,
				'expires' => $now + $window,
			);
		}

		++$bucket['count'];

		set_transient( $key, $bucket, max( 1, (int) $bucket['expires'] - $now ) );

		return $bucket['count'] > $limit;
	}

	/**
	 * The address this request appears to come from.
	 *
	 * @return string Client address, or an empty string when none is available.
	 */
	private static function client_address(): string {
		if ( class_exists( 'WC_Geolocation' ) && method_exists( 'WC_Geolocation', 'get_ip_address' ) ) {
			return (string) \WC_Geolocation::get_ip_address();
		}

		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}

	/**
	 * Handle the request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response Resolved currency, detection flag and prices.
	 */
	public function convert( WP_REST_Request $request ): WP_REST_Response {
		if ( self::is_rate_limited() ) {
			return new WP_REST_Response(
				array(
					'code'    => 'mhmcs_rate_limited',
					'message' => __( 'Too many currency conversion requests. Please try again shortly.', 'mhm-currency-switcher' ),
				),
				429,
				array( 'Retry-After' => (string) self::RATE_LIMIT_WINDOW )
			);
		}

		$requested = $request->get_param( 'currency' );
		$ids       = (array) $request->get_param( 'product_ids' );

		$detected = false;

		if ( null === $requested ) {
			$found = $this->detect_without_persisting();

			/*
			 * `detected` reports whether the SERVER resolved the currency, and
			 * the client writes its cookie on it (design spec §5.4). A failed
			 * geolocation must therefore come back false, or the client pins
			 * the base currency, the detection chain stops at its first step
			 * on every later page, and geolocation is never retried for that
			 * visitor. It stays false for an explicit currency too: the caller
			 * already knew that one, so there is nothing for us to have told
			 * them.
			 */
			$detected  = ( null !== $found );
			$requested = (string) $found;
		}

		try {
			/*
			 * A code the store cannot honour — unusable, or well-formed but
			 * not enabled — resolves to the base currency inside
			 * set_request_override(). Silently, and that is the point: an
			 * error would answer "this shop does not offer that currency" and
			 * turn the endpoint into a probe for the shop's configuration.
			 */
			$this->detection->set_request_override( $requested );

			$currency = $this->detection->get_current_currency();

			$prices = $this->context->with_forced_conversion(
				function () use ( $ids ) {
					return $this->render_prices( $ids );
				}
			);

			$response = new WP_REST_Response(
				array(
					'currency' => $currency,
					'detected' => $detected,
					'prices'   => $prices,
				)
			);

			/*
			 * The body depends on a cookie, so any shared cache that keyed it
			 * by URL alone would hand one visitor's currency to the next —
			 * the precise failure this whole feature exists to avoid.
			 */
			$response->header( 'Cache-Control', 'no-store' );

			return $response;
		} finally {
			/*
			 * The override must not outlive the response. The request that can
			 * still have a "rest of" is the dangerous one: a page render that
			 * dispatched this endpoint through rest_do_request() would print
			 * every remaining price in the REST caller's currency, without a
			 * marker, and then hand that page to the cache.
			 */
			$this->detection->clear_request_override();
		}
	}

	/**
	 * Resolve the visitor's currency without leaving a cookie behind.
	 *
	 * The chain itself lives in DetectionService so that this endpoint and a
	 * page render cannot disagree about it (spec §5.2). All that is added here
	 * is the suppression: in cache mode the cookie belongs to the client
	 * (§5.3), which decides for itself whether a detection was good enough to
	 * keep. A server-side write on this request would mean the same cookie is
	 * written from two places, and would pin a currency the client had
	 * deliberately chosen not to persist.
	 *
	 * @return string|null Detected code, or null when nothing was detectable.
	 */
	private function detect_without_persisting(): ?string {
		$this->detection->set_cookie_persistence( false );

		try {
			return $this->detection->detect_currency();
		} finally {
			$this->detection->set_cookie_persistence( true );
		}
	}

	/**
	 * Render `price_html` for every ID the caller is allowed to see.
	 *
	 * @param array<int, int> $ids Sanitised product or variation IDs.
	 * @return array<int, string> Price HTML keyed by ID.
	 */
	private function render_prices( array $ids ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$prices = array();

		foreach ( $ids as $id ) {
			$id = (int) $id;

			if ( ! $this->is_publicly_priceable( $id ) ) {
				continue;
			}

			$product = wc_get_product( $id );

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$html = $product->get_price_html();

			if ( ! is_string( $html ) || '' === $html ) {
				// A shop that shows no price for this product must not be made
				// to show one; an empty string written into the page would
				// erase the markup the marker was wrapping.
				continue;
			}

			$prices[ $id ] = $html;
		}

		return $prices;
	}

	/**
	 * Whether an anonymous caller may be given this post's price.
	 *
	 * Every rejection here is silent — the ID is simply absent from the
	 * response. The alternative, an error naming the ID, would confirm that a
	 * post exists and is not public, which is more than the site itself tells
	 * an anonymous visitor.
	 *
	 * @param int $id Post ID.
	 * @return bool True when the post is a publicly readable product or
	 *              variation.
	 */
	private function is_publicly_priceable( int $id ): bool {
		$post = get_post( $id );

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( ! in_array( $post->post_type, self::ALLOWED_POST_TYPES, true ) ) {
			/*
			 * A deliberate SECOND gate, and described as such because mutation
			 * testing showed it is not currently the load-bearing one:
			 * wc_get_product() below returns false for any other post type, so
			 * removing this check does not change the response. It is kept
			 * because the alternative is for the endpoint's contract to be an
			 * accident of WooCommerce's product factory, and because refusing
			 * here means an arbitrary post ID is never handed to WooCommerce or
			 * put through the password machinery in the first place.
			 */
			return false;
		}

		if ( ! $this->is_publicly_readable( $post ) ) {
			return false;
		}

		if ( 'product_variation' !== $post->post_type ) {
			return true;
		}

		/*
		 * A variation is checked on its OWN status above and on its parent
		 * here, and both are needed (round 3 / M-1). WooCommerce gives a
		 * variation the shop owner disabled the status `private` while its
		 * parent stays published, so a parent-only check would publish the
		 * price of every disabled variation in the shop; and a variation of an
		 * unpublished variable product keeps saying `publish`, so an own-status
		 * check alone would publish those.
		 */
		$parent = get_post( $post->post_parent );

		if ( ! $parent instanceof WP_Post || 'product' !== $parent->post_type ) {
			return false;
		}

		return $this->is_publicly_readable( $parent );
	}

	/**
	 * Whether a post is readable by the anonymous caller in front of us.
	 *
	 * @param WP_Post $post Post to check.
	 * @return bool True when published and not withheld behind a password.
	 */
	private function is_publicly_readable( WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status ) {
			// Covers draft, pending, future, private and trash in one rule.
			return false;
		}

		// The price is exactly what a post password is withholding.
		return ! post_password_required( $post );
	}
}
