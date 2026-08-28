<?php
/**
 * Detects the one way cache compatibility turns itself off in silence.
 *
 * @package MhmCurrencySwitcher\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Warns the shop owner when cache compatibility has stopped applying.
 *
 * 🔴 The failure this watches for is the worst one the feature has, and the
 * reason is that nothing about it looks like a failure. Themes and plugins
 * render a header mini-cart total by defining WOOCOMMERCE_CART site-wide.
 * ConversionContext reads that constant as "the customer's money is at stake"
 * — correctly, on a cart page — so from the first price on the first page the
 * latch closes, every page renders server-side converted, and cache
 * compatibility is off across the whole site. No error, no warning, and to the
 * shop owner nothing at all has changed: prices still look right. What has come
 * back is the original bug, where the first visitor's currency is what the page
 * cache stores for everybody.
 *
 * The signal has to be narrow or it is worse than nothing. "The latch closed"
 * is far too broad — that is what a cart page is SUPPOSED to do, and a warning
 * that fires on correct behaviour gets dismissed once and never read again. The
 * anomaly is specifically: the cart constant is defined on a request that is
 * not a cart or checkout view.
 *
 * @since 1.1.0
 */
final class CacheCompatDiagnostic {

	/**
	 * Option holding the path where the anomaly was last seen.
	 *
	 * Empty string means "checked, and healthy" — distinct from the option
	 * being absent, which means no front-end render has reported yet.
	 *
	 * @var string
	 */
	const OPTION = 'mhmcs_cache_compat_anomaly';

	/**
	 * Option holding the path where a mini-cart was left without fragments.
	 *
	 * Kept apart from OPTION on purpose. The two anomalies have different
	 * causes and different fixes, and a shop can have both at once; sharing one
	 * option would let fixing the theme silence a report about the other.
	 *
	 * @var string
	 */
	const OPTION_FRAGMENTS = 'mhmcs_cache_compat_fragments';

	/**
	 * Handle of WooCommerce's cart fragment refresh script.
	 *
	 * @var string
	 */
	const FRAGMENTS_HANDLE = 'wc-cart-fragments';

	/**
	 * User-meta key holding the SIGNATURE (the path from OPTION) a user has
	 * snoozed for the cart-constant anomaly.
	 *
	 * A boolean "dismissed forever" flag was rejected by design: snoozing is
	 * scoped to the exact path the anomaly was seen at, so a NEW path — the
	 * anomaly moving somewhere else, which is new information a shop owner
	 * needs to see — brings the notice back even though it was dismissed.
	 * See is_anomaly_snoozed().
	 *
	 * @var string
	 */
	const SNOOZE_META = 'mhmcs_snooze_cache_anomaly';

	/**
	 * User-meta key holding the snoozed signature for the fragments anomaly.
	 *
	 * Kept apart from SNOOZE_META for the same reason OPTION_FRAGMENTS is
	 * kept apart from OPTION: the two anomalies are independent, and
	 * snoozing one must not silence the other.
	 *
	 * @var string
	 */
	const SNOOZE_META_FRAGMENTS = 'mhmcs_snooze_cache_fragments';

	/**
	 * REST namespace for the snooze endpoints.
	 *
	 * Restated rather than imported from Admin\RestAPI — same reasoning as
	 * ConvertController::NAMESPACE_V1: the literal is a published URL either
	 * way, and this class must not gain an Admin\ dependency to get it.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mhmcs/v1';

	/**
	 * Route for snoozing the cart-constant anomaly.
	 *
	 * @var string
	 */
	const REST_ROUTE_SNOOZE_ANOMALY = '/cache-notice/snooze-anomaly';

	/**
	 * Route for snoozing the fragments anomaly.
	 *
	 * @var string
	 */
	const REST_ROUTE_SNOOZE_FRAGMENTS = '/cache-notice/snooze-fragments';

	/**
	 * Handle for the vanilla admin script that wires the Snooze buttons.
	 *
	 * `mhmcs`-prefixed like every other handle this plugin registers — see
	 * bin/check-legacy-tokens.sh, which this branch exists to satisfy.
	 *
	 * @var string
	 */
	const SCRIPT_HANDLE = 'mhmcs-cache-notice';

