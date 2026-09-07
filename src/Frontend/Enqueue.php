<?php
/**
 * Frontend asset enqueue — registers CSS and JS for the switcher.
 *
 * Conditionally enqueues the switcher stylesheet and script on the
 * frontend when WooCommerce is active.
 *
 * @package MhmCurrencySwitcher\Frontend
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Frontend;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MhmCurrencySwitcher\Core\ConversionContext;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;
use MhmCurrencySwitcher\Rest\ConvertController;

/**
 * Enqueue — frontend CSS and JS asset loader.
 *
 * Hooks into `wp_enqueue_scripts` to register and enqueue the
 * currency switcher stylesheet and JavaScript on the public site.
 *
 * @since 0.3.0
 */
final class Enqueue {

	/**
	 * Handle of the switcher script, which is also the handle the shared
	 * configuration object is attached to.
	 *
	 * @var string
	 */
	const SWITCHER_HANDLE = 'mhmcs-switcher';

	/**
	 * Handle of the client-side price converter.
	 *
	 * @var string
	 */
	const CONVERTER_HANDLE = 'mhmcs-price-converter';

	/**
	 * Name of the localized JavaScript object.
	 *
	 * @var string
	 */
	const DATA_OBJECT = 'mhmcsData';

	/**
	 * Currency data store.
	 *
	 * @var CurrencyStore
	 */
	private CurrencyStore $store;

	/**
	 * Shared conversion-context resolver.
	 *
	 * @var ConversionContext
	 */
	private ConversionContext $context;

	/**
	 * The switcher renderer, consulted only for its currency list.
	 *
	 * @var Switcher
	 */
	private Switcher $switcher;

	/**
	 * Constructor.
	 *
	 * @param CurrencyStore     $store    Currency data store.
	 * @param ConversionContext $context  The request's single context resolver
	 *                                    — the same instance the price surfaces
	 *                                    and the marker use, so "was a marker
	 *                                    emitted?" and "was the converter
	 *                                    loaded?" cannot disagree.
	 * @param Switcher          $switcher Switcher renderer, for the currency
	 *                                    list handed to the client.
	 */
	public function __construct( CurrencyStore $store, ConversionContext $context, Switcher $switcher ) {
		$this->store    = $store;
		$this->context  = $context;
		$this->switcher = $switcher;
	}

	/**
	 * Register the enqueue hook.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue frontend assets.
	 *
	 * Only loads when WooCommerce is active (class_exists check).
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		wp_enqueue_style(
			'mhmcs-switcher',
			MHMCS_URL . 'assets/css/switcher.css',
			array(),
			MHMCS_VERSION
		);

		wp_enqueue_style(
			'mhmcs-product-widget',
			MHMCS_URL . 'assets/css/product-widget.css',
			array(),
			MHMCS_VERSION
		);

		/*
		 * ONE reading of the shared decision, used for two things that must
		 * never disagree: whether price-converter.js is loaded at all, and
		 * what switcher.js is told about it. Asking twice would let the two
		 * answers drift — and the drift is not cosmetic: switcher.js fires an
		 * event when the client converts and falls back to a page reload when
		 * it does not, so a wrong answer means a switcher that appears to do
		 * nothing on a cart page.
		 *
		 * Reading the decision at this point is safe with respect to the
		 * context's one-way latch. wp_enqueue_scripts fires inside wp_head,
		 * long after the `wp` action, so a "base" answer is not stored and a
		 * "convert" answer would have latched at the first price read anyway.
		 */
		$server_converts = $this->context->should_convert();

		wp_enqueue_script(
			self::SWITCHER_HANDLE,
			MHMCS_URL . 'assets/js/switcher.js',
			array(),
			MHMCS_VERSION,
			true
		);

		/*
		 * Attached to the switcher handle rather than to the converter's,
		 * because both scripts need it and the converter is conditional: the
		 * switcher has to know the cookie contract and the currency list even
		 * on a cart page, where no price is converted client-side.
		 */
		wp_localize_script(
			self::SWITCHER_HANDLE,
			self::DATA_OBJECT,
			$this->build_script_data( ! $server_converts )
		);

		/*
		 * The converter is loaded only where there is something for it to do,
		 * and the condition is not a second opinion about that: it is the very
		 * decision PriceDisplayMarker used. "The server did not convert" is
		 * exactly when markers exist, so asking the shared context here covers
		 * the money context, the logged-in visitor and the switched-off mode in
		 * one question, with no chance of the two answers drifting apart.
		 */
		if ( $server_converts ) {
			return;
		}

