/**
 * 🔴 Must be the FIRST docblock in the file — Jest reads the pragma only
 * there. See price-converter.test.js for the same note.
 *
 * @jest-environment node
 */

/**
 * freshnessState() must return the DOCUMENTED state for every input, in the
 * DOCUMENTED ORDER — not merely "eventually return one of five strings".
 *
 * The original pin for this (tests/Unit/Compliance/FreshnessStatesTest.php,
 * ported verbatim from the task brief that specified this module) only
 * grepped the source text for the substring "MANUAL_ONLY". That string is
 * present in the file regardless of which branch it sits in, so it proved
 * nothing about ORDER — and the states are deliberately overlapping
 * conditions where order is the whole specification (a manual-only shop
 * that has also synced satisfies both MANUAL_ONLY and FRESH; only the first
 * check that runs decides which one wins).
 *
 * Proven dead by mutation: swapping the MANUAL_ONLY and NO_RECORD blocks in
 * freshnessState() left that source-grep pin green, because both branches'
 * bodies still contain the word "MANUAL_ONLY" somewhere in the function.
 *
 * This file replaces that pin with real executions of the function, feeding
 * it inputs and asserting the state it returns — including the two
 * overlapping pairs that make the ordering load-bearing.
 *
 * WHY SOURCE LOADED AS TEXT, NOT `require`d OR `import`ed
 * --------------------------------------------------------
 * freshness.js is a single ES module written for webpack: it uses `export
 * const` and imports `@wordpress/i18n`, a package this project does not
 * install as a plain node dependency (it lives inside `@wordpress/scripts`'
 * own webpack pipeline). This project's Jest config runs a plain Node
 * environment with no ESM/Babel transform wired in — see jest.config.js and
 * price-converter.test.js, which loads its target script the same way, for
 * the same reason. `freshnessState()` never calls `_n()` or `sprintf()` (only
 * `formatHumanAge()` and `formatRowUpdatedAgo()` do, and neither is under
 * test here), so both are stubbed rather than resolved.
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
	'freshness.js'
);

/**
 * Load freshness.js as a plain script, stubbing its one import and turning
 * its `export const` declarations into locals a trailing return statement
 * can see.
 *
 * @return {Object} { FRESHNESS, STALE_AFTER, freshnessState, formatHumanAge, formatRowUpdatedAgo }
 */
function loadFreshness() {
	const source = fs.readFileSync( SOURCE_PATH, 'utf8' );

	const body = source
		.replace( /import\s*\{[^}]*\}\s*from\s*'@wordpress\/i18n';/, '' )
		.replace( /export const/g, 'const' );

	// eslint-disable-next-line no-new-func -- deliberate sandboxed eval, see
	// the file-level comment above for why `import`/`require` do not apply.
	const sandbox = new Function(
		'_n',
		'sprintf',
		`${ body }
		return { FRESHNESS, STALE_AFTER, freshnessState, formatHumanAge, formatRowUpdatedAgo };`
	);

	const stubN = ( single, plural, count ) => ( 1 === count ? single : plural );
	const stubSprintf = ( format, ...args ) =>
		args.reduce( ( str, arg ) => str.replace( /%[ds]/, arg ), format );

	return sandbox( stubN, stubSprintf );
}

const { FRESHNESS, freshnessState } = loadFreshness();

const AUTO = { rate: { type: 'auto' } };
const MANUAL = { rate: { type: 'manual' } };