	/**
	 * Screens this notice may appear on.
	 *
	 * Unlike the WooCommerce-missing notice, scoping this one to the
	 * plugin's own admin pages does not scope it to nothing: this class is
	 * only ever wired from `Plugin::bootstrap()`, which itself only runs
	 * once WooCommerce is confirmed active (see mhm-currency-switcher.php),
	 * so `Settings` has always registered its `admin_menu` entry by the
	 * time this notice could fire. `woocommerce_page_mhm-currency-switcher`
	 * is that entry's own hook suffix — see `Settings::add_menu_page()` and
	 * `Settings::get_hook_suffix()`, and the parity test in
	 * CacheCompatDiagnosticTest that checks this literal against what
	 * `add_submenu_page()` actually returns at runtime. The other two are
	 * WooCommerce's own settings and status screens, where a shop owner
	 * chasing a cache or conversion problem is likely already looking.
	 *
	 * @var string[]
	 */
	const SCREENS = array(
		'woocommerce_page_mhm-currency-switcher',
		'woocommerce_page_wc-settings',
		'woocommerce_page_wc-status',
	);

	/**
	 * Shared conversion-context resolver.
	 *
	 * @var ConversionContext
	 */
	private ConversionContext $context;

	/**
	 * Whether a mini-cart was rendered during this request.
	 *
	 * @var bool
	 */
	private bool $mini_cart = false;

	/**
	 * Constructor.
	 *
	 * @param ConversionContext $context The request's context resolver.
	 */
	public function __construct( ConversionContext $context ) {
		$this->context = $context;
	}

	/**
	 * Register the front-end check and the admin notice.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'woocommerce_before_mini_cart', array( $this, 'note_mini_cart' ) );
		add_action( 'wp_footer', array( $this, 'check' ), PHP_INT_MAX );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Remember that this request rendered a mini-cart.
	 *
	 * WooCommerce's `cart/mini-cart.php` opens with `woocommerce_before_mini_cart`,
	 * so every route to a mini-cart — the classic widget, a theme's own markup,
	 * a block that renders the template — passes through here. There is no way
	 * to ask the page afterwards whether one was drawn.
	 *
	 * @return void
	 */
	public function note_mini_cart(): void {
		$this->mini_cart = true;
	}

	/**
	 * Whether a mini-cart was rendered during this request.
	 *
	 * @return bool
	 */
	public function has_mini_cart(): bool {
		return $this->mini_cart;
	}

	/**
	 * Whether this render shows cache compatibility being defeated.
	 *
	 * Pure, and takes its facts as arguments, so the decision can be tested
	 * without defining WOOCOMMERCE_CART anywhere. A test process that defines
	 * that constant keeps it for every test that follows it — a trap that has
	 * already silently skipped a test in this suite.
	 *
	 * @param bool $cache_compat  Whether the mode is switched on.
	 * @param bool $cart_constant Whether WOOCOMMERCE_CART/CHECKOUT is defined.
	 * @param bool $on_cart_page  Whether this really is a cart/checkout view.
	 * @return bool True when the mode is being defeated on this request.
	 */
	public static function is_anomalous( bool $cache_compat, bool $cart_constant, bool $on_cart_page ): bool {
		if ( ! $cache_compat || ! $cart_constant ) {
			return false;
		}

		return ! $on_cart_page;
	}

