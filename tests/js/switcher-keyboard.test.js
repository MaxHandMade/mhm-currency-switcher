/**
 * 🔴 Must be the FIRST docblock in the file — Jest reads the pragma only there.
 *
 * @jest-environment node
 */

/**
 * switcher.js — the currency switcher must be operable from the keyboard.
 *
 * The defect these tests lock (2026-11-04): the options were `<li role="option">`
 * with no tabindex and only a `click` listener, and the one keydown handler on
 * the page was Escape. Tab reached the button and Enter opened the list, but no
 * key could reach or pick a currency — WCAG 2.1.1 (Level A). The same script
 * drives the shortcode, the Elementor widget and the nav-menu item, so all three
 * shared it.
 *
 * Every test builds its OWN JSDOM (see jest.config.js for why). Keys are sent
 * as real KeyboardEvents to whatever element HAS focus, so a handler bound to
 * the wrong element, or focus that never moved, fails the test instead of
 * being papered over by dispatching straight at the intended target.
 *
 * @package MhmCurrencySwitcher
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );

const SCRIPT = fs.readFileSync(
	path.join( __dirname, '..', '..', 'assets', 'js', 'switcher.js' ),
	'utf8'
);

/**
 * Switcher markup as Switcher::render_switcher() emits it.
 *
 * @param {string}  id      Distinguishes two switchers on one page.
 * @param {boolean} neutral Cache-mode render: no option marked active. This
 *                          is what a first, cookieless visit to a cached page
 *                          gets — the most common state in production.
 * @return {string} HTML.
 */
function switcherHtml( id, neutral ) {
	const option = ( code, active ) =>
		'<li role="option" data-currency="' +
		code +
		'" class="mhmcs-option' +
		( active ? ' mhmcs-active' : '' ) +
		'"> <span>' +
		code +
		'</span></li>';

	return (
		'<div class="mhmcs-switcher" id="' +
		id +
		'">' +
		'<button class="mhmcs-selected" type="button" aria-expanded="false">' +
		'<span class="mhmcs-label">USD</span><span class="mhmcs-arrow" aria-hidden="true">▾</span></button>' +
		'<ul class="mhmcs-dropdown" role="listbox" aria-label="Currency">' +
		option( 'USD', ! neutral ) +
		option( 'EUR', false ) +
		option( 'GBP', false ) +
		'</ul></div>'
	);
}

/**
 * Boot a fresh page with the switcher script on it.
 *
 * @param {string} body    Extra body HTML.
 * @param {Object} options `config` overrides.
 * @return {Promise<Object>} Window, document, the currencies announced and the
 *                           element focused when the page tried to reload,
 *                           once init() ran.
 */
function boot( body, options ) {
	const reloads = [];
	const virtualConsole = new VirtualConsole();

	/*
	 * jsdom cannot navigate: location.reload() reports "Not implemented"
	 * through the virtual console, synchronously, from inside the call. That
	 * is the moment to read focus — the server-rendered path reloads the page,
	 * and whatever the script does after reload() never happens in a browser.
	 */
	virtualConsole.on( 'jsdomError', ( error ) => {
		if ( /navigation/i.test( error.message ) ) {
			reloads.push( dom.window.document.activeElement );
		}
	} );

	const dom = new JSDOM(
		'<!doctype html><html><body><a href="#" id="before">before</a>' +
			( body || switcherHtml( 'one' ) ) +
			'<a href="#" id="after">after</a></body></html>',
		{ url: 'https://shop.test/', runScripts: 'outside-only', virtualConsole }
	);
	const { window } = dom;
	const announced = [];

	window.mhmcsData = {
		config: Object.assign(
			{
				clientConversion: true,
				cookieName: 'mhmcs_currency',
				cookieDays: 30,
				baseCurrency: 'USD',
			},
			( options && options.config ) || {}
		),
	};
	window.document.addEventListener( 'mhmcs:currency-changed', ( e ) => {
		announced.push( e.detail.currency );
	} );

	window.eval( SCRIPT );

	// JSDOM is still "loading" here, so the script deferred init() to
	// DOMContentLoaded. Acting before it fires would test an unwired page
	// (measured: every assertion then reads "nothing happened", and the
	// "nothing happens" tests pass vacuously). A listener added now runs
	// after the script's own.
	const page = { window, document: window.document, announced, reloads };

	if ( 'loading' !== window.document.readyState ) {
		return Promise.resolve( page );
	}

	return new Promise( ( resolve ) => {
		window.document.addEventListener( 'DOMContentLoaded', () => resolve( page ) );
	} );
}

