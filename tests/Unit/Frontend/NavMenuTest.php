<?php
/**
 * Unit tests for the nav menu integration.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Frontend;

use MhmCurrencySwitcher\Core\ConversionContext;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\DetectionService;
use MhmCurrencySwitcher\Frontend\NavMenu;
use MhmCurrencySwitcher\Frontend\Switcher;
use PHPUnit\Framework\TestCase;

/**
 * Class NavMenuTest
 *
 * Pure unit tests — no WordPress beyond the bootstrap stubs.
 *
 * Setup:
 *   Base: TRY
 *   USD: enabled, symbol=$
 *
 * @covers \MhmCurrencySwitcher\Frontend\NavMenu
 */
class NavMenuTest extends TestCase {

	/**
	 * NavMenu instance under test.
	 *
	 * @var NavMenu
	 */
	private NavMenu $nav_menu;

	/**
	 * Build a NavMenu wired to a real Switcher that renders non-empty HTML.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$store = new CurrencyStore();
		$store->set_data(
			'TRY',
			array(
				array(
					'code'    => 'USD',
					'enabled' => true,
					'rate'    => array(
						'type'  => 'manual',
						'value' => 0.03,
					),
					'fee'     => array(
						'type'  => 'fixed',
						'value' => 0,
					),
					'format'  => array(
						'symbol'       => '$',
						'position'     => 'left',
						'thousand_sep' => ',',
						'decimal_sep'  => '.',
						'decimals'     => 2,
					),
				),
			)
		);

		$context   = new ConversionContext();
		$detection = new DetectionService( $store, $context );
		$switcher  = new Switcher( $store, $detection, $context );

		$this->nav_menu = new NavMenu( $switcher );

		unset( $GLOBALS['__mhmcs_test_is_admin'] );
		unset( $_COOKIE[ DetectionService::COOKIE_NAME ] );
	}

	/**
	 * Clean up request state.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['__mhmcs_test_is_admin'] );
		unset( $_COOKIE[ DetectionService::COOKIE_NAME ] );

		parent::tearDown();
	}

	/**
	 * Build a menu item object carrying our marker URL.
	 *
	 * @param array<int, string> $classes Existing menu item classes.
	 * @return object Menu item.
	 */
	private function make_item( array $classes ) {
		$item          = new \stdClass();
		$item->url     = NavMenu::MENU_ITEM_URL;
		$item->title   = 'Currency';
		$item->classes = $classes;

		return $item;
	}

	/**
	 * The metabox saves 'mhmcs-menu-item' as the item's own class, so by the
	 * time the frontend filter runs the class is usually already present.
	 * Appending it unconditionally emitted it twice in the rendered class
	 * attribute. The filter must be idempotent.
	 *
	 * @return void
	 */
	public function test_marker_class_is_not_duplicated_when_already_present(): void {
		$items = array( $this->make_item( array( 'mhmcs-menu-item', 'menu-item' ) ) );

		$result = $this->nav_menu->replace_menu_item( $items, new \stdClass() );

		$occurrences = array_keys( $result[0]->classes, 'mhmcs-menu-item', true );

		$this->assertCount(
			1,
			$occurrences,
			'The marker class must appear exactly once, not once per filter pass.'
		);
	}

	/**
	 * When the item carries no classes — a hand-built custom link pointing at
	 * our URL — the filter must still add the marker so the CSS applies.
	 *
	 * @return void
	 */
	public function test_marker_class_is_added_when_absent(): void {
		$items = array( $this->make_item( array( 'menu-item' ) ) );

		$result = $this->nav_menu->replace_menu_item( $items, new \stdClass() );

		$this->assertContains(
			'mhmcs-menu-item',
			$result[0]->classes,
			'An item without the marker class must receive it.'
		);
	}

	/**
	 * Running the filter twice on the same item — which happens when a theme
	 * renders the same menu in more than one location — must not accumulate
	 * classes either.
	 *
	 * @return void
	 */
	public function test_filter_is_idempotent_across_repeated_passes(): void {
		$items = array( $this->make_item( array( 'menu-item' ) ) );

		$first  = $this->nav_menu->replace_menu_item( $items, new \stdClass() );
		$second = $this->nav_menu->replace_menu_item( $first, new \stdClass() );

		$occurrences = array_keys( $second[0]->classes, 'mhmcs-menu-item', true );

		$this->assertCount( 1, $occurrences, 'Repeated passes must not stack the marker class.' );
	}

	/**
	 * The filter replaces the title with switcher markup and clears the URL so
	 * the menu item is not a dead link. Guards the behaviour the class fix
	 * must not disturb.
	 *
	 * @return void
	 */
	public function test_item_is_replaced_with_switcher_markup(): void {
		$items = array( $this->make_item( array( 'mhmcs-menu-item' ) ) );

		$result = $this->nav_menu->replace_menu_item( $items, new \stdClass() );

		$this->assertStringContainsString( 'mhmcs-switcher', $result[0]->title );
		$this->assertSame( '', $result[0]->url );
	}

	/**
	 * Items that are not ours must be returned untouched.
	 *
	 * @return void
	 */
	public function test_unrelated_items_are_left_alone(): void {
		$item          = new \stdClass();
		$item->url     = 'https://example.com/shop/';
		$item->title   = 'Shop';
		$item->classes = array( 'menu-item' );

		$result = $this->nav_menu->replace_menu_item( array( $item ), new \stdClass() );

		$this->assertSame( 'Shop', $result[0]->title );
		$this->assertSame( 'https://example.com/shop/', $result[0]->url );
		$this->assertSame( array( 'menu-item' ), $result[0]->classes );
	}

	/**
	 * In the admin the filter must not run at all — the editor needs to show
	 * the item's real title, not rendered switcher markup.
	 *
	 * @return void
	 */
	public function test_admin_requests_are_untouched(): void {
		$GLOBALS['__mhmcs_test_is_admin'] = true;

		$items  = array( $this->make_item( array( 'mhmcs-menu-item' ) ) );
		$result = $this->nav_menu->replace_menu_item( $items, new \stdClass() );

		$this->assertSame( 'Currency', $result[0]->title );
		$this->assertSame( NavMenu::MENU_ITEM_URL, $result[0]->url );
	}
}