	/**
	 * Whether this render leaves a mini-cart stranded in the base currency.
	 *
	 * On a cacheable render the mini-cart is printed in the base currency and
	 * corrected afterwards by WooCommerce's cart fragment refresh, which goes
	 * through the server and therefore converts. Themes and optimisation plugins
	 * dequeue `wc-cart-fragments` as a matter of routine, and when they do the
	 * correction never arrives: every other price on the page converts in the
	 * browser and the mini-cart total sits there in the base currency. Nothing
	 * errors, which is why it has to be said in the admin.
	 *
	 * Pure, and takes its facts as arguments, for the same reason the cart
	 * constant check is: the production facts are entangled, the arguments are
	 * not.
	 *
	 * 🔴 `$mini_cart_rendered` is what keeps this narrow. Most shops render no
	 * mini-cart at all and plenty have the script dequeued deliberately for the
	 * speed; without that fact in the condition the warning fires on a correctly
	 * configured shop, and a diagnostic that cries on correct behaviour is
	 * dismissed once and never read again.
	 *
	 * @param bool $cache_compat       Whether the mode is switched on.
	 * @param bool $cacheable_render   Whether this render is the cacheable one.
	 * @param bool $mini_cart_rendered Whether a mini-cart was drawn.
	 * @param bool $fragments_will_run Whether wc-cart-fragments was printed.
	 * @return bool True when the mini-cart is left in the base currency.
	 */
	public static function is_fragments_anomalous(
		bool $cache_compat,
		bool $cacheable_render,
		bool $mini_cart_rendered,
		bool $fragments_will_run
	): bool {
		if ( ! $cache_compat || ! $cacheable_render || ! $mini_cart_rendered ) {
			return false;
		}

		return ! $fragments_will_run;
	}

	/**
	 * Store the verdict for this render, writing only when it changed.
	 *
	 * This runs on every front-end page view, so an unconditional write would
	 * put a database write on each request of a site whose entire purpose is
	 * to be served from a cache.
	 *
	 * A clean render clears an earlier report rather than only being able to be
	 * dismissed: the owner fixes their theme and the warning has to go away by
	 * itself, or it stops meaning anything.
	 *
	 * @param bool   $anomalous Whether this render was anomalous.
	 * @param string $path      Request path, shown to the owner as an example.
	 * @param string $option    Which anomaly is being recorded.
	 * @return void
	 */
	public static function record( bool $anomalous, string $path, string $option = self::OPTION ): void {
		$stored = get_option( $option, null );
		$value  = $anomalous ? $path : '';

		if ( null === $stored && ! $anomalous ) {
			// Nothing was ever reported and nothing is wrong: writing "healthy"
			// on a site that has never had a problem is a row for no reason.
			return;
		}

		if ( is_string( $stored ) && $stored === $value ) {
			return;
		}

		update_option( $option, $value, false );

		/*
		 * Transition-only, and that qualifier is load-bearing. This method runs
		 * on every front-end request, and delete_metadata( 'user', 0, ..., true )
		 * is a SITE-WIDE delete across every user's row — issuing it whenever
		 * $anomalous is merely false would fire it on every ordinary healthy
		 * request forever, not once at the moment a real problem actually
		 * cleared. The guard is on the OLD value being a real, non-empty
		 * signature: a site that never had a problem is caught by the first
		 * return above, and one that was already clean is caught by the
		 * equality check above it — neither reaches this line.
		 *
		 * Clearing the meta here, rather than leaving it to expire on its own,
		 * is the other half of "snooze holds until the signature changes": once
		 * the option itself goes back to empty, the signature a snooze was
		 * pinned to no longer describes anything, and a LATER occurrence of the
		 * very same path must be seen again rather than silently re-matching a
		 * snooze nobody re-armed.
		 */
		if ( '' === $value && is_string( $stored ) && '' !== $stored ) {
			delete_metadata( 'user', 0, self::snooze_meta_for( $option ), '', true );
		}
	}

	/**
	 * Which user-meta key holds the snoozed signature for a given option.
	 *
	 * @param string $option self::OPTION or self::OPTION_FRAGMENTS.
	 * @return string
	 */
	private static function snooze_meta_for( string $option ): string {
		return self::OPTION_FRAGMENTS === $option ? self::SNOOZE_META_FRAGMENTS : self::SNOOZE_META;
	}

