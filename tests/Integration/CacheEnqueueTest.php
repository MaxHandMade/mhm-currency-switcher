<?php
/**
 * Integration tests: the front-end asset loader and the configuration object
 * it hands to the client-side converter (design spec §4 and §5, implementation
 * plan Task 7).
 *
 * Two contracts are locked here and neither is visible by reading either side
 * alone:
 *
 * - WHERE the converter script loads. It must load exactly where
 *   PriceDisplayMarker emitted markers and nowhere else. A page with markers
 *   and no script shows every visitor the base currency; a script on a page
 *   the server already converted is the double-conversion failure with an
 *   extra HTTP request attached.
 * - WHAT the configuration object contains, and in what TYPES. wp_localize_script()
 *   casts top-level scalars to strings, so a boolean written at the top level
 *   reaches JavaScript as "1" or "" and a number as a string. The payload is
 *   nested one level down to avoid that, and this file is what notices if it
 *   ever stops being.
 *
 * 🔴 FILE NAME IS LOAD-BEARING, for the same reason spelled out at the top of
 * CachePriceMarkerTest: ConversionContextWiringTest defines WOOCOMMERCE_CART,
 * which no PHP process can undefine, so every file sorting after it runs in a
 * permanent money context where the converter is never enqueued. "CacheEnqueue"
 * sorts ahead of both "CachePriceMarker" and "ConversionContextWiring".
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use MhmCurrencySwitcher\Frontend\Enqueue;
use MhmCurrencySwitcher\Frontend\PriceDisplayMarker;
use MhmCurrencySwitcher\Rest\ConvertController;
use WP_Scripts;

/**
 * Class CacheEnqueueTest
 */
class CacheEnqueueTest extends MhmcsIntegrationTestCase {

	/**
	 * Plugin settings as found before the test, restored in tear_down().
	 *
	 * @var array<string, mixed>
	 */
	private $saved_settings = array();

	/**
	 * Start every test as a logged-out visitor part-way through rendering a
	 * front-end page.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$settings             = get_option( 'mhmcs_settings', array() );
		$this->saved_settings = is_array( $settings ) ? $settings : array();

		wp_set_current_user( 0 );
		$this->leave_money_context();
		$this->enter_render_phase();
	}

	/**
	 * Leave no request phase, query parameter, user, setting or script
	 * registry behind.
	 *
	 * @return void
	 */
	public function tear_down() {
		update_option( 'mhmcs_settings', $this->saved_settings );

		wp_set_current_user( 0 );
		$this->leave_money_context();
		$this->enter_pre_wp_phase();

		$GLOBALS['wp_scripts'] = null;

		parent::tear_down();
	}

	// ─── Where the converter loads ───────────────────────────────────

	/**
	 * A cacheable catalogue render loads the converter.
	 *
	 * This is the case the whole feature exists for: the page went out in the
	 * base currency with markers in it, so the script that corrects them has to
	 * be there.
	 *
	 * @return void
	 */
	public function test_display_context_enqueues_the_converter(): void {
		$this->assertMoneyConstantsUndefined();

		$this->run_enqueue();

		$this->assertTrue(
			wp_script_is( Enqueue::CONVERTER_HANDLE, 'enqueued' ),
			'A display-context page carries markers, so the script that converts them must be enqueued.'
		);
	}

	/**
	 * The converter is loaded after, and depends on, the switcher script.
	 *
	 * The configuration object is attached to the switcher handle, and
	 * wp_localize_script() prints it with THAT script. Without the dependency
	 * WordPress is free to order them the other way round and the converter
	 * would read an undefined object and silently do nothing.
	 *
	 * @return void
	 */
	public function test_converter_depends_on_the_script_carrying_its_config(): void {
		$this->assertMoneyConstantsUndefined();

		$scripts = $this->run_enqueue();
		$handle  = $scripts->registered[ Enqueue::CONVERTER_HANDLE ] ?? null;

		$this->assertNotNull( $handle, 'The converter must be registered before its dependencies can be asserted.' );
		$this->assertContains(
			Enqueue::SWITCHER_HANDLE,
			$handle->deps,
			'The config object is printed with the switcher script; without the dependency the converter can run before it exists.'
		);
	}

	/**
	 * A money context loads no converter.
	 *
	 * The server converted those prices itself and emitted no marker, so the
	 * script would have nothing to do — and an extra script that reads the
	 * page looking for prices to rewrite is not a harmless no-op on a cart.
	 *
	 * @return void
	 */
	public function test_money_context_does_not_enqueue_the_converter(): void {
		$this->enter_money_context();

		$this->run_enqueue();

		$this->assertFalse(
			wp_script_is( Enqueue::CONVERTER_HANDLE, 'enqueued' ),
			'A money context is converted server-side and carries no marker; loading the converter there invites a second conversion.'
		);
	}