describe( 'freshnessState()', () => {
	it( 'is MANUAL_ONLY for a manual-only shop with no sync record — not NO_RECORD', () => {
		// The first overlapping pair the ordering exists for: every condition
		// for NO_RECORD is also true here (no automatic currency means the
		// question is moot, but a naive unordered check would still see
		// "no lastSync" and answer NO_RECORD).
		expect(
			freshnessState( [ MANUAL ], null, 'USD', 'daily', 1000 )
		).toBe( FRESHNESS.MANUAL_ONLY );
	} );

	it( 'is MANUAL_ONLY for a manual-only shop that HAS synced — not FRESH', () => {
		// The second overlapping pair: a recent, correctly-based sync record
		// is present, which is exactly what FRESH requires — except there is
		// no automatic currency for that sync to be a claim about.
		expect(
			freshnessState(
				[ MANUAL ],
				{ time: 900, base: 'USD' },
				'USD',
				'daily',
				1000
			)
		).toBe( FRESHNESS.MANUAL_ONLY );
	} );

	it( 'checks every currency, not just the first — a mixed list is not MANUAL_ONLY', () => {
		expect(
			freshnessState( [ MANUAL, AUTO ], null, 'USD', 'daily', 1000 )
		).toBe( FRESHNESS.NO_RECORD );
	} );

	it( 'is NO_RECORD when an automatic currency exists and nothing was ever synced', () => {
		expect(
			freshnessState( [ AUTO ], null, 'USD', 'daily', 1000 )
		).toBe( FRESHNESS.NO_RECORD );
	} );

	it( 'is NO_RECORD when the sync record exists but carries no time', () => {
		expect(
			freshnessState( [ AUTO ], { base: 'USD' }, 'USD', 'daily', 1000 )
		).toBe( FRESHNESS.NO_RECORD );
	} );

	it( 'is STALE_BASE when the recorded sync was against a different base', () => {
		expect(
			freshnessState(
				[ AUTO ],
				{ time: 999, base: 'EUR' },
				'USD',
				'daily',
				1000
			)
		).toBe( FRESHNESS.STALE_BASE );
	} );

	// A non-zero anchor on purpose: `time: 0` would make `! lastSync.time`
	// true (0 is falsy in JS) and every case below would silently collapse
	// to NO_RECORD instead of exercising STALE_AGE/FRESH at all. A real sync
	// timestamp is never epoch zero, so an arbitrary realistic Unix time is
	// the honest stand-in.
	const SYNC_TIME = 1700000000;

	it( 'is STALE_AGE once the interval\'s double-tick window has passed', () => {
		expect(
			freshnessState(
				[ AUTO ],
				{ time: SYNC_TIME, base: 'USD' },
				'USD',
				'hourly',
				SYNC_TIME + 2 * 3600 + 1
			)
		).toBe( FRESHNESS.STALE_AGE );
	} );

	it( 'is FRESH inside the interval\'s double-tick window', () => {
		expect(
			freshnessState(
				[ AUTO ],
				{ time: SYNC_TIME, base: 'USD' },
				'USD',
				'hourly',
				SYNC_TIME + 2 * 3600 - 1
			)
		).toBe( FRESHNESS.FRESH );
	} );

	it( 'falls back to the manual stale window for an unrecognised interval', () => {
		// STALE_AFTER has no entry for 'twicedaily'-typo'd or legacy values;
		// freshnessState() falls back to STALE_AFTER.manual (48h) rather than
		// throwing or treating an unknown interval as "never stale".
		expect(
			freshnessState(
				[ AUTO ],
				{ time: SYNC_TIME, base: 'USD' },
				'USD',
				'not-a-real-interval',
				SYNC_TIME + 48 * 3600 + 1
			)
		).toBe( FRESHNESS.STALE_AGE );
	} );
} );

describe( 'freshnessState() — ordering is load-bearing (mutation drill)', () => {
	it( 'documents the mutation that must turn the suite red', () => {
		// This test intentionally asserts nothing about behaviour — it exists
		// so the drill below is discoverable from inside the suite. The
		// actual proof is manual and outside CI: swap the MANUAL_ONLY block
		// and the NO_RECORD block in admin-app/src/lib/freshness.js and
		// re-run `npm run test:js`. The two tests above named "not NO_RECORD"
		// and "not FRESH" must fail — if they do not, this suite is not
		// exercising order and needs to be fixed again, the same way the old
		// source-grep pin was.
		expect( true ).toBe( true );
	} );
} );
