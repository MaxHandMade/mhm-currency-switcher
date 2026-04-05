<?php
/**
 * WordPress nav menu integration for the currency switcher.
 *
 * Registers a custom metabox in Appearance > Menus that lets users
 * add the currency switcher as a menu item. On the frontend, the
 * menu item is replaced with the interactive switcher dropdown.
 *
 * @package MhmCurrencySwitcher\Frontend
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Frontend;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NavMenu — currency switcher as a WordPress nav menu item.
 *
 * @since 0.3.0
 */
final class NavMenu {

	/**
	 * Custom URL used to identify our menu item.
	 *
	 * @var string
	 */
	const MENU_ITEM_URL = '#mhm-currency-switcher';

	/**
	 * Switcher renderer.
	 *
	 * @var Switcher
	 */
	private Switcher $switcher;

	/**
	 * Constructor.
	 *
	 * @param Switcher $switcher Switcher renderer.
	 */
	public function __construct( Switcher $switcher ) {
		$this->switcher = $switcher;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		// Admin: add metabox to menu editor.
		add_action( 'admin_head-nav-menus.php', array( $this, 'add_menu_metabox' ) );

		// Frontend: replace our menu item with the switcher.
		add_filter( 'wp_nav_menu_objects', array( $this, 'replace_menu_item' ), 10, 2 );

		// Prevent our URL from being sanitized/removed.
		add_filter( 'wp_setup_nav_menu_item', array( $this, 'label_menu_item' ) );
	}

	/**
	 * Register the metabox in Appearance > Menus.
	 *
	 * @return void
	 */
	public function add_menu_metabox(): void {
		add_meta_box(
			'mhm-cs-nav-menu',
			__( 'MHM Currency Switcher', 'mhm-currency-switcher' ),
			array( $this, 'render_metabox' ),
			'nav-menus',
			'side',
			'low'
		);
	}

	/**
	 * Render the metabox content with a single "Currency Switcher" item.
	 *
	 * @return void
	 */
	public function render_metabox(): void {
		global $_nav_menu_placeholder;

		$_nav_menu_placeholder = $_nav_menu_placeholder < -1 // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WP core pattern for nav menu metaboxes.
			? $_nav_menu_placeholder - 1
			: -1;

		?>
		<div id="mhm-cs-menu-item" class="posttypediv">
			<div class="tabs-panel tabs-panel-active">
				<ul class="categorychecklist form-no-clear">
					<li>
						<label class="menu-item-title">
							<input
								type="checkbox"
								class="menu-item-checkbox"
								name="menu-item[<?php echo esc_attr( (string) $_nav_menu_placeholder ); ?>][menu-item-object-id]"
								value="<?php echo esc_attr( (string) $_nav_menu_placeholder ); ?>"
							/>
							<?php esc_html_e( 'Currency Switcher', 'mhm-currency-switcher' ); ?>
						</label>
						<input
							type="hidden"
							class="menu-item-type"
							name="menu-item[<?php echo esc_attr( (string) $_nav_menu_placeholder ); ?>][menu-item-type]"
							value="custom"
						/>
						<input
							type="hidden"
							class="menu-item-title"
							name="menu-item[<?php echo esc_attr( (string) $_nav_menu_placeholder ); ?>][menu-item-title]"
							value="<?php esc_attr_e( 'Currency', 'mhm-currency-switcher' ); ?>"
						/>
						<input
							type="hidden"
							class="menu-item-url"
							name="menu-item[<?php echo esc_attr( (string) $_nav_menu_placeholder ); ?>][menu-item-url]"
							value="<?php echo esc_attr( self::MENU_ITEM_URL ); ?>"
						/>
						<input
							type="hidden"
							class="menu-item-classes"
							name="menu-item[<?php echo esc_attr( (string) $_nav_menu_placeholder ); ?>][menu-item-classes]"
							value="mhm-cs-menu-item"
						/>
					</li>
				</ul>
			</div>
			<p class="button-controls wp-clearfix">
				<span class="add-to-menu">
					<input
						type="submit"
						class="button submit-add-to-menu right"
						value="<?php esc_attr_e( 'Add to Menu', 'mhm-currency-switcher' ); ?>"
						name="add-post-type-menu-item"
						id="mhm-cs-submit-menu-item"
					/>
					<span class="spinner"></span>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Label our custom menu items in the admin editor.
	 *
	 * @param object $menu_item Menu item object.
	 * @return object Modified menu item.
	 */
	public function label_menu_item( $menu_item ) {
		if ( isset( $menu_item->url ) && self::MENU_ITEM_URL === $menu_item->url ) {
			$menu_item->type_label = __( 'MHM Currency', 'mhm-currency-switcher' );
		}

		return $menu_item;
	}

	/**
	 * Replace our placeholder menu item with the switcher on the frontend.
	 *
	 * @param array  $items Menu item objects.
	 * @param object $args  wp_nav_menu arguments.
	 * @return array Modified menu items.
	 */
	public function replace_menu_item( array $items, $args ): array {
		if ( is_admin() ) {
			return $items;
		}

		foreach ( $items as &$item ) {
			if ( ! isset( $item->url ) || self::MENU_ITEM_URL !== $item->url ) {
				continue;
			}

			// Render the switcher and inject it as the menu item title.
			$switcher_html = $this->switcher->render_shortcode( array( 'size' => 'small' ) );

			if ( '' === $switcher_html ) {
				continue;
			}

			$item->title = $switcher_html;
			$item->url   = '';

			// Add identifying class.
			if ( ! is_array( $item->classes ) ) {
				$item->classes = array();
			}
			$item->classes[] = 'mhm-cs-menu-item';
		}
		unset( $item );

		return $items;
	}
}
