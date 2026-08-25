<?php
/**
 * Minimal Elementor stubs for static analysis.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Elementor is an optional integration: the three classes in
 * src/Integration/Elementor/ only load when Elementor is active. Without a
 * declaration for `Elementor\Widget_Base`, PHPStan cannot analyse them at all
 * — and "extends unknown class" is one of the few errors PHPStan refuses to
 * let you ignore, so the only alternatives were to drop those files out of
 * `paths` (a scope hole, which is the very thing this round is closing) or to
 * take a dependency on one of the third-party vendor elementor-stubs packages.
 *
 * Declaring the handful of symbols we actually touch is better than either:
 * the files stay in scope, no unvetted dependency enters the build, and —
 * the part that matters — PHPStan can now check OUR calls to inherited
 * methods. A typo in `add_control()` is caught here; behind an `ignoreErrors`
 * pattern it would not have been.
 *
 * SCOPE RULE: this file declares ONLY what src/Integration/Elementor/ uses.
 * If you reach for another Elementor symbol, add it here in the same commit —
 * a missing declaration fails the gate loudly, which is the desired behaviour.
 *
 * NOT SHIPPED and NEVER LOADED AT RUNTIME: `tests/` is excluded by
 * .distignore and by phpcs.xml.dist; PHPStan reads it through bootstrapFiles.
 * The constant VALUES below mirror Elementor's own, but nothing depends on
 * them being right: PHPStan only needs the symbols to exist and be strings.
 *
 * @package MhmCurrencySwitcher
 */

namespace Elementor;

/**
 * Base class every Elementor widget extends.
 *
 * Method signatures are deliberately untyped, matching Elementor's own: our
 * widgets narrow the return types (`get_name(): string`), which is legal
 * against an untyped parent but would collide with an invented one.
 */
abstract class Widget_Base {

	/**
	 * Open a controls section.
	 *
	 * @param string               $section_id Section identifier.
	 * @param array<string, mixed> $args       Section arguments.
	 * @return void
	 */
	public function start_controls_section( $section_id, $args = array() ) {}

	/**
	 * Close the current controls section.
	 *
	 * @return void
	 */
	public function end_controls_section() {}

	/**
	 * Register a single control.
	 *
	 * @param string               $id      Control identifier.
	 * @param array<string, mixed> $args    Control arguments.
	 * @param array<string, mixed> $options Control options.
	 * @return void
	 */
	public function add_control( $id, $args = array(), $options = array() ) {}

	/**
	 * Resolved settings for the current render.
	 *
	 * @param string|null $setting_key Single setting to read, or null for all.
	 * @return array<string, mixed>|mixed
	 */
	public function get_settings_for_display( $setting_key = null ) {}

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	abstract public function get_name();

	/**
	 * Human-readable widget name.
	 *
	 * @return string
	 */
	abstract public function get_title();

	/**
	 * Editor icon class.
	 *
	 * @return string
	 */
	public function get_icon() {}

	/**
	 * Categories this widget belongs to.
	 *
	 * @return string[]
	 */
	public function get_categories() {}

	/**
	 * Declare the widget's controls.
	 *
	 * @return void
	 */
	protected function register_controls() {}

	/**
	 * Output the widget.
	 *
	 * @return void
	 */
	protected function render() {}
}

/**
 * Control type and tab constants.
 */
class Controls_Manager {
	const TAB_CONTENT = 'content';
	const TAB_STYLE   = 'style';
	const TEXT        = 'text';
	const SELECT      = 'select';
	const SWITCHER    = 'switcher';
	const COLOR       = 'color';
}

/**
 * Registry widgets are registered against.
 */
class Widgets_Manager {

	/**
	 * Register a widget instance.
	 *
	 * @param Widget_Base $widget Widget to register.
	 * @return void
	 */
	public function register( $widget ) {}
}

/**
 * Registry widget categories are registered against.
 */
class Elements_Manager {

	/**
	 * Register a widget category.
	 *
	 * @param string               $id   Category identifier.
	 * @param array<string, mixed> $args Category arguments.
	 * @return void
	 */
	public function add_category( $id, $args ) {}
}
