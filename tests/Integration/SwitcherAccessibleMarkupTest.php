<?php
/**
 * Integration test: the switcher's accessibility attributes survive
 * wp_kses_post() on the WordPress this run installed.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

/**
 * Switcher::render_switcher() returns its markup through wp_kses_post(), and
 * what kses keeps differs by WordPress version: aria-haspopup on a button only
 * from 6.9, tabindex on a list item only from 7.1. Those two are therefore set
 * by assets/js/switcher.js. Everything the PHP renderer DOES print is asserted
 * here, against real kses, so the claim in Switcher.php's escape comment is
 * measured on every matrix leg (6.6 floor, 6.8, latest, trunk) rather than
 * read off one version's allowlist.
 *
 * Attribute ORDER is deliberately not asserted: WordPress 7.2's HTML-API kses
 * does not keep it.
 */
class SwitcherAccessibleMarkupTest extends MhmcsIntegrationTestCase {

	/**
	 * Rendered shortcode.
	 *
	 * @var string
	 */
	private $html = '';

	/**
	 * Render once per test, as a visitor would get it.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->html = do_shortcode( '[mhmcs_currency_switcher]' );

		// Positive control: without a rendered switcher every "not contains"
		// below would pass on an empty string.
		$this->assertStringContainsString( 'mhmcs-switcher', $this->html, 'Precondition: the switcher rendered.' );
	}

	/**
	 * The opening tag of the first element matching a class.
	 *
	 * @param string $tag   Tag name.
	 * @param string $class Class the element carries.
	 * @return string The tag, or '' when absent.
	 */
	private function tag( string $tag, string $class ): string {
		return 1 === preg_match( '#<' . $tag . '\b[^>]*\bclass="' . preg_quote( $class, '#' ) . '[^"]*"[^>]*>#', $this->html, $m ) ? $m[0] : '';
	}

	/**
	 * A <button> without type="button" is a submit button.
	 *
	 * @return void
	 */
	public function test_the_button_is_not_a_submit_button(): void {
		$button = $this->tag( 'button', 'mhmcs-selected' );

		$this->assertNotSame( '', $button );
		$this->assertStringContainsString( 'type="button"', $button );
		$this->assertStringContainsString( 'aria-expanded="false"', $button );
	}

	/**
	 * The listbox has an accessible name (ARIA 1.2 requires one).
	 *
	 * @return void
	 */
	public function test_the_listbox_has_an_accessible_name(): void {
		$list = $this->tag( 'ul', 'mhmcs-dropdown' );

		$this->assertNotSame( '', $list );
		$this->assertStringContainsString( 'role="listbox"', $list );
		$this->assertMatchesRegularExpression( '/\baria-label="[^"]+"/', $list );
	}

	/**
	 * The arrow glyph is hidden from assistive technology.
	 *
	 * @return void
	 */
	public function test_the_arrow_glyph_is_hidden_from_screen_readers(): void {
		$arrow = $this->tag( 'span', 'mhmcs-arrow' );

		$this->assertNotSame( '', $arrow );
		$this->assertStringContainsString( 'aria-hidden="true"', $arrow );
	}

	/**
	 * Every option keeps its role and its currency code.
	 *
	 * @return void
	 */
	public function test_options_keep_role_and_currency(): void {
		$this->assertGreaterThanOrEqual( 2, preg_match_all( '#<li\b(?=[^>]*\brole="option")(?=[^>]*\bdata-currency="[A-Z]{3}")[^>]*>#', $this->html ) );
	}

	/**
	 * The attributes kses drops on older WordPress are NOT printed by PHP:
	 * the script owns them. If one reappears here it would exist on some
	 * versions and silently vanish on others.
	 *
	 * @return void
	 */
	public function test_version_dependent_attributes_are_left_to_the_script(): void {
		$this->assertStringNotContainsString( 'aria-haspopup', $this->html );
		$this->assertStringNotContainsString( 'tabindex', $this->html );
	}
}
