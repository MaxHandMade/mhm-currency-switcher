/**
 * 🔴 Must be the FIRST docblock in the file — Jest reads the pragma only there,
 * and in its jsdom environment `require('jsdom')` dies on a missing TextEncoder.
 * Each test builds its own window anyway; see below.
 *
 * @jest-environment node
 */

/**
 * price-converter.js — the marker set is dynamic, not fixed at load.
 *
 * The bug these tests lock: the converter collected its markers once, on
 * DOMContentLoaded, and never looked again. Anything that put a marker into the
 * page after that — WooCommerce Blocks hydrating `wc-block-components-product-price`
 * from the cached page's own server data, a client-rendered product grid paging,
 * a third-party filter script — left a base-currency price on the page for good.
 * On a block theme that is the DEFAULT single-product template, so the flagship
 * feature was broken on a stock install.
 *
 * 🔴 Every test builds its OWN JSDOM. Sharing one document would leave the
 * previous test's MutationObserver alive on the same DOM, and it would convert
 * the next test's markers — a green that proves nothing. Verified by running the
 * suite against the unfixed script: with a shared document the regression test
 * passes without the fix.
 *
 * @package MhmCurrencySwitcher
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { JSDOM } = require( 'jsdom' );

const SCRIPT = fs.readFileSync(
	path.join( __dirname, '..', '..', 'assets', 'js', 'price-converter.js' ),
	'utf8'
);

const BASE_HTML = '<bdi>$20.00</bdi>';
const CONVERTED_HTML = '<bdi>€18,40</bdi>';

/**
 * Requests one page may make before the stub calls it a loop.
 *
 * Far above anything a real page needs — the busiest test here makes two.
 *
 * @type {number}
 */
const RUNAWAY_LIMIT = 25;

/**
 * Build the localized config the script reads, mirroring Enqueue.php.
 *
 * @param {Object} overrides Fields to replace.
 * @return {Object} Config object.
 */
function makeConfig( overrides ) {
	return Object.assign(
		{
			restUrl: 'https://shop.test/wp-json/mhmcs/v1/convert',
			baseCurrency: 'USD',
			cacheCompat: true,
			clientConversion: true,
			autoDetect: false,
			cookieName: 'mhmcs_currency',
			cookieDays: 30,
			urlParam: 'currency',
			batchSize: 50,
			markerClass: 'mhmcs-price',
			idAttribute: 'data-mhmcs-product',
			currencies: { USD: {}, EUR: {} },
		},
		overrides || {}
	);
}

/**
 * Marker markup as PriceDisplayMarker emits it.
 *
 * @param {number} id   Product ID.
 * @param {string} html Price HTML to wrap.
 * @return {string} Marker HTML.
 */
function marker( id, html ) {
	return (
		'<span class="mhmcs-price" data-mhmcs-product="' +
		id +
		'">' +
		html +
		'</span>'
	);
}

/**
 * Boot a fresh page with the converter on it.
 *
 * @param {Object} options Body HTML, cookie and config overrides.
 * @return {Object} The window, plus the recorded requests.
 */
function boot( options ) {
	const settings = options || {};
	const dom = new JSDOM(
		'<!doctype html><html><body>' + ( settings.body || '' ) + '</body></html>',
		{ url: 'https://shop.test/shop/', runScripts: 'outside-only' }
	);

	const { window } = dom;
	const requests = [];

	if ( settings.cookie ) {
		window.document.cookie = settings.cookie;
	}

	window.fetch = function ( url, init ) {
		const body = JSON.parse( init.body );

		requests.push( body );

		/*
		 * A converter that reacts to the DOM can in principle react to its own
		 * effect on it. Left unbounded that saturates the event loop and the
		 * test HANGS rather than failing — a useless signal in CI. Cutting the
		 * stub off turns any runaway into a plain assertion failure carrying the
		 * count. (This is not hypothetical: it is what the observer does when
		 * its marker check is defeated and no WeakMap is available to hold the
		 * second lock.)
		 */
		if ( requests.length > RUNAWAY_LIMIT ) {
			return Promise.reject( new Error( 'runaway conversion loop' ) );
		}

		const prices = {};

		body.product_ids.forEach( function ( id ) {
			prices[ id ] = CONVERTED_HTML;
		} );

		return Promise.resolve( {
			ok: true,
			json: function () {
				return Promise.resolve( {
					currency: body.currency || 'EUR',
					detected: null === body.currency,
					prices,
				} );
			},
		} );
	};

	window.mhmcsData = { config: makeConfig( settings.config ) };

	if ( settings.beforeLoad ) {
		settings.beforeLoad( window );
	}

	window.eval( SCRIPT );

	return { window, requests };
}

/**
 * Let the script's setTimeout(0) hop and its fetch promises settle.
 *
 * @return {Promise} Resolves after the queue has drained.
 */
function settle() {
	return new Promise( function ( resolve ) {
		setTimeout( resolve, 50 );
	} );
}

/**
 * Text of a marker, whitespace-trimmed.
 *
 * @param {Object} window Page window.
 * @param {number} id     Product ID.
 * @return {string} Marker text.
 */
function priceOf( window, id ) {
	const element = window.document.querySelector(
		'.mhmcs-price[data-mhmcs-product="' + id + '"]'
	);

	return element ? element.textContent.trim() : '';
}

