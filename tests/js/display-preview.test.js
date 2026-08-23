/**
 * 🔴 Must be the FIRST docblock in the file — Jest reads the pragma only
 * there. See freshness.test.js and price-converter.test.js for the same
 * note.
 *
 * @jest-environment node
 */

/**
 * buildPreviewRows() must show the list the visitor will actually see.
 *
 * The original pin for this (tests/Unit/Compliance/DisplayPreviewFidelityTest.php,
 * from the task brief this module was extracted to satisfy) asserted only
 * that the substring 'baseSymbol' appears somewhere in DisplayOptions.jsx.
 * That shape has been defeated three times in this project already — twice
 * by a hidden string literal (in a comment or an unrelated branch) and once
 * by a hardcoded value that satisfied the grep while doing the wrong thing.
 * A hardcoded `{ code: 'USD', symbol: '$' }` prepended to the preview would
 * make that pin pass while being wrong for every shop whose base currency is
 * not USD.
 *
 * This suite instead RUNS buildPreviewRows() and asserts on its return value:
 * the base currency first, no duplicate if it also appears in the array,
 * disabled currencies excluded, the base's own symbol coming through, and a
 * currency with no stored symbol not blanking the row.
 *
 * WHY SOURCE LOADED AS TEXT, NOT `require`d OR `import`ed
 * --------------------------------------------------------
 * display-preview.js is a single ES module written for webpack: it uses
 * `export const`. This project's Jest config runs a plain Node environment
 * with no ESM/Babel transform wired in — see jest.config.js and
 * freshness.test.js, which loads its target script the same way, for the
 * same reason. Unlike freshness.js this module imports nothing from
 * `@wordpress/i18n`, so only the `export` keyword needs stripping.
 *
 * @package MhmCurrencySwitcher
 */

const fs = require( 'fs' );
const path = require( 'path' );

const SOURCE_PATH = path.join(
	__dirname,
	'..',
	'..',
	'admin-app',
	'src',
	'lib',
	'display-preview.js'
);

/**
 * Load display-preview.js as a plain script, turning its `export const`
 * declaration into a local a trailing return statement can see.
 *
 * A missing or renamed export makes the trailing `return { buildPreviewRows }`
 * throw a ReferenceError at load time — this fails LOUDLY (every test in the
 * suite errors out) rather than silently returning `undefined` and letting
 * assertions on `undefined` pass by accident.
 *
 * @return {Object} { buildPreviewRows }
 */
function loadDisplayPreview() {
	const source = fs.readFileSync( SOURCE_PATH, 'utf8' );

	const body = source.replace( /export const/g, 'const' );

	// eslint-disable-next-line no-new-func -- deliberate sandboxed eval, see
	// the file-level comment above for why `import`/`require` do not apply.
	const sandbox = new Function(
		`${ body }
		return { buildPreviewRows };`
	);

	return sandbox();
}

const { buildPreviewRows } = loadDisplayPreview();

describe( 'buildPreviewRows()', () => {
	it( 'puts the base currency first, exactly as Switcher::build_options_list() does', () => {
		const rows = buildPreviewRows(
			[
				{ code: 'EUR', enabled: true, format: { symbol: '€' } },
				{ code: 'GBP', enabled: true, format: { symbol: '£' } },
			],
			'USD',
			'$'
		);

		expect( rows[ 0 ] ).toEqual( { code: 'USD', symbol: '$' } );
		expect( rows.map( ( r ) => r.code ) ).toEqual( [
			'USD',
			'EUR',
			'GBP',
		] );
	} );

	it( 'does not duplicate the base currency if it is also present in the array', () => {
		// Defensive: Switcher.php's own comment says the base currency never
		// has a row in the currency table, but the preview must not print it
		// twice if that assumption is ever violated upstream.
		const rows = buildPreviewRows(
			[
				{ code: 'USD', enabled: true, format: { symbol: '$' } },
				{ code: 'EUR', enabled: true, format: { symbol: '€' } },
			],
			'USD',
			'$'
		);

		expect( rows.filter( ( r ) => r.code === 'USD' ) ).toHaveLength( 1 );
		expect( rows.map( ( r ) => r.code ) ).toEqual( [ 'USD', 'EUR' ] );
	} );

	it( 'excludes disabled currencies', () => {
		const rows = buildPreviewRows(
			[
				{ code: 'EUR', enabled: true, format: { symbol: '€' } },
				{ code: 'GBP', enabled: false, format: { symbol: '£' } },
			],
			'USD',
			'$'
		);

		expect( rows.map( ( r ) => r.code ) ).toEqual( [ 'USD', 'EUR' ] );
	} );

	it( "carries the base currency's own symbol through, not a currency-table lookup", () => {
		// The base currency has no row in `currencies` to look a symbol up
		// from — window.mhmCsAdmin.baseSymbol is the only source there is.
		const rows = buildPreviewRows( [], 'TRY', '₺' );

		expect( rows[ 0 ] ).toEqual( { code: 'TRY', symbol: '₺' } );
	} );

	it( 'does not blank a row whose currency has no stored symbol', () => {
		const rows = buildPreviewRows(
			[ { code: 'EUR', enabled: true, format: {} } ],
			'USD',
			'$'
		);

		expect( rows[ 1 ] ).toEqual( { code: 'EUR', symbol: '' } );
	} );

	it( 'returns an empty-symbol base row when baseSymbol is not supplied', () => {
		const rows = buildPreviewRows( [], 'USD', '' );

		expect( rows[ 0 ] ).toEqual( { code: 'USD', symbol: '' } );
	} );
} );

describe( 'buildPreviewRows() — export shape (mutation drill)', () => {
	it( 'documents the mutation that must turn the suite red loudly, not silently', () => {
		// The actual proof is manual and outside CI: rename the export in
		// admin-app/src/lib/display-preview.js (e.g. `buildPreviewRows` to
		// `buildRows`) and re-run `npm run test:js`. loadDisplayPreview()'s
		// sandboxed `return { buildPreviewRows }` must throw a ReferenceError
		// at load time — every test above must ERROR, not silently receive
		// `undefined` and pass by calling `undefined(...)` in a way Jest
		// happens to swallow.
		expect( typeof buildPreviewRows ).toBe( 'function' );
	} );
} );
