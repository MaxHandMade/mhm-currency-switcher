/**
 * 🔴 Must be the FIRST docblock in the file — Jest reads the pragma only
 * there. Unlike this suite's siblings this one needs a DOM: it drives real
 * react-dom, because the defect it pins lives in React's controlled-input
 * reconciliation, not in our arithmetic.
 *
 * @jest-environment jsdom
 */

/**
 * The numeric fields of the currency panel must accept a rate below 1.
 *
 * WHAT BROKE
 * ----------
 * v1.2.0 shipped `value={ currency.rate?.value || '' }` against
 * `value: parseFloat( value ) || 0`. Typing "0.0211" into that pair leaves
 * 211 in the field — measured in Chrome on a real store, see
 * admin-app/src/utils/numeric-field.js for the full record. Every foreign
 * rate on a TRY-based store is below 1, so the panel's central field could
 * not take a single realistic value, and it failed silently.
 *
 * WHY THIS SUITE RUNS REACT INSTEAD OF ASSERTING ON THE HELPERS
 * -------------------------------------------------------------
 * The helpers in isolation are three lines and prove nothing: the defect only
 * appears when the value prop, the change handler and react-dom's number-input
 * branch run as one loop. So `typeInto()` below renders a real controlled
 * `<input type="number">` with react-dom and dispatches one `input` event per
 * character, and react-dom's own reconciliation decides what the field ends up
 * holding.
 *
 * WHAT IS REAL HERE AND WHAT IS MODELLED
 * ---------------------------------------
 * Real: react-dom 18.3.1's commit, including the number-input branch of
 * `updateWrapper` whose LOOSE `node.value != value` comparison is the reason a
 * half-typed "0.0" survives while a 0-with-empty-prop does not.
 *
 * Modelled: the browser's edit buffer. A number input keeps the raw text the
 * user typed but reports `''` from `.value` whenever that text is not a valid
 * floating-point number ("0." reports '', ".0" reports ".0" — measured in
 * Chrome via validity.badInput and reproduced identically by jsdom). jsdom has
 * no editing at all, so `typeInto()` keeps the buffer itself, hands the field
 * each new buffer (jsdom then applies the same sanitisation Chrome does), and
 * treats a write by React as replacing the buffer — which is what a JS
 * assignment to `.value` does to a real field being edited.
 *
 * That model is not guesswork: the per-keystroke trace it reproduces was read
 * off Chrome on the live panel (0 → field '', badInput false; . → '', badInput
 * true; 0 → '', badInput false; then 2, 1, 1 building 211).
 *
 * The suite carries its own negative control: `LEGACY_CONTRACT` is the pair
 * v1.2.0 shipped, and a test asserts it still produces 211 — the number
 * Chrome produced. If a future change to this harness stops reproducing the
 * failure, that test goes green-when-it-should-be-red and says the gate has
 * gone blind.
 *
 * @package MhmCurrencySwitcher
 */

const fs = require( 'fs' );
const path = require( 'path' );
const React = require( 'react' );
const ReactDOM = require( 'react-dom/client' );
const { act } = React;

// react-dom 18.3 refuses to flush updates inside act() without this, and warns
// loudly instead of failing — which would leave every assertion below reading
// a state that never advanced.
global.IS_REACT_ACT_ENVIRONMENT = true;

const SOURCE_PATH = path.join(
	__dirname,
	'..',
	'..',
	'admin-app',
	'src',
	'utils',
	'numeric-field.js'
);

const PANEL_PATH = path.join(
	__dirname,
	'..',
	'..',
	'admin-app',
	'src',
	'components',
	'tabs',
	'ManageCurrencies.jsx'
);

/**
 * Load numeric-field.js as a plain script, turning its `export const`
 * declarations into locals the trailing return can see.
 *
 * Same technique, and the same reason, as display-preview.test.js: these
 * sources are ES modules written for webpack and this project's Jest config
 * wires no ESM/Babel transform. A renamed export makes the trailing return
 * throw a ReferenceError at load time — loud, not silently undefined.
 *
 * @return {Object} { toFieldValue, parseFieldValue }
 */
function loadNumericField() {
	const source = fs.readFileSync( SOURCE_PATH, 'utf8' );
	const body = source.replace( /export const/g, 'const' );

	// eslint-disable-next-line no-new-func -- deliberate sandboxed eval; see
	// the note above for why `import`/`require` do not apply here.
	const sandbox = new Function(
		`${ body }
		return { toFieldValue, parseFieldValue };`
	);

	return sandbox();
}

