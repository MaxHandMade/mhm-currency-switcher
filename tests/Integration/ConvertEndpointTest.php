<?php
/**
 * Integration tests: POST mhmcs/v1/convert (design spec §5.1, §6 and §9C,
 * implementation plan Task 6).
 *
 * This endpoint is UNAUTHENTICATED by design (spec §6), so most of this file
 * is not about the happy path. It is about what an anonymous caller can reach:
 * which products it may price, how many per request, what the response is
 * allowed to say, and what it must not confirm about a post it cannot see.
 *
 * 🔴 Every test here first makes the request LOOK like the real one, by
 * setting `$_GET['rest_route']`. That is not decoration. A genuine
 * `POST /wp-json/mhmcs/v1/convert` is a REST request that is not the Store
 * API, so ConversionContext decision 2 resolves it to the BASE currency —
 * forcing is the only reason the endpoint converts at all. Dispatched through
 * rest_do_request() without that query var the context falls through to other
 * branches (a money context, say, which an earlier test file pins for the rest
 * of the process by defining WOOCOMMERCE_CART) and every "the endpoint
 * converts" assertion below would pass whether or not the controller ever
 * called force_convert(). With it set, decision 2 sits above the money branch
 * and the assertions bite.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Class ConvertEndpointTest
 */
class ConvertEndpointTest extends MhmcsIntegrationTestCase {

	/**
	 * The route under test.
	 *
	 * Spelled out rather than read from the controller's constants: this is a
	 * published URL, a contract with assets/js/price-converter.js (Task 7) and
	 * with every page already sitting in somebody's cache. A test that derived
	 * it from the code under test could never notice it moving.
	 *
	 * @var string
	 */
	private const ROUTE = '/mhmcs/v1/convert';

	/**
	 * Server-side cap on IDs per request (spec §6).
	 *
	 * @var int
	 */
	private const BATCH_LIMIT = 50;