/**
 * Press a key on whatever has focus, as a browser would.
 *
 * @param {Object} page Booted page.
 * @param {string} key  KeyboardEvent.key value.
 * @return {boolean} Whether the default action was NOT prevented.
 */
function press( page, key ) {
	const fire = ( type ) => {
		const target = page.document.activeElement || page.document.body;
		const notPrevented = target.dispatchEvent(
			new page.window.KeyboardEvent( type, { key, bubbles: true, cancelable: true } )
		);
		return { target, notPrevented };
	};
	const clickIfButton = ( { target, notPrevented } ) => {
		if ( notPrevented && 'BUTTON' === target.tagName ) {
			target.dispatchEvent(
				new page.window.MouseEvent( 'click', { bubbles: true, detail: 0 } )
			);
		}
	};

	/*
	 * jsdom fires no activation from keys, so emulate what browsers do, and
	 * WHERE they do it: Enter activates a focused <button> on keydown, Space
	 * on KEYUP — on whatever has focus by then. That difference is the trap:
	 * a handler that moves focus to the button during Space's keydown hands
	 * the keyup to the button, which clicks and reopens the list.
	 */
	const down = fire( 'keydown' );

	if ( 'Enter' === key ) {
		clickIfButton( down );
	}

	const up = fire( 'keyup' );

	if ( ' ' === key && down.notPrevented ) {
		clickIfButton( up );
	} else if ( ' ' === key && up.target !== down.target ) {
		// Prevented keydown, but focus moved to a button before keyup. Firefox
		// still activates the button that receives the keyup (Chrome only does
		// when that button also got the keydown); emulate the stricter case.
		clickIfButton( up );
	}

	return down.notPrevented;
}

const focused = ( page ) => page.document.activeElement;
const dropdown = ( page, id = 'one' ) =>
	page.document.querySelector( '#' + id + ' .mhmcs-dropdown' );
const button = ( page, id = 'one' ) =>
	page.document.querySelector( '#' + id + ' .mhmcs-selected' );
const isOpen = ( page, id = 'one' ) =>
	dropdown( page, id ).classList.contains( 'mhmcs-open' );
const code = ( el ) => el && el.getAttribute( 'data-currency' );