		wp_enqueue_script(
			self::CONVERTER_HANDLE,
			MHMCS_URL . 'assets/js/price-converter.js',
			array( self::SWITCHER_HANDLE ),
			MHMCS_VERSION,
			true
		);
	}

	/**
	 * Build the configuration object handed to the front-end scripts.
	 *
	 * 🔴 Everything sits one level down, under `config`, on purpose.
	 * wp_localize_script() casts every TOP-LEVEL scalar to a string for
	 * backwards compatibility: `true` reaches JavaScript as `"1"`, `false` as
	 * the empty string, and `50` as `"50"`. Nested values are JSON-encoded
	 * untouched, so the client reads real booleans and real numbers. This is
	 * the same class of defect as the key-name mismatches this plugin has
	 * shipped before — a value that silently changes type between the two
	 * sides of one contract.
	 *
	 * The four constants below are read from the PHP that owns them rather
	 * than restated in JavaScript, for the same reason: a hard-coded batch size
	 * that drifted from the endpoint's cap would turn every page into a 400,
	 * and a hard-coded marker class would simply stop matching.
	 *
	 * @param bool $client_conversion Whether price-converter.js is being
	 *                                loaded on this render, i.e. whether the
	 *                                client — rather than a page reload — is
	 *                                what applies a currency change.
	 * @return array{config: array<string, mixed>} Localization payload.
	 */
	private function build_script_data( bool $client_conversion ): array {
		$settings = get_option( 'mhmcs_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		return array(
			'config' => array(
				'restUrl'          => esc_url_raw( rest_url( ConvertController::NAMESPACE_V1 . ConvertController::ROUTE ) ),
				'baseCurrency'     => $this->store->get_base_currency(),

				/*
				 * Defaulting to enabled when the key was never written matches
				 * both the activation default and ConversionContext's own
				 * reading of it. A site upgraded from v1.0.0 has no such key.
				 */
				'cacheCompat'      => ! array_key_exists( 'cache_compat', $settings ) || (bool) $settings['cache_compat'],

				/*
				 * Whether a currency change is applied by the client or by a
				 * page reload — the exact same decision that determines
				 * whether price-converter.js is on the page, passed in rather
				 * than re-derived, so the two can never disagree.
				 *
				 * NOT the same thing as `cacheCompat`. Cache compatibility can
				 * be on while this is false: a cart page, or a logged-in
				 * visitor, is converted server-side and carries no converter
				 * script, so nothing there would listen for
				 * `mhmcs:currency-changed` and the switcher would appear to do
				 * nothing at all. switcher.js reloads in that case, which is
				 * the v1.0.0 behaviour and correct for a page the server
				 * renders per visitor.
				 */
				'clientConversion' => $client_conversion,

				/*
				 * The opposite direction from 'cacheCompat' above, on purpose.
				 * cacheCompat defaults an absent key to enabled because that
				 * matches both the activation default and what a v1.0.0
				 * upgrade was already doing — a pure correctness question this
				 * plugin answers either way. auto_detect defaults an absent
				 * key to OFF because what it gates changes what the visitor
				 * sees based on a country resolved from their IP address; a
				 * settings row that never carried this key never asked for
				 * that, and enabling it silently on upgrade would be the
				 * surprise, not the safe choice. Since 2.2.0 default_settings()
				 * seeds it false too, so the two agree.
				 */
				'autoDetect'       => ! empty( $settings['auto_detect'] ),
				'cookieName'       => DetectionService::COOKIE_NAME,
				'cookieDays'       => DetectionService::COOKIE_DAYS,
				'urlParam'         => DetectionService::URL_PARAM,
				'batchSize'        => ConvertController::MAX_PRODUCT_IDS,
				'markerClass'      => PriceDisplayMarker::CSS_CLASS,
				'idAttribute'      => PriceDisplayMarker::ID_ATTRIBUTE,
				'currencies'       => $this->build_currency_map(),
			),
		);
	}

	/**
	 * Map every currency this shop offers to its symbol, flag and name.
	 *
	 * Two jobs, and the second is the load-bearing one. The switcher UI uses
	 * the symbol and flag to show the visitor's active currency without a page
	 * reload; and the KEYS of this map are the allowlist the client validates a
	 * cookie or a `?currency=` value against. That list — base currency plus
	 * enabled currencies — is precisely what DetectionService::validate_code()
	 * accepts, so a code one side rejects the other rejects too.
	 *
	 * @return array<string, array<string, string>> Currency data keyed by code.
	 */
	private function build_currency_map(): array {
		$map = array();

		foreach ( $this->switcher->get_currency_options() as $option ) {
			$code = isset( $option['code'] ) ? (string) $option['code'] : '';

			if ( '' === $code ) {
				continue;
			}

			$map[ $code ] = array(
				'symbol' => isset( $option['symbol'] ) ? (string) $option['symbol'] : '',
				'flag'   => isset( $option['flag_url'] ) ? (string) $option['flag_url'] : '',
				'name'   => isset( $option['name'] ) ? (string) $option['name'] : $code,
			);
		}

		return $map;
	}
}