	/**
	 * Whether a recorded anomaly is currently snoozed for the viewing user.
	 *
	 * Pure, and compares SIGNATURES rather than a boolean flag: a snooze
	 * recorded for one path must not silence a later anomaly reported at a
	 * DIFFERENT path, because a new location is new information. See the
	 * class docblock's design note.
	 *
	 * @param string $current_signature The path presently stored in the option.
	 * @param mixed  $snoozed_signature Whatever get_user_meta() returned for this user.
	 * @return bool True when the current anomaly is hidden by an active snooze.
	 */
	public static function is_anomaly_snoozed( string $current_signature, $snoozed_signature ): bool {
		if ( '' === $current_signature || ! is_string( $snoozed_signature ) || '' === $snoozed_signature ) {
			return false;
		}

		return $snoozed_signature === $current_signature;
	}

	/**
	 * Whether this request really is a cart or checkout view.
	 *
	 * 🔴 Deliberately does NOT call is_cart()/is_checkout(), and that is the
	 * entire point of the method. WooCommerce defines them as
	 * `filter || defined( 'WOOCOMMERCE_CART' ) || CartCheckoutUtils::is_cart_page()`
	 * — the constant this diagnostic is investigating SATISFIES them. Asking
	 * is_cart() here made the anomaly answer "that's just the cart page" on
	 * every request, so the warning could never fire at all. The first version
	 * of this class shipped that mistake past a green unit suite: the tests
	 * pass the two facts in as independent arguments, and they are not
	 * independent in production.
	 *
	 * What is left after removing the constant is WooCommerce's own definition:
	 * the page it assigned, or a page carrying the cart/checkout shortcode. A
	 * shop that put its cart on some other page legitimately defines the
	 * constant there, and warning about that would be a false alarm.
	 *
	 * @return bool True when this is genuinely a cart or checkout view.
	 */
	public static function is_real_cart_view(): bool {
		foreach ( array( 'cart', 'checkout' ) as $page ) {
			if ( function_exists( 'wc_get_page_id' ) && function_exists( 'is_page' ) ) {
				$id = (int) wc_get_page_id( $page );

				if ( $id > 0 && is_page( $id ) ) {
					return true;
				}
			}

			if ( function_exists( 'wc_post_content_has_shortcode' )
				&& wc_post_content_has_shortcode( 'woocommerce_' . $page ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether this render is worth reporting a path from.
	 *
	 * A 404 is not. The browser's own request for `/favicon.ico` is served as
	 * one, `wp_footer` fires on it like any other render, and the last write
	 * wins — so the warning told the shop owner their prices were broken "on
	 * /favicon.ico/", which is both useless and faintly alarming. Seen in the
	 * first browser round of this feature.
	 *
	 * @return bool True when this render can name a page the owner recognises.
	 */
	private static function is_reportable_render(): bool {
		return ! ( function_exists( 'is_404' ) && is_404() );
	}

	/**
	 * Check this render and remember the verdict.
	 *
	 * @return void
	 */
	public function check(): void {
		if ( is_admin() || ! self::is_reportable_render() ) {
			return;
		}

		$anomalous = self::is_anomalous(
			$this->context->is_cache_compat_enabled(),
			defined( 'WOOCOMMERCE_CART' ) || defined( 'WOOCOMMERCE_CHECKOUT' ),
			self::is_real_cart_view()
		);

		$path = isset( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		self::record( $anomalous, $path );

		/*
		 * Asked at PHP_INT_MAX on wp_footer, which is after
		 * wp_print_footer_scripts (priority 20) — so "done" is the settled
		 * answer for this render however WooCommerce or a theme decided it,
		 * rather than a guess at what should have been enqueued.
		 */
		self::record(
			self::is_fragments_anomalous(
				$this->context->is_cache_compat_enabled(),
				$this->context->is_cacheable_render(),
				$this->has_mini_cart(),
				function_exists( 'wp_script_is' ) && wp_script_is( self::FRAGMENTS_HANDLE, 'done' )
			),
			$path,
			self::OPTION_FRAGMENTS
		);
	}

	/**
	 * Whether the given capability and screen together permit this notice.
	 *
	 * Pure given its inputs, so the capability gate and the screen scope can
	 * each be exercised directly without a real wp-admin request.
	 *
	 * @param bool        $can_manage_woocommerce Whether the user has manage_woocommerce.
	 * @param string|null $screen_id              Current screen id, or null when unset.
	 * @return bool True when the notice may render.
	 */
	public static function is_notice_visible( bool $can_manage_woocommerce, ?string $screen_id ): bool {
		if ( ! $can_manage_woocommerce ) {
			return false;
		}

		if ( null === $screen_id ) {
			return false;
		}

		return in_array( $screen_id, self::SCREENS, true );
	}

	/**
	 * Show the warning on admin screens.
	 *
	 * `get_current_screen()` returns null before the screen has been set up
	 * (it is not available before `admin_init`), so the id handed to
	 * `is_notice_visible()` is null in that case rather than a fatal error
	 * from reading `->id` off nothing.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		$screen    = get_current_screen();
		$screen_id = ( null !== $screen ) ? (string) $screen->id : null;

		if ( ! self::is_notice_visible( current_user_can( 'manage_woocommerce' ), $screen_id ) ) {
			return;
		}

		$this->render_cart_constant_notice();
		$this->render_fragments_notice();
	}

	/**
	 * The mini-cart left in the base currency, if it happened.
	 *
	 * @return void
	 */
	private function render_fragments_notice(): void {
		$path = get_option( self::OPTION_FRAGMENTS, '' );

		if ( ! is_string( $path ) || '' === $path ) {
			return;
		}

		if ( self::is_anomaly_snoozed( $path, get_user_meta( get_current_user_id(), self::SNOOZE_META_FRAGMENTS, true ) ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'MHM Currency Switcher: the mini-cart is stuck in your base currency.', 'mhm-currency-switcher' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: the request path where the problem was detected, 2: the script handle that is missing. */
					esc_html__( 'On %1$s a mini-cart was rendered on a page that gets cached, but WooCommerce\'s %2$s script was not loaded. That script is what fetches the mini-cart again through the server, which is where the conversion happens — so without it the rest of the page converts and the mini-cart total stays in your base currency. Themes and optimisation plugins commonly remove this script for speed.', 'mhm-currency-switcher' ),
					'<code>' . esc_html( $path ) . '</code>',
					'<code>' . esc_html( self::FRAGMENTS_HANDLE ) . '</code>'
				);
				?>
			</p>
			<p>
				<?php esc_html_e( 'Either let that script load again, or remove the mini-cart from cached pages. This notice clears itself once a page renders with both.', 'mhm-currency-switcher' ); ?>
			</p>
			<p>
				<button type="button" class="button" data-mhmcs-snooze="fragments">
					<?php esc_html_e( 'Snooze until this changes', 'mhm-currency-switcher' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * The cart constant defined away from the cart, if it happened.
	 *
	 * @return void
	 */
	private function render_cart_constant_notice(): void {
		$path = get_option( self::OPTION, '' );

		if ( ! is_string( $path ) || '' === $path ) {
			return;
		}

		if ( self::is_anomaly_snoozed( $path, get_user_meta( get_current_user_id(), self::SNOOZE_META, true ) ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'MHM Currency Switcher: cache compatibility is not being applied.', 'mhm-currency-switcher' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: the request path where the problem was detected. */
					esc_html__( 'On %s the plugin was forced to convert prices on the server, which is what cache compatibility mode exists to avoid. The usual cause is a theme or plugin that defines the WooCommerce cart constant on every page — often to show a cart total in the header. While that is happening, a page cache can store one visitor\'s currency and serve it to everybody else.', 'mhm-currency-switcher' ),
					'<code>' . esc_html( $path ) . '</code>'
				);
				?>
			</p>
			<p>
				<?php esc_html_e( 'This notice clears itself as soon as a front-end page renders normally again.', 'mhm-currency-switcher' ); ?>
			</p>
			<p>
				<button type="button" class="button" data-mhmcs-snooze="anomaly">
					<?php esc_html_e( 'Snooze until this changes', 'mhm-currency-switcher' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Register the snooze REST routes.
	 *
	 * Registered unconditionally, like ConvertController's route — a shop
	 * that just switched cache compatibility off may still have an admin
	 * tab open with a Snooze button on it, and that click must not 404.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE_SNOOZE_ANOMALY,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'snooze_cart_constant_anomaly' ),
				'permission_callback' => array( $this, 'check_snooze_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE_SNOOZE_FRAGMENTS,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'snooze_fragments_anomaly' ),
				'permission_callback' => array( $this, 'check_snooze_permission' ),
			)
		);
	}

	/**
	 * Nonce + capability, both checked here explicitly rather than left
	 * entirely to WordPress's own cookie-auth nonce enforcement — this is new
	 * attack surface (a write endpoint), and both halves must be provably
	 * enforced, in both directions, on their own.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool True when the request may snooze a notice.
	 */
	public function check_snooze_permission( WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		return self::is_snooze_permitted(
			is_string( $nonce ) && '' !== $nonce && false !== wp_verify_nonce( $nonce, 'wp_rest' ),
			current_user_can( 'manage_woocommerce' )
		);
	}

	/**
	 * Pure predicate behind check_snooze_permission(), exercised directly so
	 * both halves of the gate — and both directions of each — can be proven
	 * without constructing a real REST request.
	 *
	 * @param bool $valid_nonce            Whether the request's nonce verified.
	 * @param bool $can_manage_woocommerce Whether the user holds manage_woocommerce.
	 * @return bool True when both hold.
	 */
	public static function is_snooze_permitted( bool $valid_nonce, bool $can_manage_woocommerce ): bool {
		return $valid_nonce && $can_manage_woocommerce;
	}

	/**
	 * POST — snooze the cart-constant anomaly at its current signature.
	 *
	 * @return WP_REST_Response
	 */
	public function snooze_cart_constant_anomaly(): WP_REST_Response {
		return self::snooze( self::OPTION, self::SNOOZE_META );
	}

	/**
	 * POST — snooze the fragments anomaly at its current signature.
	 *
	 * @return WP_REST_Response
	 */
	public function snooze_fragments_anomaly(): WP_REST_Response {
		return self::snooze( self::OPTION_FRAGMENTS, self::SNOOZE_META_FRAGMENTS );
	}

	/**
	 * Snooze whichever anomaly $option describes, at whatever signature is
	 * CURRENTLY stored for it.
	 *
	 * 🔴 The request carries no signature of its own, deliberately. The
	 * obvious design has the browser send back the signature it saw and
	 * displayed — but the server already knows which anomaly is presently
	 * recorded: it is this very $option, the same value render_notice() just
	 * read to decide whether to print a notice at all. Accepting a value from
	 * the client would mean validating an arbitrary string before writing it
	 * to user meta, and would let a request snooze a signature that was never
	 * actually recorded. Reading it here instead removes that whole class of
	 * problem rather than solving it.
	 *
	 * @param string $option   self::OPTION or self::OPTION_FRAGMENTS.
	 * @param string $meta_key The matching snooze meta key.
	 * @return WP_REST_Response
	 */
	private static function snooze( string $option, string $meta_key ): WP_REST_Response {
		$signature = get_option( $option, '' );

		if ( ! is_string( $signature ) || '' === $signature ) {
			// Nothing recorded right now — there is nothing to snooze, and
			// writing a snooze for an empty signature would match the NEXT
			// clean render (is_anomaly_snoozed() refuses an empty signature
			// on both sides, but there is no reason to store one either).
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'There is nothing to snooze right now.', 'mhm-currency-switcher' ),
				),
				200
			);
		}

		update_user_meta( get_current_user_id(), $meta_key, $signature );

		return new WP_REST_Response(
			array(
				'success'   => true,
				'signature' => $signature,
			),
			200
		);
	}

	/**
	 * Enqueue the Snooze button's script — only on the same three screens
	 * the notice itself is scoped to (self::SCREENS). A button that can
	 * never print has no reason to ship its script everywhere else in
	 * wp-admin.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, self::SCREENS, true ) ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			MHMCS_URL . 'assets/js/cache-notice-snooze.js',
			array(),
			MHMCS_VERSION,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'mhmcsCacheNotice',
			array(
				'restUrl' => esc_url_raw( rest_url( self::REST_NAMESPACE . '/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}
