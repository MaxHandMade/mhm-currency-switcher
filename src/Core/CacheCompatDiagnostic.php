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
	 * Show the warning on admin screens.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
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
		</div>
		<?php
	}
}