const { toFieldValue, parseFieldValue } = loadNumericField();

/**
 * The pair v1.2.0 shipped. Kept only as this suite's negative control.
 */
const LEGACY_CONTRACT = {
	toFieldValue: ( value ) => value || '',
	parseFieldValue: ( raw ) => parseFloat( raw ) || 0,
};

const FIXED_CONTRACT = { toFieldValue, parseFieldValue };

/**
 * Type `text` one character at a time into a controlled number input built
 * from `contract`, and report what the state ended up holding.
 *
 * @param {string} text     Characters to type.
 * @param {Object} contract { toFieldValue, parseFieldValue }.
 * @param {*}      initial  Starting state value.
 * @return {{ state: *, displayed: string }} Final state and field text.
 */
function typeInto( text, contract, initial = '' ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );

	let current = initial;

	const Field = () => {
		const [ value, setValue ] = React.useState( initial );

		current = value;

		return React.createElement( 'input', {
			type: 'number',
			value: contract.toFieldValue( value ),
			onChange: ( event ) =>
				setValue( contract.parseFieldValue( event.target.value ) ),
		} );
	};

	const root = ReactDOM.createRoot( container );

	act( () => {
		root.render( React.createElement( Field ) );
	} );

	const input = container.querySelector( 'input' );

	// The native setter, then a bubbling `input` event: this is how a real
	// keystroke reaches React, whose onChange rides the delegated listener.
	// Going through `input.value = x` alone would leave React's value tracker
	// thinking nothing changed and no onChange would fire at all.
	const nativeSetter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;

	// The text the user can see. `.value` is NOT this: a number input reports
	// '' for any buffer that is not a valid floating-point number.
	let buffer = String( contract.toFieldValue( initial ) );

	for ( const character of text ) {
		buffer += character;

		let reported;

		act( () => {
			nativeSetter.call( input, buffer );

			// What the field kept of the buffer — jsdom applies the same
			// sanitisation the browser does.
			reported = input.value;

			input.dispatchEvent(
				new window.Event( 'input', { bubbles: true } )
			);
		} );

		if ( input.value !== reported ) {
			// React wrote over the field during the commit. A write to
			// `.value` replaces what the user was editing, so the next
			// keystroke appends to React's text — this is how "0.0211"
			// became 211.
			buffer = input.value;
		}
	}

	const displayed = buffer;

	act( () => {
		root.unmount();
	} );

	container.remove();

	return { state: current, displayed };
}

describe( 'numeric field contract', () => {
	it( 'keeps a stored 0 as 0 instead of blanking the field', () => {
		expect( toFieldValue( 0 ) ).toBe( 0 );
	} );

	it( 'passes an absent value through as an empty field', () => {
		expect( toFieldValue( undefined ) ).toBe( '' );
		expect( toFieldValue( null ) ).toBe( '' );
	} );

	it( 'reads an empty field as empty, not as 0', () => {
		// 0 here is what force-writes the field mid-edit; see the module.
		expect( parseFieldValue( '' ) ).toBe( '' );
	} );

	it( 'reads a number back as a number', () => {
		expect( parseFieldValue( '0.0211' ) ).toBe( 0.0211 );
		expect( parseFieldValue( '0' ) ).toBe( 0 );
		expect( parseFieldValue( '1.5' ) ).toBe( 1.5 );
	} );
} );

describe( 'typing a rate into a controlled number input', () => {
	it( 'accepts a rate below 1', () => {
		expect( typeInto( '0.0211', FIXED_CONTRACT ).state ).toBe( 0.0211 );
	} );

	it( 'accepts the fee and rounding shapes below 1 too', () => {
		expect( typeInto( '0.05', FIXED_CONTRACT ).state ).toBe( 0.05 );
		expect( typeInto( '0.99', FIXED_CONTRACT ).state ).toBe( 0.99 );
	} );

	it( 'still accepts a rate above 1', () => {
		expect( typeInto( '1.5', FIXED_CONTRACT ).state ).toBe( 1.5 );
		expect( typeInto( '35', FIXED_CONTRACT ).state ).toBe( 35 );
	} );

	it( 'leaves the typed text in the field, not a rewritten number', () => {
		expect( typeInto( '0.0211', FIXED_CONTRACT ).displayed ).toBe(
			'0.0211'
		);
	} );

	// NEGATIVE CONTROL — this is the shipped defect. If this test ever goes
	// green, the harness above has stopped being able to see the bug and
	// every assertion in this file is worthless.
	it( 'reproduces the v1.2.0 defect with the old contract', () => {
		expect( typeInto( '0.0211', LEGACY_CONTRACT ).state ).toBe( 211 );
		expect( typeInto( '0.05', LEGACY_CONTRACT ).state ).toBe( 5 );
	} );
} );