	/**
	 * A logged-in visitor gets no converter.
	 *
	 * @return void
	 */
	public function test_logged_in_visitor_does_not_get_the_converter(): void {
		$this->assertMoneyConstantsUndefined();

		wp_set_current_user( self::$admin_id );

		$this->run_enqueue();

		$this->assertFalse(
			wp_script_is( Enqueue::CONVERTER_HANDLE, 'enqueued' ),
			'Decision 6 converts for logged-in visitors server-side and emits no marker (CachePriceMarkerTest locks that); the script must follow.'
		);
	}

	/**
	 * With cache compatibility off there is no client-side path at all.
	 *
	 * @return void
	 */
	public function test_cache_compat_disabled_does_not_enqueue_the_converter(): void {
		$this->assertMoneyConstantsUndefined();

		$settings                 = $this->saved_settings;
		$settings['cache_compat'] = false;
		update_option( 'mhmcs_settings', $settings );

		$this->run_enqueue();

		$this->assertFalse(
			wp_script_is( Enqueue::CONVERTER_HANDLE, 'enqueued' ),
			'The toggle is the site owner opting out of the whole client-side path: no marker, no REST call, no script.'
		);
	}

	/**
	 * The switcher script and its configuration load everywhere, including the
	 * contexts the converter stays out of.
	 *
	 * The switcher itself still has to work on a cart page, and it needs the
	 * cookie contract and the currency list to do it.
	 *
	 * @return void
	 */
	public function test_switcher_config_is_present_even_where_the_converter_is_not(): void {
		$this->enter_money_context();

		$this->run_enqueue();
		$config = $this->localized_config();

		$this->assertIsArray( $config, 'The switcher configuration must be localized in every front-end context.' );
	}

	// ─── What the configuration contains ─────────────────────────────

	/**
	 * 🔴 Booleans and numbers must survive localization as booleans and
	 * numbers.
	 *
	 * wp_localize_script() html_entity_decodes and CASTS every top-level scalar
	 * to a string for backwards compatibility: `true` becomes "1", `false`
	 * becomes "", and 50 becomes "50". The payload is therefore nested under
	 * `config`, where wp_json_encode() leaves the types alone. A regression
	 * here is silent on the PHP side and produces a client that reads `""` as a
	 * setting value.
	 *
	 * @return void
	 */
	public function test_config_values_keep_their_json_types(): void {
		$settings                 = $this->saved_settings;
		$settings['cache_compat'] = true;
		$settings['auto_detect']  = false;
		update_option( 'mhmcs_settings', $settings );

		$this->run_enqueue();
		$config = $this->localized_config();

		$this->assertIsBool( $config['cacheCompat'], 'cacheCompat reached JavaScript as a string; the payload is no longer nested.' );
		$this->assertIsBool( $config['autoDetect'], 'autoDetect reached JavaScript as a string; the payload is no longer nested.' );
		$this->assertIsInt( $config['batchSize'], 'batchSize reached JavaScript as a string; Math.max/parseInt would still cope, but the contract is a number.' );

		$this->assertTrue( $config['cacheCompat'] );
		$this->assertFalse( $config['autoDetect'] );
	}

	/**
	 * `clientConversion` must report whether price-converter.js is actually
	 * on this page — the same answer, from the same reading of the same
	 * decision, not a second derivation of it.
	 *
	 * switcher.js branches on this value: true means "announce
	 * mhmcs:currency-changed and let the converter rewrite the prices", false
	 * means "reload, because nothing here is listening". So the two have to
	 * agree exactly, and this asserts them against each other rather than
	 * against a hard-coded expectation — a test written the other way would
	 * still pass if both sides moved together in the wrong direction.
	 *
	 * @return void
	 */
	public function test_client_conversion_flag_tracks_the_converter_script(): void {
		$this->assertMoneyConstantsUndefined();

		$this->run_enqueue();

		$this->assertTrue(
			$this->localized_config()['clientConversion'],
			'A cacheable display render loads the converter, so the client owns the change.'
		);
		$this->assertTrue( wp_script_is( Enqueue::CONVERTER_HANDLE, 'enqueued' ) );

		$this->enter_money_context();
		$this->run_enqueue();

		$this->assertFalse(
			$this->localized_config()['clientConversion'],
			'A money context is converted server-side and carries no converter, so switcher.js must reload instead of firing an event nobody hears.'
		);
		$this->assertFalse( wp_script_is( Enqueue::CONVERTER_HANDLE, 'enqueued' ) );
	}