	/**
	 * Start every test as an anonymous visitor issuing a REST call.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( 0 );

		$_GET['rest_route'] = self::ROUTE;
	}

	/**
	 * Leave no request shape, geolocation setting or detection state behind.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_GET['rest_route'] );

		$this->reset_detection_service();

		parent::tear_down();
	}

	// ─── The contract: request in, prices out ────────────────────────

	/**
	 * The happy path: every requested ID comes back with its price_html
	 * rendered in the requested currency.
	 *
	 * @return void
	 */
	public function test_returns_converted_price_html_for_each_id(): void {
		$first  = $this->create_simple_product( 100.0 );
		$second = $this->create_simple_product( 55.0 );

		$response = $this->convert(
			array(
				'currency'    => self::TARGET_CURRENCY,
				'product_ids' => array( $first->get_id(), $second->get_id() ),
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( self::TARGET_CURRENCY, $data['currency'] );
		$this->assertCount( 2, $data['prices'] );

		$this->assertStringContainsString( '200.00', $data['prices'][ $first->get_id() ], '100 * 2.0 — the endpoint must actually convert, which only force_convert() can make it do on a REST request.' );
		$this->assertStringContainsString( self::TARGET_SYMBOL, $data['prices'][ $first->get_id() ], 'The currency FORMAT must be converted too, not just the amount.' );
		$this->assertStringContainsString( '110.00', $data['prices'][ $second->get_id() ], '55 * 2.0 — each ID is priced on its own, not cross-wired.' );
	}

	/**
	 * The response body carries the three documented keys and nothing else
	 * (spec §6).
	 *
	 * The endpoint answers anonymous callers, so every extra field is a
	 * decision to publish something. Stock, SKU, name, visibility or raw
	 * amounts would each say more about a product than the page the caller
	 * already has.
	 *
	 * @return void
	 */
	public function test_response_contains_only_prices_currency_and_detected(): void {
		$product = $this->create_simple_product( 100.0 );

		$data = $this->convert(
			array(
				'currency'    => self::TARGET_CURRENCY,
				'product_ids' => array( $product->get_id() ),
			)
		)->get_data();

		$keys = array_keys( $data );
		sort( $keys );

		$this->assertSame(
			array( 'currency', 'detected', 'prices' ),
			$keys,
			'The response must publish nothing beyond the resolved currency, the detection flag and the prices.'
		);
		$this->assertIsBool( $data['detected'] );
	}

	/**
	 * 🔴 The response must not be cached (spec §6).
	 *
	 * Its whole body depends on the caller's currency, which is carried in a
	 * cookie. Anything that cached it by URL alone would serve one visitor's
	 * currency to the next.
	 *
	 * @return void
	 */
	public function test_response_sends_a_no_store_cache_control_header(): void {
		$product = $this->create_simple_product( 100.0 );

		$headers = $this->convert(
			array(
				'currency'    => self::TARGET_CURRENCY,
				'product_ids' => array( $product->get_id() ),
			)
		)->get_headers();

		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertStringContainsString(
			'no-store',
			$headers['Cache-Control'],
			'A per-visitor response served from a shared cache is the exact failure this whole feature exists to avoid.'
		);
	}

	/**
	 * The endpoint's own output must never carry a marker.
	 *
	 * A marker means "this is a base price, please convert it". The endpoint's
	 * output is already converted; if the client wrote a marked price back
	 * into the page it would be collected and converted again on the next run.
	 *
	 * @return void
	 */
	public function test_converted_price_html_carries_no_marker(): void {
		$product = $this->create_simple_product( 100.0 );

		$data = $this->convert(
			array(
				'currency'    => self::TARGET_CURRENCY,
				'product_ids' => array( $product->get_id() ),
			)
		)->get_data();

		$this->assertStringNotContainsString(
			'mhmcs-price',
			$data['prices'][ $product->get_id() ],
			'A marker inside the endpoint\'s own answer would be collected on the next pass and converted a second time.'
		);
	}

	/**
	 * An anonymous caller is served: the endpoint is public by design.
	 *
	 * @return void
	 */
	public function test_anonymous_callers_are_served(): void {
		$this->assertSame( 0, get_current_user_id(), 'Guard: this test only means something logged out.' );

		$product = $this->create_simple_product( 100.0 );

		$response = $this->convert(
			array(
				'currency'    => self::TARGET_CURRENCY,
				'product_ids' => array( $product->get_id() ),
			)
		);

		$this->assertSame( 200, $response->get_status(), 'The endpoint serves cached pages, whose visitors are by definition logged out.' );
	}

	// ─── The batch bound (spec §6) ───────────────────────────────────

	/**
	 * 🔴 More than 50 IDs is refused, and refused as a 400 — not silently
	 * truncated and not honoured.
	 *
	 * @return void
	 */
	public function test_rejects_more_than_fifty_ids(): void {
		$product = $this->create_simple_product( 100.0 );

		$response = $this->convert(
			array(
				'currency'    => self::TARGET_CURRENCY,
				'product_ids' => array_fill( 0, self::BATCH_LIMIT + 1, $product->get_id() ),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayNotHasKey( 'prices', (array) $response->get_data(), 'A rejected batch must do no work at all.' );
	}

	/**
	 * 🔴 The cap is on what the caller SENT, before de-duplication.
	 *
	 * De-duplicating first and then counting would let a caller post five
	 * thousand IDs and still be inside the limit whenever they repeat — the
	 * cap would then bound the response size but not the work, and the work is
	 * the expensive half (a post lookup and a full WooCommerce price render
	 * each).
	 *
	 * @return void
	 */
	public function test_a_huge_batch_of_distinct_ids_is_refused_outright(): void {
		$product = $this->create_simple_product( 100.0 );

		$ids = range( $product->get_id() + 1, $product->get_id() + 5000 );

		$response = $this->convert(
			array(
				'currency'    => self::TARGET_CURRENCY,
				'product_ids' => $ids,
			)
		);

		$this->assertSame( 400, $response->get_status(), '5000 IDs must cost one validation failure, not 5000 lookups.' );
	}

	/**
	 * Exactly 50 is accepted: the boundary is inclusive, and the client chunks
	 * to exactly this size (spec §5.1).
	 *
	 * @return void
	 */
	public function test_accepts_exactly_fifty_ids(): void {
		$product = $this->create_simple_product( 100.0 );

		$ids    = array_fill( 0, self::BATCH_LIMIT - 1, $product->get_id() + 90000 );
		$ids[]  = $product->get_id();

		$response = $this->convert(
			array(
				'currency'    => self::TARGET_CURRENCY,
				'product_ids' => $ids,
			)
		);

		$this->assertSame( 200, $response->get_status(), 'An off-by-one here would break every full chunk the client sends.' );
		$this->assertArrayHasKey( $product->get_id(), $response->get_data()['prices'] );
	}

	/**
	 * A missing product_ids parameter is a 400, not an empty success.
	 *
	 * @return void
	 */
	public function test_missing_product_ids_is_rejected(): void {
		$response = $this->convert( array( 'currency' => self::TARGET_CURRENCY ) );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * product_ids must be an array. A scalar is a 400, not something to be
	 * coerced.
	 *
	 * @return void
	 */
	public function test_non_array_product_ids_is_rejected(): void {
		$response = $this->convert(
			array(
				'currency'    => self::TARGET_CURRENCY,
				'product_ids' => '42',
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Repeated IDs collapse to one entry, so a caller cannot inflate the work
	 * inside a legal batch either.
	 *
	 * @return void
	 */
	public function test_duplicate_ids_are_collapsed(): void {
		$product = $this->create_simple_product( 100.0 );

		$data = $this->convert(
			array(
				'currency'    => self::TARGET_CURRENCY,
				'product_ids' => array_fill( 0, 40, $product->get_id() ),
			)
		)->get_data();

		$this->assertCount( 1, $data['prices'] );
	}

	/**
	 * Junk inside the array is dropped rather than fatal.
	 *
	 * @return void
	 */
	public function test_non_numeric_entries_are_dropped(): void {
		$product = $this->create_simple_product( 100.0 );

		$response = $this->convert(
			array(
				'currency'    => self::TARGET_CURRENCY,
				'product_ids' => array( $product->get_id(), 'abc', '', null, -5, 0, array( 1 ) ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $product->get_id() ), array_keys( $response->get_data()['prices'] ) );
	}

	// ─── Visibility matrix (spec §6, §9C) ────────────────────────────

	/**
	 * 🔴 A draft product is skipped — silently.
	 *
	 * Silence is the point. An error naming the ID would confirm that a post
	 * exists and is not public, which is more than the anonymous caller could
	 * learn from the site itself.
	 *
	 * Every test in this section sends a PUBLISHED product alongside the
	 * hidden one, and asserts that the published one came back. Without that
	 * control "the hidden ID is absent" would also be satisfied by an endpoint
	 * that returned nothing at all.
	 *
	 * @return void
	 */
	public function test_draft_products_are_skipped(): void {
		$this->assertHiddenBySettingPostFields( array( 'post_status' => 'draft' ) );
	}

	/**
	 * A private product is skipped, even though an administrator could see it.
	 * The endpoint answers anonymous cache traffic and has no user to check.
	 *
	 * @return void
	 */
	public function test_private_products_are_skipped(): void {
		$this->assertHiddenBySettingPostFields( array( 'post_status' => 'private' ) );
	}

	/**
	 * A pending product is skipped.
	 *
	 * @return void
	 */
	public function test_pending_products_are_skipped(): void {
		$this->assertHiddenBySettingPostFields( array( 'post_status' => 'pending' ) );
	}

	/**
	 * A password-protected product is skipped for a caller without the
	 * password. Its price is exactly what the password is hiding.
	 *
	 * @return void
	 */
	public function test_password_protected_products_are_skipped(): void {
		$this->assertHiddenBySettingPostFields( array( 'post_password' => 'mhmcs-secret' ) );
	}

	/**
	 * A trashed product is skipped.
	 *
	 * @return void
	 */
	public function test_trashed_products_are_skipped(): void {
		$visible = $this->create_simple_product( 100.0 );
		$hidden  = $this->create_simple_product( 77.0 );

		wp_trash_post( $hidden->get_id() );

		$prices = $this->convert_ids( array( $visible->get_id(), $hidden->get_id() ) );

		$this->assertArrayHasKey( $visible->get_id(), $prices, 'Control: the published product must come back, or "absent" proves nothing.' );
		$this->assertArrayNotHasKey( $hidden->get_id(), $prices );
	}

	/**
	 * A post that is not a product is skipped, whatever its status.
	 *
	 * ⚠️ This asserts the BEHAVIOUR, not the controller's post-type allowlist.
	 * Mutation testing showed the property survives that allowlist being
	 * deleted, because wc_get_product() independently refuses any other post
	 * type — the endpoint enforces this twice. The test is kept because the
	 * behaviour is what matters and either mechanism could change, but it must
	 * not be read as proof that the allowlist is what stops a caller pricing an
	 * arbitrary post ID.
	 *
	 * @return void
	 */
	public function test_non_product_post_types_are_skipped(): void {
		$visible = $this->create_simple_product( 100.0 );
		$page    = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$post    = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );

		$prices = $this->convert_ids( array( $visible->get_id(), $page, $post ) );

		$this->assertArrayHasKey( $visible->get_id(), $prices, 'Control.' );
		$this->assertArrayNotHasKey( $page, $prices );
		$this->assertArrayNotHasKey( $post, $prices );
	}

	/**
	 * An ID that matches no post at all is skipped without an error.
	 *
	 * @return void
	 */
	public function test_unknown_ids_are_skipped(): void {
		$visible = $this->create_simple_product( 100.0 );

		$prices = $this->convert_ids( array( $visible->get_id(), $visible->get_id() + 999000 ) );

		$this->assertArrayHasKey( $visible->get_id(), $prices, 'Control.' );
		$this->assertCount( 1, $prices );
	}

	// ─── Variations (spec §6; round 2 / H4 and round 3 / M-1) ────────

	/**
	 * A `product_variation` ID is accepted and priced as itself.
	 *
	 * The marker carries variation IDs (Task 5), so refusing the post type
	 * here would leave every variable product's price permanently in the base
	 * currency.
	 *
	 * @return void
	 */
	public function test_accepts_a_product_variation(): void {
		$built     = $this->create_variable_product( array( 15.0, 25.0 ) );
		$variation = $built['variations'][0];

		$prices = $this->convert_ids( array( $variation->get_id() ) );

		$this->assertArrayHasKey( $variation->get_id(), $prices );
		$this->assertStringContainsString( '30.00', $prices[ $variation->get_id() ], '15 * 2.0 — the variation is priced as itself, not as its parent\'s range.' );
	}

	/**
	 * 🔴 Round 3 / M-1: a variation whose OWN status is `private` is refused,
	 * even though its parent is published.
	 *
	 * WooCommerce sets exactly this status on a variation the shop owner
	 * disabled. Checking only the parent — the obvious implementation — would
	 * publish the price of every disabled variation in the shop.
	 *
	 * @return void
	 */
	public function test_rejects_a_private_variation_of_a_published_parent(): void {
		$built   = $this->create_variable_product( array( 15.0, 25.0 ) );
		$enabled = $built['variations'][0];
		$hidden  = $built['variations'][1];

		wp_update_post(
			array(
				'ID'          => $hidden->get_id(),
				'post_status' => 'private',
			)
		);

		$this->assertSame( 'publish', get_post_status( $built['product']->get_id() ), 'Guard: the PARENT must stay published, or this test is not about the variation\'s own status.' );

		$prices = $this->convert_ids( array( $enabled->get_id(), $hidden->get_id() ) );

		$this->assertArrayHasKey( $enabled->get_id(), $prices, 'Control: its published sibling must still be priced.' );
		$this->assertArrayNotHasKey(
			$hidden->get_id(),
			$prices,
			'A disabled variation carries status `private`; checking only the parent would publish its price.'
		);
	}

	/**
	 * A variation of a DRAFT parent is refused, even when the variation itself
	 * says `publish` — which is what WooCommerce leaves behind when a whole
	 * variable product is unpublished.
	 *
	 * @return void
	 */
	public function test_rejects_a_variation_whose_parent_is_not_published(): void {
		$visible   = $this->create_simple_product( 100.0 );
		$built     = $this->create_variable_product( array( 15.0 ) );
		$variation = $built['variations'][0];

		wp_update_post(
			array(
				'ID'          => $built['product']->get_id(),
				'post_status' => 'draft',
			)
		);

		$this->assertSame( 'publish', get_post_status( $variation->get_id() ), 'Guard: the variation itself is still published, so only the parent check can refuse it.' );

		$prices = $this->convert_ids( array( $visible->get_id(), $variation->get_id() ) );

		$this->assertArrayHasKey( $visible->get_id(), $prices, 'Control.' );
		$this->assertArrayNotHasKey( $variation->get_id(), $prices );
	}

	/**
	 * A variation of a password-protected parent is refused.
	 *
	 * @return void
	 */
	public function test_rejects_a_variation_whose_parent_is_password_protected(): void {
		$visible   = $this->create_simple_product( 100.0 );
		$built     = $this->create_variable_product( array( 15.0 ) );
		$variation = $built['variations'][0];

		wp_update_post(
			array(
				'ID'            => $built['product']->get_id(),
				'post_password' => 'mhmcs-secret',
			)
		);

		$prices = $this->convert_ids( array( $visible->get_id(), $variation->get_id() ) );

		$this->assertArrayHasKey( $visible->get_id(), $prices, 'Control.' );
		$this->assertArrayNotHasKey( $variation->get_id(), $prices );
	}

	// ─── Currency resolution (spec §6, §5.4) ─────────────────────────

	/**
	 * 🔴 A currency the shop does not offer resolves to the base currency —
	 * it is not an error.
	 *
	 * An error would answer "that currency is not enabled here", turning the
	 * endpoint into a probe for the shop's configuration. Falling back to base
	 * answers the same thing for every code the shop does not offer.
	 *
	 * @return void
	 */
	public function test_unknown_currency_falls_back_to_base(): void {
		$product = $this->create_simple_product( 100.0 );

		$response = $this->convert(
			array(
				'currency'    => 'ZWL',
				'product_ids' => array( $product->get_id() ),
			)
		);

		$this->assertSame( 200, $response->get_status(), 'A currency the shop does not offer must not be distinguishable from one it does by status code.' );

		$data = $response->get_data();

		$this->assertSame( 'USD', $data['currency'] );
		$this->assertStringContainsString( '100.00', $data['prices'][ $product->get_id() ], 'The base amount, unconverted.' );
		$this->assertStringNotContainsString( self::TARGET_SYMBOL, $data['prices'][ $product->get_id() ] );
	}

	/**
	 * A malformed currency also resolves to base, and is NOT treated as "no
	 * currency supplied" — which would silently start a geolocation lookup the
	 * caller never asked for.
	 *
	 * @return void
	 */
	public function test_malformed_currency_falls_back_to_base_without_detecting(): void {
		$this->enable_geolocation_to( 'DE' );

		$product = $this->create_simple_product( 100.0 );

		$data = $this->convert(
			array(
				'currency'    => '€€€€',
				'product_ids' => array( $product->get_id() ),
			)
		)->get_data();

		$this->assertSame( 'USD', $data['currency'] );
		$this->assertFalse( $data['detected'], 'A caller who sent SOMETHING is not asking to be detected; only an explicit null is.' );
	}

	/**
	 * A currency that is only a different case is still honoured — the shared
	 * sanitiser upper-cases before validating.
	 *
	 * @return void
	 */
	public function test_lowercase_currency_is_normalised(): void {
		$product = $this->create_simple_product( 100.0 );

		$data = $this->convert(
			array(
				'currency'    => strtolower( self::TARGET_CURRENCY ),
				'product_ids' => array( $product->get_id() ),
			)
		)->get_data();

		$this->assertSame( self::TARGET_CURRENCY, $data['currency'] );
		$this->assertStringContainsString( '200.00', $data['prices'][ $product->get_id() ] );
	}

	/**
	 * An explicit currency is never reported as "detected": the client already
	 * knows what it asked for, and `detected` is what tells it to persist a
	 * cookie (spec §5.4).
	 *
	 * @return void
	 */
	public function test_explicit_currency_is_not_reported_as_detected(): void {
		$product = $this->create_simple_product( 100.0 );

		$data = $this->convert(
			array(
				'currency'    => self::TARGET_CURRENCY,
				'product_ids' => array( $product->get_id() ),
			)
		)->get_data();

		$this->assertFalse( $data['detected'] );
	}

	/**
	 * `currency: null` asks the server to detect, and a successful
	 * geolocation is reported back with `detected: true`.
	 *
	 * @return void
	 */
	public function test_null_currency_resolves_via_geolocation(): void {
		$this->enable_geolocation_to( 'DE' );

		$product = $this->create_simple_product( 100.0 );

		$data = $this->convert(
			array(
				'currency'    => null,
				'product_ids' => array( $product->get_id() ),
			)
		)->get_data();

		$this->assertSame( self::TARGET_CURRENCY, $data['currency'], 'Germany maps to EUR, which this shop offers.' );
		$this->assertTrue( $data['detected'], 'The client persists its cookie on this flag.' );
		$this->assertStringContainsString( '200.00', $data['prices'][ $product->get_id() ] );
	}

	/**
	 * An omitted currency parameter means the same as an explicit null.
	 *
	 * @return void
	 */
	public function test_omitted_currency_also_asks_for_detection(): void {
		$this->enable_geolocation_to( 'DE' );

		$product = $this->create_simple_product( 100.0 );

		$data = $this->convert( array( 'product_ids' => array( $product->get_id() ) ) )->get_data();

		$this->assertSame( self::TARGET_CURRENCY, $data['currency'] );
		$this->assertTrue( $data['detected'] );
	}

	/**
	 * 🔴 Round 2 / H2: geolocation that cannot resolve anything returns the
	 * BASE currency with `detected: false`.
	 *
	 * The flag is what stops the client writing a cookie. If a failure were
	 * reported as a success the client would pin the base currency, the
	 * detection chain would stop at step 1 on every later page, and
	 * geolocation would never be retried for that visitor.
	 *
	 * @return void
	 */
	public function test_failed_geolocation_returns_detected_false(): void {
		$this->enable_geolocation_with_no_signal();

		$product = $this->create_simple_product( 100.0 );

		$data = $this->convert(
			array(
				'currency'    => null,
				'product_ids' => array( $product->get_id() ),
			)
		)->get_data();

		$this->assertSame( 'USD', $data['currency'] );
		$this->assertFalse( $data['detected'] );
		$this->assertStringContainsString( '100.00', $data['prices'][ $product->get_id() ], 'A failed detection shows the base price, not a guess.' );
	}

	/**
	 * A visitor's cookie is honoured on the detection path, in the same
	 * cookie-first order the server uses everywhere else (spec §5.2).
	 *
	 * @return void
	 */
	public function test_detection_honours_the_visitor_cookie_first(): void {
		$this->enable_geolocation_to( 'GB' );
		$this->set_visitor_currency( self::TARGET_CURRENCY );

		$product = $this->create_simple_product( 100.0 );

		$data = $this->convert(
			array(
				'currency'    => null,
				'product_ids' => array( $product->get_id() ),
			)
		)->get_data();

		$this->assertSame(
			self::TARGET_CURRENCY,
			$data['currency'],
			'The cookie outranks geolocation here exactly as it does on a page render; disagreeing would show one currency and charge another.'
		);
	}

	/**
	 * 🔴 The endpoint must leave no server-side currency cookie behind
	 * (spec §4, round 4 / L-1; §5.3 gives the cookie to the client).
	 *
	 * ⚠️ This assertion is WEAK here and is not the proof. The integration
	 * suite runs on PHP's real setcookie(), and under the CLI SAPI a PHPUnit
	 * process has already produced output, so DetectionService::set_currency()
	 * would decline the write on the headers_sent() check regardless of
	 * anything this task changed — the assertion below cannot fail. The
	 * counting proof lives in the unit suite, where setcookie() is stubbed and
	 * every call is recorded:
	 * DetectionServiceTest::test_geolocation_writes_no_cookie_while_persistence_is_off
	 * asserts 1 geolocation lookup and 0 cookie writes, against a control that
	 * asserts exactly 1 write with the switch left alone. This test is kept as
	 * the end-to-end shape check only.
	 *
	 * @return void
	 */
	public function test_detection_path_leaves_no_currency_cookie(): void {
		$this->enable_geolocation_to( 'DE' );
		$this->clear_visitor_currency();

		$product = $this->create_simple_product( 100.0 );

		$data = $this->convert(
			array(
				'currency'    => null,
				'product_ids' => array( $product->get_id() ),
			)
		)->get_data();

		$this->assertTrue( $data['detected'], 'Guard: geolocation must genuinely have succeeded, or "no cookie" is vacuous.' );
		$this->assertArrayNotHasKey(
			'mhmcs_currency',
			$_COOKIE,
			'In cache mode the client owns this cookie (spec §5.3).'
		);
	}

	// ─── Request isolation ───────────────────────────────────────────

	/**
	 * 🔴 The endpoint must not leak its forced currency into the rest of the
	 * request.
	 *
	 * The realistic failure is a page render that dispatches this endpoint
	 * through rest_do_request(): if the request override or the conversion
	 * latch survived, every price printed after it would be converted, in the
	 * REST caller's currency, with no marker — and that page then goes into
	 * the cache.
	 *
	 * @return void
	 */
	public function test_endpoint_leaves_no_currency_override_behind(): void {
		$product = $this->create_simple_product( 100.0 );

		$response = $this->convert(
			array(
				'currency'    => self::TARGET_CURRENCY,
				'product_ids' => array( $product->get_id() ),
			)
		);

		/*
		 * 🔴 Guard, and it was earned. Written without it, this was the ONE
		 * test in this file that passed against a repository where the route
		 * did not exist yet: a 404 sets no override, so "no override left
		 * behind" was trivially true. It now has to survive a request that
		 * genuinely set one.
		 */
		$this->assertSame( 200, $response->get_status(), 'Guard: the endpoint must actually have run and pinned a currency.' );
		$this->assertSame( self::TARGET_CURRENCY, $response->get_data()['currency'] );

		$detection = $this->shared_detection_service();

		$this->assertNotNull( $detection, 'Guard: the shared service must be reachable, or this test asserts nothing.' );
		$this->assertSame(
			'USD',
			$detection->get_current_currency(),
			'With no visitor cookie the shared service must be back on the base currency; the endpoint\'s override must not outlive its own response.'
		);
	}

	// ─── Helpers ─────────────────────────────────────────────────────

	/**
	 * Dispatch a POST to the endpoint with a JSON body.
	 *
	 * @param array<string, mixed> $body Request payload.
	 * @return WP_REST_Response
	 */
	private function convert( array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_do_request( $request );
	}

	/**
	 * Convert a list of IDs in TARGET_CURRENCY and return just the price map.
	 *
	 * @param array<int, int> $ids Product or variation IDs.
	 * @return array<int, string> Price HTML keyed by ID.
	 */
	private function convert_ids( array $ids ): array {
		$response = $this->convert(
			array(
				'currency'    => self::TARGET_CURRENCY,
				'product_ids' => $ids,
			)
		);

		$this->assertSame( 200, $response->get_status(), 'An invisible product must be SKIPPED, never turned into an error that confirms it exists.' );

		return $response->get_data()['prices'];
	}

	/**
	 * Assert that a product hidden by the given post fields is skipped, while
	 * a published sibling in the same request is still priced.
	 *
	 * @param array<string, mixed> $fields Post fields to apply to the hidden product.
	 * @return void
	 */
	private function assertHiddenBySettingPostFields( array $fields ): void {
		$visible = $this->create_simple_product( 100.0 );
		$hidden  = $this->create_simple_product( 77.0 );

		wp_update_post( array_merge( array( 'ID' => $hidden->get_id() ), $fields ) );

		$prices = $this->convert_ids( array( $visible->get_id(), $hidden->get_id() ) );

		$this->assertArrayHasKey(
			$visible->get_id(),
			$prices,
			'Control: the published product must come back, otherwise "the hidden one is absent" is also true of an endpoint that returns nothing.'
		);
		$this->assertArrayNotHasKey(
			$hidden->get_id(),
			$prices,
			'A product the anonymous caller cannot see on the site must not be priced for them here either.'
		);
		$this->assertStringNotContainsString( '154.00', (string) wp_json_encode( $prices ), 'Not even the converted amount (77 * 2.0) may appear anywhere in the body.' );
	}
}