describe( 'price-converter marker collection', () => {
	it( 'converts the markers present when it loads', async () => {
		const { window, requests } = boot( {
			body: marker( 21, BASE_HTML ),
			cookie: 'mhmcs_currency=EUR',
		} );

		await settle();

		expect( requests ).toHaveLength( 1 );
		expect( requests[ 0 ].product_ids ).toEqual( [ 21 ] );
		expect( priceOf( window, 21 ) ).toBe( '€18,40' );
	} );

	it( 'converts a marker inserted after the first run', async () => {
		// The regression. WooCommerce Blocks hydrates the product-price block
		// from the cached page's own base-currency data and swaps our written
		// node for a fresh one, ~700ms after load, in a stock block theme.
		const { window, requests } = boot( {
			body: '<div class="wc-block-components-product-price">' +
				marker( 21, BASE_HTML ) +
				'</div>',
			cookie: 'mhmcs_currency=EUR',
		} );

		await settle();
		expect( priceOf( window, 21 ) ).toBe( '€18,40' );

		const container = window.document.querySelector(
			'.wc-block-components-product-price'
		);

		container.innerHTML = marker( 21, BASE_HTML );
		expect( priceOf( window, 21 ) ).toBe( '$20.00' );

		await settle();

		expect( priceOf( window, 21 ) ).toBe( '€18,40' );
		expect( requests.length ).toBeGreaterThan( 1 );
	} );

	it( 'converts a marker added to a page that had none', async () => {
		// A client-rendered grid that arrives empty and fills in later: the
		// first run finds nothing and returns before resolving a currency.
		const { window } = boot( {
			body: '<div id="grid"></div>',
			cookie: 'mhmcs_currency=EUR',
		} );

		await settle();

		window.document.getElementById( 'grid' ).innerHTML = marker(
			10,
			BASE_HTML
		);

		await settle();

		expect( priceOf( window, 10 ) ).toBe( '€18,40' );
	} );

	it( 'does not re-run for its own writes', async () => {
		// The converter replaces the contents of every marker it converts. If
		// watching the DOM counted that as new work, each pass would schedule
		// the next one and the page would request its prices for ever.
		const { window, requests } = boot( {
			body: marker( 21, BASE_HTML ) + marker( 10, BASE_HTML ),
			cookie: 'mhmcs_currency=EUR',
		} );

		await settle();
		await settle();
		await settle();

		expect( requests ).toHaveLength( 1 );
		expect( priceOf( window, 21 ) ).toBe( '€18,40' );
	} );

	it( 'prices a late marker on its own, without re-pricing the page', async () => {
		// Without this the two guards cover for each other and neither is
		// locked: dropping the "already converted" filter breaks no test,
		// because our own writes add no marker for the observer to react to.
		// An infinite-scroll catalogue would re-request every price on the page
		// for each batch that scrolled in — against an endpoint that has no rate
		// limiting.
		const { window, requests } = boot( {
			body: '<div id="grid">' + marker( 21, BASE_HTML ) + '</div>',
			cookie: 'mhmcs_currency=EUR',
		} );

		await settle();
		expect( requests ).toHaveLength( 1 );

		window.document
			.getElementById( 'grid' )
			.insertAdjacentHTML( 'beforeend', marker( 10, BASE_HTML ) );

		await settle();

		expect( requests ).toHaveLength( 2 );
		expect( requests[ 1 ].product_ids ).toEqual( [ 10 ] );
	} );

	it( 'ignores unrelated DOM changes without a WeakMap to fall back on', async () => {
		// The "already converted" filter needs a WeakMap; where there is none it
		// is off by design, and the marker check in the observer is all that
		// stands between a page that animates something and a request per
		// mutation. Deleting WeakMap is what isolates that check — with it
		// present, the filter hides whether the check works at all.
		const { window, requests } = boot( {
			body: marker( 21, BASE_HTML ) + '<div id="side"></div>',
			cookie: 'mhmcs_currency=EUR',
			beforeLoad( win ) {
				delete win.WeakMap;
			},
		} );

		await settle();
		expect( requests ).toHaveLength( 1 );

		window.document.getElementById( 'side' ).innerHTML =
			'<span class="ticker">3</span>';

		await settle();

		expect( requests ).toHaveLength( 1 );
	} );

	it( 'ignores DOM changes that carry no marker', async () => {
		const { window, requests } = boot( {
			body: marker( 21, BASE_HTML ) + '<div id="side"></div>',
			cookie: 'mhmcs_currency=EUR',
		} );

		await settle();
		expect( requests ).toHaveLength( 1 );

		window.document.getElementById( 'side' ).innerHTML =
			'<p>Free shipping over $50</p>';

		await settle();

		expect( requests ).toHaveLength( 1 );
	} );

	it( 'leaves a re-inserted base price alone when the visitor is on the base currency', async () => {
		// No request is made for the base currency, so a late marker must not
		// produce one either — that would be a call per hydration, for nothing.
		const { window, requests } = boot( {
			body: '<div id="grid">' + marker( 21, BASE_HTML ) + '</div>',
			cookie: 'mhmcs_currency=USD',
		} );

		await settle();
		expect( requests ).toHaveLength( 0 );

		window.document.getElementById( 'grid' ).innerHTML = marker(
			10,
			BASE_HTML
		);

		await settle();

		expect( requests ).toHaveLength( 0 );
		expect( priceOf( window, 10 ) ).toBe( '$20.00' );
	} );
} );
