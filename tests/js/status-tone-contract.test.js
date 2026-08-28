/**
 * 🔴 Must be the FIRST docblock in the file — Jest reads the pragma only
 * there. See price-converter.test.js for the same note.
 *
 * @jest-environment node
 */

/**
 * Guard: every `tone` value the admin app can hand to
 * `mhmcs-status--${ tone }` must have a matching CSS rule.
 *
 * Found by review, not by any existing test: freshness.js's
 * formatNextSync() used 'warning'/'info' where freshnessMessage() used
 * 'warn'/'ok'/'muted' for the same design concept, and style.css only ever
 * defined rules for the second vocabulary. The status dot silently lost its
 * `::before` background for two states, for months, because nothing
 * compared what the JS could emit against what the CSS could render — the
 * jest suite asserted the tone STRING, never that it was a renderable one
 * (tests/js/next-sync.test.js:102,113 pinned 'warning'/'info' without ever
 * touching style.css).
 *
 * Static text extraction, not module execution: this project's jest.config
 * runs plain Node with no Babel (see next-sync.test.js's loadFreshness()
 * comment for why executing these ES modules needs a sandboxed eval), but a
 * producer/consumer name comparison never needs to run the code — reading
 * both files as text and regexing out the tokens is enough, and avoids
 * needing to load two files (freshness.js, ManageCurrencies.jsx) through
 * that sandbox for a check that isn't about behaviour.
 *
 * 🔴 KNOWN BLIND SPOT — declared, not fixed: this guard extracts only the
 * `tone: '<x>'` object-literal form. ManageCurrencies.jsx:191 assigns a tone
 * through a ternary instead — `const tone = pill && 'warn' === pill.tone ?
 * 'warn' : 'ok';` — and neither branch's string is written as `tone: '…'`,
 * so this scan does not see it. It is covered TODAY only because 'warn' and
 * 'ok' both also appear as `tone:` literals elsewhere in freshness.js
 * (lines 134/138/145 and 153) — a coincidence of the current code, not a
 * property this guard enforces. A tone introduced solely through a ternary
 * or any other non-literal expression can still slip past this test.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const STYLE_PATH = path.join(
	__dirname,
	'..',
	'..',
	'admin-app',
	'src',
	'style.css'
);

const SOURCE_PATHS = [
	path.join( __dirname, '..', '..', 'admin-app', 'src', 'lib', 'freshness.js' ),
	path.join(
		__dirname,
		'..',
		'..',
		'admin-app',
		'src',
		'components',
		'tabs',
		'ManageCurrencies.jsx'
	),
];

/**
 * Every `.mhmcs-status--<x>` rule name style.css defines.
 *
 * @param {string} css Raw stylesheet text.
 * @return {Set<string>} Defined tone names.
 */
function extractDefinedTones( css ) {
	const defined = new Set();
	const pattern = /\.mhmcs-status--([a-zA-Z0-9_-]+)/g;
	let match;

	while ( ( match = pattern.exec( css ) ) !== null ) {
		defined.add( match[ 1 ] );
	}

	return defined;
}

/**
 * Every `tone: '<x>'` object-literal value a source file emits.
 *
 * @param {string} source Raw JS/JSX text.
 * @return {Set<string>} Emitted tone names.
 */
function extractEmittedTones( source ) {
	const emitted = new Set();
	const pattern = /tone:\s*'([a-zA-Z0-9_-]+)'/g;
	let match;

	while ( ( match = pattern.exec( source ) ) !== null ) {
		emitted.add( match[ 1 ] );
	}

	return emitted;
}

describe( 'status tone contract (style.css <-> tone emitters)', () => {
	const css = fs.readFileSync( STYLE_PATH, 'utf8' );
	const definedTones = extractDefinedTones( css );

	const emittedTones = new Set();
	for ( const sourcePath of SOURCE_PATHS ) {
		const source = fs.readFileSync( sourcePath, 'utf8' );
		for ( const tone of extractEmittedTones( source ) ) {
			emittedTones.add( tone );
		}
	}

	it( 'found at least one defined .mhmcs-status--<x> rule (scan is not blind)', () => {
		expect( definedTones.size ).toBeGreaterThan( 0 );
	} );

	it( 'found at least one emitted tone: \'<x>\' literal (scan is not blind)', () => {
		expect( emittedTones.size ).toBeGreaterThan( 0 );
	} );

	it( 'has a CSS rule for every tone the admin app can emit', () => {
		const unstyled = [ ...emittedTones ].filter(
			( tone ) => ! definedTones.has( tone )
		);

		if ( unstyled.length > 0 ) {
			throw new Error(
				`Tone(s) emitted with no matching CSS rule: ${ unstyled.join(
					', '
				) }. style.css defines: ${ [ ...definedTones ]
					.sort()
					.join( ', ' ) }.`
			);
		}

		expect( unstyled ).toEqual( [] );
	} );
} );