describe( 'the panel wires every numeric field through the contract', () => {
	const source = fs.readFileSync( PANEL_PATH, 'utf8' );

	/**
	 * Pull the `value={...}` expression that belongs to each numeric input.
	 *
	 * A grep for `toFieldValue(` would pass on a file that imports it once
	 * and forgets one field — that shape has been defeated in this project
	 * before. So this walks from each `type="number"` to the end of its
	 * TextControl and captures that control's own value prop.
	 *
	 * @return {string[]} One value expression per numeric field, in order.
	 */
	const numericValueProps = () => {
		const props = [];
		let cursor = source.indexOf( 'type="number"' );

		while ( cursor !== -1 ) {
			const controlEnd = source.indexOf( '/>', cursor );
			const control = source.slice( cursor, controlEnd );
			const valueAt = control.indexOf( 'value={' );

			props.push(
				valueAt === -1
					? '<no value prop>'
					: control.slice(
							valueAt,
							control.indexOf( '}', valueAt ) + 1
					  )
			);

			cursor = source.indexOf( 'type="number"', controlEnd );
		}

		return props;
	};

	it( 'has the number of numeric fields this suite was written against', () => {
		// Rate, fee, rounding value, rounding subtract, decimals. A sixth
		// field must be added to this pin deliberately — and by then its
		// value prop is already covered by the assertion below.
		expect( numericValueProps() ).toHaveLength( 5 );
	} );

	it( 'reads every one of them through toFieldValue()', () => {
		numericValueProps().forEach( ( prop ) => {
			expect( prop ).toContain( 'toFieldValue(' );
		} );
	} );

	it( 'leaves no `|| \'\'` value prop behind, numeric or not', () => {
		// Not just the number fields. `x || ''` erases every falsy-but-real
		// value, and the panel's format drawer has text fields where "0" is
		// a legal entry (symbol, decimal separator, thousand separator). The
		// consequence there is milder — a character that will not stay typed,
		// not a 10.000x rate — but it is the same defect, so the sweep covers
		// the class rather than the one member that hurt.
		const valueProps = [];
		let cursor = source.indexOf( 'value={' );

		while ( cursor !== -1 ) {
			valueProps.push(
				source.slice( cursor, source.indexOf( '}', cursor ) + 1 )
			);
			cursor = source.indexOf( 'value={', cursor + 1 );
		}

		valueProps.forEach( ( prop ) => {
			expect( prop ).not.toContain( "|| ''" );
		} );
	} );

	// The state side of the loop. A field wired to toFieldValue() whose
	// handler still coerces an empty reading to 0 re-opens the defect from
	// the other end: state 0 against a buffer of "0." is exactly the case
	// react-dom force-writes over.
	//
	// 🔴 The first version of this pin banned the literal string
	// `parseFloat( value ) || 0` and accepted a single `parseFieldValue(`
	// anywhere in the file. The pre-ZIP audit defeated it in a scratch copy:
	// rewriting one handler as `Number( value ) || 0` left all 13 tests
	// green. So the pin now reads each handler's OWN body.
	[
		'handleRateValueChange',
		'handleFeeValueChange',
		'handleRoundingChange',
	].forEach( ( handler ) => {
		/**
		 * The body of one change handler, from its declaration to the `};`
		 * that closes it at the component's indentation level.
		 *
		 * @return {string} Handler source.
		 */
		const body = () => {
			const start = source.indexOf( `const ${ handler } = (` );

			expect( start ).toBeGreaterThan( -1 );

			return source.slice( start, source.indexOf( '\n\t};', start ) );
		};

		it( `${ handler }() stores through parseFieldValue()`, () => {
			expect( body() ).toContain( 'parseFieldValue(' );
		} );

		it( `${ handler }() coerces nothing to zero itself`, () => {
			// `|| 0`, `?? 0`, parseFloat/parseInt/Number(...) — any of them
			// turns the empty reading of a half-typed number back into a 0.
			expect( body() ).not.toMatch( /(\|\||\?\?)\s*0\b/ );
			expect( body() ).not.toMatch( /\b(parseFloat|parseInt|Number)\s*\(/ );
		} );
	} );
} );
