/**
 * 🔴 Must be the FIRST docblock in the file — Jest reads the pragma only
 * there. See price-converter.test.js for the same note.
 *
 * @jest-environment node
 */

/**
 * The Advanced tab names a RECURRENCE ("Twice daily") and, until now, stopped
 * there. A shop owner reading that had no way to tell 02:00 from 14:00, nor
 * whether an event was scheduled at all — the reason this line exists.
 *
 * Three things are pinned here, and each one is a way the line could lie:
 *
 * 1. The absolute time is the one PHP formatted. wp_next_scheduled() answers a
 *    UTC timestamp and the shop's timezone is a WordPress option, so anything
 *    formatted in the browser would show the VISITOR's zone. The test asserts
 *    the `formatted` string is passed through untouched rather than rebuilt.
 *
 * 2. "Interval set, but nothing scheduled" is its own state. It is a real
 *    condition — a cron event can be cleared by another plugin, or lost when a
 *    schedule name disappears — and silently rendering nothing would leave the
 *    shop believing rates update when they do not.
 *
 * 3. Manual is silent. The radio already reads "Manual only"; a second line
 *    saying the same thing is noise, and the empty state is what the caller
 *    branches on.
 */

/*
 * Loaded as text, not imported: jest.config.js runs plain Node with no Babel,
 * and freshness.js is an ES module that imports @wordpress/i18n. Same approach
 * as freshness.test.js — see that file's header for the full reasoning.
 */
const fs = require( 'fs' );
const path = require( 'path' );

function loadFreshness() {
	const source = fs.readFileSync(
		path.join( __dirname, '..', '..', 'admin-app', 'src', 'lib', 'freshness.js' ),
		'utf8'
	);

	const body = source
		.replace( /import\s*\{[^}]*\}\s*from\s*'@wordpress\/i18n';/, '' )
		.replace( /export const/g, 'const' );

	// eslint-disable-next-line no-new-func -- deliberate sandboxed eval.
	const sandbox = new Function(
		'__',
		'_n',
		'sprintf',
		`${ body }
		return { formatNextSync };`
	);

	const stubUnderscore = ( text ) => text;
	const stubN = ( single, plural, count ) => ( 1 === count ? single : plural );

	/*
	 * 🔴 Handles POSITIONAL placeholders (%1$s), not only %s/%d.
	 *
	 * The stub copied from freshness.test.js only understood /%[ds]/, so a
	 * format string using %1$s came back with its placeholders intact — and
	 * an assertion looking for the substituted value failed against a
	 * function that was actually correct. A stub that cannot do what the real
	 * @wordpress/i18n sprintf does is not a stub, it is a second bug.
	 */
	const stubSprintf = ( format, ...args ) => {
		let out = format.replace( /%(\d+)\$[ds]/g, ( _match, index ) =>
			String( args[ Number( index ) - 1 ] )
		);

		return args.reduce(
			( str, arg ) => str.replace( /%[ds]/, String( arg ) ),
			out
		);
	};

	return sandbox( stubUnderscore, stubN, stubSprintf );
}

const { formatNextSync } = loadFreshness();

describe( 'formatNextSync', () => {
	const NOW = 1787000000;

	it( 'returns null on the manual interval, whatever is scheduled', () => {
		expect(
			formatNextSync(
				{ time: NOW + 3600, formatted: '25 Aug 2026 02:00' },
				'manual',
				NOW
			)
		).toBeNull();
	} );

	it( 'reports a missing schedule when the interval says one should exist', () => {
		const line = formatNextSync( null, 'twicedaily', NOW );

		expect( line ).not.toBeNull();
		expect( line.tone ).toBe( 'warn' );
		expect( line.text ).toMatch( /no update is scheduled|bulunamadı/i );
	} );

	it( 'carries the PHP-formatted absolute time through verbatim', () => {
		const line = formatNextSync(
			{ time: NOW + 7200, formatted: '25 Aug 2026 02:00' },
			'twicedaily',
			NOW
		);

		expect( line.tone ).toBe( 'muted' );
		expect( line.text ).toContain( '25 Aug 2026 02:00' );
	} );

	it( 'says the wait in relative terms alongside the absolute time', () => {
		const line = formatNextSync(
			{ time: NOW + 7200, formatted: '25 Aug 2026 02:00' },
			'twicedaily',
			NOW
		);

		expect( line.text ).toMatch( /2 hours|2 saat/i );
	} );

	/**
	 * WordPress cron is VISIT-TRIGGERED. Promising a clock time without that
	 * caveat is a claim the software cannot keep: on a site with no traffic the
	 * event simply waits. The caveat is part of the sentence, not a footnote,
	 * so it cannot be dropped by a later edit without this failing.
	 */
	it( 'never promises the time without saying a visit triggers it', () => {
		const line = formatNextSync(
			{ time: NOW + 7200, formatted: '25 Aug 2026 02:00' },
			'twicedaily',
			NOW
		);

		expect( line.text ).toMatch( /visit|ziyaret/i );
	} );

	/**
	 * An overdue event is the normal state of a low-traffic shop, not an error.
	 * Rendering "in -3 hours" would read as a bug to the shop owner.
	 */
	it( 'reads an overdue event as due now rather than as negative time', () => {
		const line = formatNextSync(
			{ time: NOW - 7200, formatted: '24 Aug 2026 22:00' },
			'twicedaily',
			NOW
		);

		expect( line.text ).not.toMatch( /-/ );
		expect( line.text ).toMatch( /due|next visit|beklemede|ziyaret/i );
	} );
} );
