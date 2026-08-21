<?php
/**
 * Compliance test: every key the format drawer writes must be a key the
 * sanitiser recognises.
 *
 * `RestAPI::ensure_currency_format()` is a whitelist keyed by exact field
 * name — `symbol`, `position`, `decimals`, `decimal_sep`, `thousand_sep`.
 * A key it does not recognise is not rejected and it produces no error: it
 * is silently ignored, and the field is filled from whatever default the
 * sanitiser uses for an ABSENT key. A typo in the drawer's field name (say
 * `decimalSep` for `decimal_sep`) would therefore lose the admin's input
 * with nothing anywhere to say so — the field shows what was typed until
 * the next reload, and what is actually stored is the WooCommerce default.
 *
 * This is the same defect class
 * `RestAPITest::test_advanced_settings_jsx_keys_and_values_match_the_sanitiser()`
 * exists to catch for the Advanced tab, adapted to this tab's shape: the
 * Advanced tab's controls call a flat `update( 'key', value )` that writes a
 * top-level setting; this tab's controls call
 * `handleFormatChange( index, 'field', value )`, writing into
 * `currency.format.field` on one row instead.
 *
 * WHY THIS IS A SOURCE-READING TEST
 * ----------------------------------
 * This repository has no React test runner — `@wordpress/scripts` is the
 * only JS devDependency and `@testing-library/react` is not installed,
 * verified by resolution — so a rendered-component assertion is not
 * available here. `PanelUnsavedChangesTest` documents the same limitation
 * for the same file.
 *
 * KNOWN WEAKNESS, INHERITED ON PURPOSE, AND CLOSED HERE
 * -------------------------------------------------------
 * `RestAPITest::parse_jsx_controls()` silently returns an empty array for a
 * JSX shape it cannot parse, so a control that moved to a shape the regex
 * no longer matches simply vanishes from that pin instead of failing it.
 * The extractor below inherits the same regex-based technique, but this
 * test does not stop at "found something": it asserts the exact ordered
 * list of keys it expects (`EXPECTED_KEYS`). If the extractor's match count
 * drops — because a control moved, was renamed, or the call shape changed
 * — the comparison against `EXPECTED_KEYS` fails loudly instead of quietly
 * pinning nothing.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use MhmCurrencySwitcher\Admin\RestAPI;
use MhmCurrencySwitcher\Core\Converter;
use MhmCurrencySwitcher\Core\CurrencyStore;
use MhmCurrencySwitcher\Core\RateProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class FormatDrawerKeyParityTest
 */
class FormatDrawerKeyParityTest extends TestCase {

	/**
	 * Every field the drawer is expected to write, in the order
	 * `ManageCurrencies.jsx` declares its controls. This is the pin's own
	 * expectation — checked against what the extractor below actually
	 * finds, not merely a fallback for when extraction comes up empty.
	 *
	 * @var string[]
	 */
	private const EXPECTED_KEYS = array(
		'symbol',
		'position',
		'decimals',
		'decimal_sep',
		'thousand_sep',
	);

	/**
	 * Start every test from an empty option store and no fired actions.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['__mhmcs_test_options']     = array();
		$GLOBALS['__mhmcs_test_did_actions'] = array();
	}

	/**
	 * Leave no options or fired actions behind for neighbouring tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['__mhmcs_test_options']     = array();
		$GLOBALS['__mhmcs_test_did_actions'] = array();

		parent::tearDown();
	}

	/**
	 * Build a RestAPI instance backed by an empty currency store.
	 *
	 * @return RestAPI
	 */
	private function create_api(): RestAPI {
		$store = new CurrencyStore();
		$store->set_data( 'USD', array() );

		return new RestAPI( $store, new Converter( $store ), new RateProvider() );
	}

	/**
	 * Every literal field name `ManageCurrencies.jsx` passes as the second
	 * argument of `handleFormatChange( index, '<field>', value )`, in
	 * source order.
	 *
	 * @return string[]
	 */
	private function drawer_format_keys(): array {
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/admin-app/src/components/tabs/ManageCurrencies.jsx'
		);

		$this->assertIsString( $source, 'ManageCurrencies.jsx must be readable.' );

		preg_match_all(
			"/handleFormatChange\(\s*index,\s*'([a-zA-Z_]+)'/",
			$source,
			$matches
		);

		return $matches[1];
	}

	/**
	 * 🔴 Drift lock. Every field the drawer writes must survive
	 * `ensure_currency_format()` under the SAME spelling — and sent ALONE,
	 * the way a currency whose admin has edited only one field would send
	 * it, so a misspelled key cannot hide behind a sibling field that
	 * happens to carry the right value through some other path.
	 *
	 * A real production bug in this plugin was exactly this drift on a
	 * neighbouring tab: `show_flag` written / `show_flags` expected, a
	 * toggle that silently did nothing (see the Advanced-tab sibling pin).
	 *
	 * @return void
	 */
	public function test_drawer_format_keys_are_recognised_by_the_sanitiser(): void {
		$found = $this->drawer_format_keys();

		$this->assertSame(
			self::EXPECTED_KEYS,
			$found,
			'ManageCurrencies.jsx no longer calls handleFormatChange() with exactly the expected '
				. 'field names, in order (found: [' . implode( ', ', $found ) . ']). If a format '
				. 'field was added, renamed, or removed, update EXPECTED_KEYS to match here — do '
				. 'not delete this pin.'
		);

		// One value per field that survives ensure_currency_format() ONLY
		// if the sanitiser recognises the exact key name. Each is chosen to
		// differ from every default the sanitiser would otherwise fill in
		// for an absent key, so a silently-dropped key produces a visibly
		// different, wrong result instead of an accidental match.
		$sentinel = array(
			'symbol'       => 'Ω¤',
			'position'     => 'right_space',
			'decimals'     => 3,
			'decimal_sep'  => 'X',
			'thousand_sep' => 'Y',
		);

		foreach ( $found as $key ) {
			$api     = $this->create_api();
			$request = new \WP_REST_Request();
			$request->set_json_params(
				array(
					'base_currency' => 'USD',
					'currencies'    => array(
						array(
							'code'   => 'EUR',
							'format' => array( $key => $sentinel[ $key ] ),
						),
					),
				)
			);

			$saved = $api->save_currencies( $request )->get_data()['currencies'][0]['format'];

			$this->assertSame(
				$sentinel[ $key ],
				$saved[ $key ] ?? null,
				sprintf(
					'The drawer writes currency.format.%1$s, but the sanitiser did not preserve '
						. 'it — sent %2$s, got back %3$s. A spelling mismatch here silently '
						. 'discards whatever the admin typed into this field, with no error '
						. 'anywhere.',
					$key,
					wp_json_encode( $sentinel[ $key ] ),
					wp_json_encode( $saved[ $key ] ?? null )
				)
			);
		}
	}
}