describe( 'switcher keyboard operation', () => {
	test( 'options are programmatically focusable but not tab stops', async () => {
		const page = await boot();
		const options = page.document.querySelectorAll( '.mhmcs-option' );

		options.forEach( ( li ) => {
			// -1: reachable by focus(), not a Tab stop. Set by the script, not
			// the PHP renderer, so kses on WordPress < 7.1 cannot strip it.
			expect( li.getAttribute( 'tabindex' ) ).toBe( '-1' );
		} );
	} );

	test( 'Enter on the button opens the list and focuses the current option', async () => {
		const page = await boot();

		button( page ).focus();
		press( page, 'Enter' );

		expect( isOpen( page ) ).toBe( true );
		expect( button( page ).getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect( code( focused( page ) ) ).toBe( 'USD' );
	} );

	test( 'ArrowDown on the closed button opens the list too', async () => {
		const page = await boot();

		button( page ).focus();
		const notPrevented = press( page, 'ArrowDown' );

		expect( notPrevented ).toBe( false ); // The page must not scroll.
		expect( isOpen( page ) ).toBe( true );
		expect( code( focused( page ) ) ).toBe( 'USD' );
	} );

	test( 'arrows, Home and End move focus through the options and stop at the ends', async () => {
		const page = await boot();

		button( page ).focus();
		press( page, 'Enter' );

		expect( press( page, 'ArrowDown' ) ).toBe( false );
		expect( code( focused( page ) ) ).toBe( 'EUR' );
		press( page, 'ArrowDown' );
		expect( code( focused( page ) ) ).toBe( 'GBP' );
		press( page, 'ArrowDown' );
		expect( code( focused( page ) ) ).toBe( 'GBP' ); // No wrap.
		expect( press( page, 'ArrowUp' ) ).toBe( false );
		expect( code( focused( page ) ) ).toBe( 'EUR' );
		// Home/End must not scroll the page either.
		expect( press( page, 'Home' ) ).toBe( false );
		expect( code( focused( page ) ) ).toBe( 'USD' );
		expect( press( page, 'End' ) ).toBe( false );
		expect( code( focused( page ) ) ).toBe( 'GBP' );
	} );

	test( 'on a neutral (cache-mode) render, opening focuses the first option', async () => {
		const page = await boot( switcherHtml( 'one', true ) );

		// Precondition: nothing is marked active, as on a cached first visit.
		expect( page.document.querySelector( '.mhmcs-active' ) ).toBeNull();

		button( page ).focus();
		press( page, 'Enter' );

		expect( code( focused( page ) ) ).toBe( 'USD' );
	} );

	test( 'the button is wired to its listbox for assistive technology', async () => {
		const page = await boot( switcherHtml( 'one' ) + switcherHtml( 'two' ) );
		const ids = [ 'one', 'two' ].map( ( id ) => dropdown( page, id ).id );

		[ 'one', 'two' ].forEach( ( id, i ) => {
			expect( button( page, id ).getAttribute( 'aria-haspopup' ) ).toBe( 'listbox' );
			expect( button( page, id ).getAttribute( 'aria-controls' ) ).toBe( ids[ i ] );
			expect( page.document.getElementById( ids[ i ] ) ).toBe( dropdown( page, id ) );
		} );
		expect( ids[ 0 ] ).not.toBe( ids[ 1 ] );
	} );

	test( 'handled keys do not reach a theme menu script on an ancestor', async () => {
		const page = await boot();
		const seen = [];

		page.document.body.addEventListener( 'keydown', ( e ) => seen.push( e.key ) );

		button( page ).focus();
		press( page, 'ArrowDown' ); // On the button: opens.
		press( page, 'ArrowDown' ); // In the list.
		press( page, 'Home' );
		press( page, 'End' );
		press( page, 'Escape' );

		expect( seen ).toEqual( [] );

		// Positive control: an unhandled key still bubbles, so the listener
		// above was really listening.
		press( page, 'a' );
		expect( seen ).toEqual( [ 'a' ] );
	} );

	test( 'a held-down Enter does not pick whichever option it lands on', async () => {
		const page = await boot();

		button( page ).focus();
		press( page, 'Enter' );
		press( page, 'ArrowDown' );
		focused( page ).dispatchEvent(
			new page.window.KeyboardEvent( 'keydown', {
				key: 'Enter',
				repeat: true,
				bubbles: true,
				cancelable: true,
			} )
		);

		expect( page.announced ).toEqual( [] );
		expect( isOpen( page ) ).toBe( true );
	} );

	test( 'on the server-rendered path, focus is on the button BEFORE the reload', async () => {
		const page = await boot( undefined, { config: { clientConversion: false } } );

		button( page ).focus();
		press( page, 'Enter' );
		press( page, 'ArrowDown' );
		press( page, 'Enter' );

		// Exactly one reload, and the button already had focus when it ran.
		expect( page.reloads ).toHaveLength( 1 );
		expect( page.reloads[ 0 ] ).toBe( button( page ) );
		expect( page.document.cookie ).toContain( 'mhmcs_currency=EUR' );
	} );

	test.each( [ [ 'Enter' ], [ ' ' ] ] )(
		'%p on an option selects it, closes the list and returns focus to the button',
		async ( key ) => {
			const page = await boot();

			button( page ).focus();
			press( page, 'Enter' );
			press( page, 'ArrowDown' );
			const notPrevented = press( page, key );

			expect( notPrevented ).toBe( false );
			expect( page.announced ).toEqual( [ 'EUR' ] );
			expect( isOpen( page ) ).toBe( false );
			expect( button( page ).getAttribute( 'aria-expanded' ) ).toBe( 'false' );
			expect( focused( page ) ).toBe( button( page ) );
			expect( page.document.cookie ).toContain( 'mhmcs_currency=EUR' );
		}
	);

	test( 'Escape inside the list closes it and returns focus to the button, selecting nothing', async () => {
		const page = await boot();

		button( page ).focus();
		press( page, 'Enter' );
		press( page, 'ArrowDown' );
		// Precondition: focus really is INSIDE the list. Without it, "focus
		// returned to the button" is free — it never left (measured: this
		// test passed against the unfixed script).
		expect( code( focused( page ) ) ).toBe( 'EUR' );
		press( page, 'Escape' );

		expect( isOpen( page ) ).toBe( false );
		expect( focused( page ) ).toBe( button( page ) );
		expect( page.announced ).toEqual( [] );
	} );

	test( 'focus leaving the switcher (Tab, virtual cursor) closes the list without selecting', async () => {
		const page = await boot();

		button( page ).focus();
		press( page, 'Enter' );
		press( page, 'ArrowDown' );
		expect( code( focused( page ) ) ).toBe( 'EUR' ); // Precondition.

		// jsdom does not move focus on Tab; move it the way Tab would. The list
		// must close on focus LEAVING, not on the Tab keydown — closing there
		// hides the focused option before the browser resolves where Tab goes.
		press( page, 'Tab' );
		expect( isOpen( page ) ).toBe( true );
		page.document.getElementById( 'after' ).focus();

		expect( isOpen( page ) ).toBe( false );
		expect( button( page ).getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( page.announced ).toEqual( [] );
	} );

	test( 'moving focus between the button and its options keeps the list open', async () => {
		const page = await boot();

		button( page ).focus();
		press( page, 'Enter' );
		press( page, 'ArrowDown' );
		button( page ).focus(); // e.g. Shift+Tab back to the button.

		expect( isOpen( page ) ).toBe( true );
	} );

	test( 'aria-selected follows the active currency', async () => {
		const page = await boot();

		const selected = () =>
			[ ...page.document.querySelectorAll( '.mhmcs-option' ) ]
				.filter( ( li ) => 'true' === li.getAttribute( 'aria-selected' ) )
				.map( code );

		expect( selected() ).toEqual( [ 'USD' ] );

		button( page ).focus();
		press( page, 'Enter' );
		press( page, 'End' );
		press( page, 'Enter' );

		expect( selected() ).toEqual( [ 'GBP' ] );
	} );

	test( 'keys in one switcher never move another on the same page', async () => {
		const page = await boot( switcherHtml( 'one' ) + switcherHtml( 'two' ) );

		button( page, 'two' ).focus();
		press( page, 'Enter' );
		press( page, 'ArrowDown' );

		expect( isOpen( page, 'two' ) ).toBe( true );
		expect( isOpen( page, 'one' ) ).toBe( false );
		// Focus moved to an OPTION of the second switcher, not merely stayed
		// on its button (which would pass with no keyboard support at all).
		expect( code( focused( page ) ) ).toBe( 'EUR' );
		expect( focused( page ).closest( '.mhmcs-switcher' ).id ).toBe( 'two' );
	} );

	test( 'a mouse click still opens the list WITHOUT stealing focus into it', async () => {
		const page = await boot();

		button( page ).focus();
		button( page ).dispatchEvent(
			new page.window.MouseEvent( 'click', { bubbles: true, detail: 1 } )
		);

		expect( isOpen( page ) ).toBe( true );
		expect( focused( page ) ).toBe( button( page ) );
	} );
} );