	/**
	 * `clientConversion` is NOT a rename of `cacheCompat`.
	 *
	 * Cache compatibility can be on while the client converts nothing: a
	 * logged-in visitor takes the server-side path and gets no markers and no
	 * converter. Reading the setting instead of the decision would tell
	 * switcher.js to fire an event on that page and the switcher would appear
	 * to do nothing at all.
	 *
	 * @return void
	 */
	public function test_client_conversion_is_not_the_cache_compat_setting(): void {
		$settings                 = $this->saved_settings;
		$settings['cache_compat'] = true;
		update_option( 'mhmcs_settings', $settings );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );

		$this->run_enqueue();
		$config = $this->localized_config();

		$this->assertTrue( $config['cacheCompat'], 'Precondition: the mode is on.' );
		$this->assertFalse(
			$config['clientConversion'],
			'A logged-in visitor is converted server-side even with cache compatibility on.'
		);
	}

	/**
	 * The flag must survive localization as a real boolean, for the same
	 * reason every other nested value must.
	 *
	 * @return void
	 */
	public function test_client_conversion_keeps_its_json_type(): void {
		$this->run_enqueue();

		$this->assertIsBool(
			$this->localized_config()['clientConversion'],
			'clientConversion reached JavaScript as a string; `"" && …` is falsy, so a switcher on a cacheable page would silently start reloading again.'
		);
	}

	/**
	 * `auto_detect` is passed through, not inverted or renamed.
	 *
	 * The client sends `currency: null` — "server, geolocate this visitor" —
	 * only when this is on. This plugin has shipped two production bugs from a
	 * settings key that one side wrote and the other never read, so the key is
	 * asserted against a real saved setting rather than against a default.
	 *
	 * @return void
	 */
	public function test_auto_detect_setting_reaches_the_client(): void {
		$settings                = $this->saved_settings;
		$settings['auto_detect'] = true;
		update_option( 'mhmcs_settings', $settings );

		$this->run_enqueue();

		$this->assertTrue(
			$this->localized_config()['autoDetect'],
			'With auto_detect saved as on, the client must be told so — otherwise it never asks the server to geolocate.'
		);
	}

	/**
	 * Cache compatibility defaults to ON for a site that has never saved it.
	 *
	 * @return void
	 */
	public function test_cache_compat_defaults_to_enabled_when_never_saved(): void {
		$settings = $this->saved_settings;
		unset( $settings['cache_compat'] );
		update_option( 'mhmcs_settings', $settings );

		$this->run_enqueue();

		$this->assertTrue(
			$this->localized_config()['cacheCompat'],
			'A site upgraded from v1.0.0 has no cache_compat key; the client must read the same default ConversionContext does.'
		);
	}

	/**
	 * 🔴 The client's batch size is the server's cap, and the marker strings
	 * are the marker's own.
	 *
	 * The endpoint answers a batch over its cap with a 400, so a client that
	 * chunked at a different number would fail whole pages rather than degrade;
	 * and a selector built from a class the marker no longer emits matches
	 * nothing at all, silently.
	 *
	 * @return void
	 */
	public function test_client_contract_values_come_from_the_server_constants(): void {
		$this->run_enqueue();
		$config = $this->localized_config();

		$this->assertSame( ConvertController::MAX_PRODUCT_IDS, $config['batchSize'] );
		$this->assertSame( PriceDisplayMarker::CSS_CLASS, $config['markerClass'] );
		$this->assertSame( PriceDisplayMarker::ID_ATTRIBUTE, $config['idAttribute'] );

		/*
		 * Also asserted as literals. The three constants above could all be
		 * renamed together and stay self-consistent while the marker in a
		 * cached page — and every cache plugin that rewrites HTML — kept the
		 * old strings.
		 */
		$this->assertSame( 50, $config['batchSize'] );
		$this->assertSame( 'mhmcs-price', $config['markerClass'] );
		$this->assertSame( 'data-mhmcs-product', $config['idAttribute'] );
	}

	/**
	 * The cookie contract handed to the client is the one PHP reads.
	 *
	 * The client writes this cookie and PHP reads it on the next request; a
	 * different name, or a different lifetime, means the visitor's choice is
	 * either invisible to the server or expires on a different day than the
	 * settings claim.
	 *
	 * @return void
	 */
	public function test_cookie_contract_matches_the_detection_service(): void {
		$this->run_enqueue();
		$config = $this->localized_config();

		$this->assertSame( 'mhmcs_currency', $config['cookieName'] );
		$this->assertSame( 30, $config['cookieDays'] );
		$this->assertSame( 'currency', $config['urlParam'] );
	}

	/**
	 * The currency map is the base currency plus the enabled ones — the same
	 * set DetectionService::validate_code() accepts.
	 *
	 * The client validates a cookie and a `?currency=` value against these keys
	 * before sending anything, so if the two sets diverged the client would
	 * honour a code the server refuses, or refuse one the server honours. Both
	 * directions end with the catalogue and the cart in different currencies.
	 *
	 * @return void
	 */
	public function test_currency_map_is_the_allowlist_the_server_validates_against(): void {
		$this->run_enqueue();
		$currencies = $this->localized_config()['currencies'];

		$this->assertArrayHasKey( 'USD', $currencies, 'The base currency is always accepted by validate_code() and must be offered to the client.' );
		$this->assertArrayHasKey( self::TARGET_CURRENCY, $currencies, 'An enabled currency is accepted by validate_code() and must be offered to the client.' );
		$this->assertArrayNotHasKey( 'XXX', $currencies );

		$this->assertSame(
			self::TARGET_SYMBOL,
			$currencies[ self::TARGET_CURRENCY ]['symbol'],
			'The symbol comes from the administrator saved format, exactly as the switcher renders it.'
		);
		$this->assertArrayHasKey( 'flag', $currencies[ self::TARGET_CURRENCY ] );
	}

	/**
	 * The endpoint URL points at the route the controller actually registered.
	 *
	 * @return void
	 */
	public function test_rest_url_resolves_to_the_registered_route(): void {
		$this->run_enqueue();

		$this->assertSame(
			rest_url( 'mhmcs/v1/convert' ),
			$this->localized_config()['restUrl'],
			'A URL the controller does not serve turns every conversion into a 404 the visitor never sees.'
		);
	}

	// ─── Helpers ─────────────────────────────────────────────────────

	/**
	 * Run the front-end enqueue cycle against a fresh script registry.
	 *
	 * The registry is rebuilt rather than reused: WP_Scripts is a global that
	 * survives a test, so a handle enqueued by an earlier test would make a
	 * later "is it enqueued?" assertion pass without the code under test ever
	 * running.
	 *
	 * @return WP_Scripts The registry after the cycle.
	 */
	private function run_enqueue(): WP_Scripts {
		$GLOBALS['wp_scripts'] = null;

		do_action( 'wp_enqueue_scripts' );

		return wp_scripts();
	}

	/**
	 * Decode the configuration object as JavaScript will receive it.
	 *
	 * Read back out of WP_Scripts and JSON-decoded rather than asserted against
	 * the array the class built, so the assertions see what
	 * wp_localize_script() actually emitted — which is the whole point of the
	 * type tests above.
	 *
	 * @return array<string, mixed>|null The `config` object, or null.
	 */
	private function localized_config(): ?array {
		$data = wp_scripts()->get_data( Enqueue::SWITCHER_HANDLE, 'data' );

		if ( ! is_string( $data ) || '' === $data ) {
			return null;
		}

		$start = strpos( $data, '{' );

		if ( false === $start ) {
			return null;
		}

		$decoded = json_decode( rtrim( trim( substr( $data, $start ) ), ';' ), true );

		return is_array( $decoded ) && isset( $decoded['config'] ) && is_array( $decoded['config'] )
			? $decoded['config']
			: null;
	}

	/**
	 * Mark the request as being past the `wp` action.
	 *
	 * @return void
	 */
	private function enter_render_phase(): void {
		$GLOBALS['wp_actions']['wp'] = 1;
	}

	/**
	 * Return the request to the phase before the `wp` action.
	 *
	 * @return void
	 */
	private function enter_pre_wp_phase(): void {
		unset( $GLOBALS['wp_actions']['wp'] );
	}

	/**
	 * Put the request into a WooCommerce money context, reversibly.
	 *
	 * @return void
	 */
	private function enter_money_context(): void {
		$_GET['wc-ajax'] = 'get_refreshed_fragments';
	}

	/**
	 * Leave the WooCommerce money context.
	 *
	 * @return void
	 */
	private function leave_money_context(): void {
		unset( $_GET['wc-ajax'] );
	}

	/**
	 * Assert that no earlier test has defined WooCommerce's cart/checkout
	 * constants, which cannot be undefined.
	 *
	 * @return void
	 */
	private function assertMoneyConstantsUndefined(): void {
		$this->assertFalse(
			defined( 'WOOCOMMERCE_CART' ) || defined( 'WOOCOMMERCE_CHECKOUT' ),
			'This test asserts display-context behaviour, so it must run before ConversionContextWiringTest defines WOOCOMMERCE_CART for the rest of the process. PHPUnit sorts the files it collects; check that this file name still sorts ahead of ConversionContextWiringTest.php.'
		);
	}
}
