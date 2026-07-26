/**
 * MHM Currency Switcher — cache-friendly client-side price conversion.
 *
 * A catalogue page is rendered, and cached, in the shop's base currency for
 * every visitor alike, with each price wrapped by PriceDisplayMarker. This
 * script collects those markers, asks `POST mhmcs/v1/convert` for the same
 * prices in this visitor's currency, and writes the answer back in place.
 *
 * Three properties are load-bearing and every one of them has bitten a
 * previous revision of the design:
 *
 * 1. The detection order below is the SAME as the server's
 *    DetectionService::detect_currency(): cookie, then `?currency=`, then ask
 *    the server to detect. Written the other way round, a visitor with an EUR
 *    cookie who follows a `?currency=USD` link is shown USD in the catalogue
 *    and charged EUR at the checkout (design spec §5.2).
 * 2. The cookie is written in exactly one case: we sent `currency: null` and
 *    the server answered `detected: true`. See persist().
 * 3. Failure is silent and leaves the base price on the page. No message, no
 *    half-converted page, and never a price left invisible.
 *
 * The event contract with switcher.js runs both ways. Inbound: a currency
 * change is announced as `mhmcs:currency-changed`, dispatched on `document` or
 * on `window`, and this script re-runs. Outbound: after a successful
 * conversion pass this script dispatches `mhmcs:currency-resolved` carrying the
 * currency the server resolved, so the switcher's indicator can catch up in
 * the auto-detect case, where no cookie existed for it to read. The two names
 * are deliberately different — see announce().
 *
 * @package MhmCurrencySwitcher
 * @since 1.1.0
 */
( function () {
	'use strict';

	/*
	 * Everything below the top level of the localized object keeps its real
	 * JSON types. wp_localize_script() casts TOP-LEVEL scalars to strings —
	 * `true` arrives as "1" and `false` as "" — so Enqueue.php nests the whole
	 * payload one level down and this script reads real booleans and numbers.
	 */
	const config = window.mhmcsData && window.mhmcsData.config;

	if (
		! config ||
		! config.markerClass ||
		! config.idAttribute ||
		! config.restUrl
	) {
		return;
	}

	/**
	 * Report a failure to the console and nowhere else.
	 *
	 * A visitor whose conversion failed still has a correct, readable price in
	 * front of them — the shop's base currency — so there is nothing to tell
	 * them and nothing they could do about it.
	 *
	 * @param {string} message Diagnostic message.
	 * @param {*}      detail  Optional detail to log alongside it.
	 * @return {void}
	 */
	function debug( message, detail ) {
		if ( window.console && 'function' === typeof window.console.debug ) {
			window.console.debug(
				'[mhm-currency-switcher] ' + message,
				detail
			);
		}
	}

	/*
	 * The browser floor, checked before anything is computed. Both guards bail
	 * the same way — the page keeps the base prices it was cached with, and the
	 * only trace is a console line.
	 */
	if (
		'function' !== typeof window.fetch ||
		'function' !== typeof window.Promise
	) {
		debug( 'fetch or Promise unavailable; base prices left in place.' );
		return;
	}

	/**
	 * Markers to convert. An attribute-presence check is part of the selector
	 * so a marker without an ID is never collected and never counted.
	 *
	 * This can only ever match real elements. Price HTML also travels inside
	 * the JSON blobs WooCommerce Blocks preloads into
	 * `<script type="application/json">`, but a script element's contents are
	 * one text node — querySelectorAll walks elements, so it cannot reach
	 * inside. (Those blobs carry no marker in any case: a Store API request is
	 * a money context, so the server converts it and PriceDisplayMarker does
	 * not wrap it.)
	 *
	 * @type {string}
	 */
	const MARKER_SELECTOR =
		'.' + config.markerClass + '[' + config.idAttribute + ']';

	/**
	 * Batch size, taken from the server's own limit rather than restated here.
	 * The endpoint rejects a batch over its cap with a 400, so a hard-coded 50
	 * that drifted from ConvertController::MAX_PRODUCT_IDS would fail the whole
	 * page rather than degrade.
	 *
	 * @type {number}
	 */
	const BATCH_SIZE = Math.max( 1, parseInt( config.batchSize, 10 ) || 1 );

	/**
	 * Base-currency HTML of every marker seen, so switching BACK to the base
	 * currency can restore it. Without this the page would be stuck in the
	 * previously converted currency: no request is made for the base currency,
	 * because the server would return exactly the HTML the page was cached
	 * with.
	 *
	 * @type {WeakMap|null}
	 */
	const originals = 'function' === typeof WeakMap ? new WeakMap() : null;

	/**
	 * Whether a run is already queued for this tick.
	 *
	 * @type {boolean}
	 */
	let pending = false;

	/**
	 * Normalise a currency code the same way the server does.
	 *
	 * DetectionService::sanitize_currency_code() uppercases, trims and requires
	 * exactly three ASCII letters. Anything else is not a currency code.
	 *
	 * @param {*} value Raw value from a cookie, a URL or a response.
	 * @return {string|null} Three-letter code, or null.
	 */
	function sanitizeCode( value ) {
		if ( 'string' !== typeof value ) {
			return null;
		}

		const code = value.replace( /^\s+|\s+$/g, '' ).toUpperCase();

		return /^[A-Z]{3}$/.test( code ) ? code : null;
	}

	/**
	 * Keep only a code this shop actually offers.
	 *
	 * The localized currency map is the base currency plus the enabled ones,
	 * which is precisely the allowlist DetectionService::validate_code()
	 * applies. Skipping this check would let `?currency=XYZ` send the client
	 * down a branch the server resolves differently.
	 *
	 * @param {string|null} code Sanitised currency code.
	 * @return {string|null} The code when the shop offers it, or null.
	 */
	function knownCurrency( code ) {
		if ( ! code || ! config.currencies ) {
			return null;
		}

		return Object.prototype.hasOwnProperty.call( config.currencies, code )
			? code
			: null;
	}

	/**
	 * Decode one percent-encoded cookie or query-string component.
	 *
	 * @param {string} raw Encoded value.
	 * @return {string} Decoded value, or the input when it is malformed.
	 */
	function decodeValue( raw ) {
		try {
			return decodeURIComponent( raw.replace( /\+/g, ' ' ) );
		} catch ( error ) {
			return raw;
		}
	}

	/**
	 * Read the currency cookie — step 1 of the chain, exactly as on the server.
	 *
	 * @return {string|null} Raw cookie value, or null when absent.
	 */
	function readCookie() {
		const prefix = config.cookieName + '=';
		const parts = document.cookie ? document.cookie.split( ';' ) : [];
		let i;
		let part;

		for ( i = 0; i < parts.length; i++ ) {
			part = parts[ i ].replace( /^\s+/, '' );

			if ( 0 === part.indexOf( prefix ) ) {
				return decodeValue( part.slice( prefix.length ) );
			}
		}

		return null;
	}

	/**
	 * Read the `?currency=` query parameter — step 2 of the chain.
	 *
	 * Parsed by hand rather than with URLSearchParams so the script keeps the
	 * same browser floor as its fetch/Promise guard above and adds no second
	 * one.
	 *
	 * @return {string|null} Raw parameter value, or null when absent.
	 */
	function readUrlParam() {
		const query = window.location.search;

		if ( ! query || '?' !== query.charAt( 0 ) ) {
			return null;
		}

		const pairs = query.slice( 1 ).split( '&' );
		let separator;
		let i;

		for ( i = 0; i < pairs.length; i++ ) {
			separator = pairs[ i ].indexOf( '=' );

			if ( -1 === separator ) {
				continue;
			}

			if (
				decodeValue( pairs[ i ].slice( 0, separator ) ) ===
				config.urlParam
			) {
				return decodeValue( pairs[ i ].slice( separator + 1 ) );
			}
		}

		return null;
	}

	/**
	 * Resolve the currency to ask for, in the server's order.
	 *
	 * 🔴 Cookie FIRST, then the URL parameter. DetectionService::detect_currency()
	 * reads them in that order and detect_from_url_param() deliberately writes
	 * no cookie, so a `?currency=` link overrides the display for that view only
	 * — on both sides. Reversing the two here is the round-2 / YB-3 bug: the
	 * catalogue would show the link's currency while the cart, which asks the
	 * server, charges the cookie's.
	 *
	 * @return {string|null} A currency code, or null meaning "server, detect it".
	 */
	function resolveCurrency() {
		const fromCookie = knownCurrency( sanitizeCode( readCookie() ) );

		if ( fromCookie ) {
			return fromCookie;
		}

		const fromUrl = knownCurrency( sanitizeCode( readUrlParam() ) );

		if ( fromUrl ) {
			return fromUrl;
		}

		return null;
	}

	/**
	 * Persist a detected currency, under the one condition that allows it.
	 *
	 * Only a currency the SERVER detected for us is written, and only when it
	 * reports `detected: true`. A `false` means nothing was detectable — no
	 * CloudFlare country header and no MaxMind database, which is the common
	 * production case — and the response fell back to the base currency.
	 * Writing that would pin the base currency in the cookie, the chain would
	 * match at step 1 from then on, and geolocation would never be retried for
	 * this visitor (design spec §5.4).
	 *
	 * Nothing is written for the `?currency=` path either, which is server
	 * parity and not an omission: detect_from_url_param() writes no cookie, so
	 * a link-driven currency lasts for that view on both sides.
	 *
	 * @param {Object} payload Decoded response body.
	 * @return {void}
	 */
	function persist( payload ) {
		if ( ! payload || true !== payload.detected ) {
			return;
		}

		const code = sanitizeCode( payload.currency );

		if ( ! code ) {
			return;
		}

		writeCookie( code );
	}

	/**
	 * Write the currency cookie with the attributes PHP uses.
	 *
	 * Byte-for-byte the same contract as DetectionService::set_currency()
	 * (design spec §5.3): 30 days, path `/`, SameSite=Lax, and `Secure` on
	 * https only — matching is_ssl(), and mandatory in that direction, because
	 * a browser drops a `Secure` cookie set over plain http and the visitor's
	 * choice would silently stop persisting.
	 *
	 * @param {string} code Three-letter currency code.
	 * @return {void}
	 */
	function writeCookie( code ) {
		const days = parseInt( config.cookieDays, 10 ) || 30;
		let cookie =
			config.cookieName +
			'=' +
			encodeURIComponent( code ) +
			';path=/' +
			';max-age=' +
			days * 24 * 60 * 60 +
			';SameSite=Lax';

		if ( 'https:' === window.location.protocol ) {
			cookie += ';Secure';
		}

		document.cookie = cookie;
	}

	/**
	 * Collect the markers on the page, remembering each one's base HTML the
	 * first time it is seen.
	 *
	 * @return {Array} Marker elements.
	 */
	function collect() {
		const nodes = document.querySelectorAll( MARKER_SELECTOR );
		const markers = [];
		let i;

		for ( i = 0; i < nodes.length; i++ ) {
			if ( originals && ! originals.has( nodes[ i ] ) ) {
				originals.set( nodes[ i ], nodes[ i ].innerHTML );
			}

			markers.push( nodes[ i ] );
		}

		return markers;
	}

	/**
	 * Put the page back into the base currency.
	 *
	 * @param {Array} markers Marker elements.
	 * @return {void}
	 */
	function restore( markers ) {
		if ( ! originals ) {
			return;
		}

		markers.forEach( function ( element ) {
			const base = originals.get( element );

			if ( 'string' === typeof base && base !== element.innerHTML ) {
				write( element, base );
			}
		} );
	}

	/**
	 * Whether the visitor has asked for reduced motion.
	 *
	 * @return {boolean} True when the fade must be skipped.
	 */
	function prefersReducedMotion() {
		return (
			'function' === typeof window.matchMedia &&
			window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
		);
	}

	/**
	 * Write price HTML into a marker and fade it in.
	 *
	 * innerHTML is deliberate and bounded. The value is WooCommerce's own
	 * `price_html`, generated by wc_price() on the server in this same request
	 * cycle — it is markup (`<bdi>`, `<del>`, `<ins>`) whose structure the price
	 * depends on, so textContent would print the tags and a sanitiser would
	 * flatten a sale price into one number. The only site-supplied value inside
	 * it, the currency symbol, is sanitised when the administrator saves it.
	 *
	 * The fade cannot strand a price invisible. The restore is scheduled BEFORE
	 * anything is hidden, so no later line — including one that throws — can
	 * leave opacity at 0; and it is a setTimeout rather than a
	 * requestAnimationFrame, because rAF does not run in a background tab and
	 * would leave the prices blank on a tab the visitor comes back to.
	 *
	 * @param {Element} element Marker element.
	 * @param {string}  html    Price HTML to write.
	 * @return {void}
	 */
	function write( element, html ) {
		if ( ! prefersReducedMotion() ) {
			window.setTimeout( function () {
				element.style.transition = 'opacity 200ms ease-in';
				element.style.opacity = '1';
			}, 0 );

			element.style.opacity = '0';
		}

		element.innerHTML = html;
	}

	/**
	 * Split a list of IDs into batches the endpoint will accept.
	 *
	 * @param {Array}  ids  Product IDs.
	 * @param {number} size Maximum batch size.
	 * @return {Array} Array of ID arrays.
	 */
	function chunk( ids, size ) {
		const batches = [];
		let index;

		for ( index = 0; index < ids.length; index += size ) {
			batches.push( ids.slice( index, index + size ) );
		}

		return batches;
	}

	/**
	 * POST one batch to the convert endpoint.
	 *
	 * @param {string|null} currency Currency to price in, or null to detect.
	 * @param {Array}       ids      Product IDs, at most BATCH_SIZE of them.
	 * @return {Promise} Resolves with the decoded body.
	 */
	function send( currency, ids ) {
		return window
			.fetch( config.restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify( {
					currency,
					product_ids: ids,
				} ),
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}

				return response.json();
			} );
	}

	/**
	 * Tell switcher.js which currency this page ended up in.
	 *
	 * The auto-detect case is the one that needs it. With `auto_detect` on and
	 * no cookie yet, the server resolves the currency here, in the response to
	 * this request — the switcher was rendered neutral, showing the base
	 * currency, and has no other way to learn what the visitor actually got.
	 * Without this it would read "$ USD" next to prices in ₺.
	 *
	 * 🔴 Deliberately NOT `mhmcs:currency-changed`. This script listens to
	 * that event, so announcing on it would make the two scripts trigger each
	 * other.
	 *
	 * @param {Object} payload Decoded response body.
	 * @return {void}
	 */
	function announce( payload ) {
		const code = payload ? sanitizeCode( payload.currency ) : null;

		if ( ! code || 'function' !== typeof window.CustomEvent ) {
			return;
		}

		document.dispatchEvent(
			new window.CustomEvent( 'mhmcs:currency-resolved', {
				bubbles: true,
				detail: { currency: code },
			} )
		);
	}

	/**
	 * Send one batch and write whatever came back.
	 *
	 * A rejection is absorbed here and resolves to null, so one failed batch
	 * cannot take the others down with it: the products it covered keep the
	 * base price the page was cached with, and the rest of the page still
	 * converts. There is no partially-written state to unwind — a marker is
	 * either replaced whole or not touched.
	 *
	 * @param {string|null} currency   Currency to price in, or null to detect.
	 * @param {Array}       ids        Product IDs.
	 * @param {Object}      byId       Marker elements keyed by product ID.
	 * @param {boolean}     mayPersist Whether this batch may write the cookie.
	 * @return {Promise} Resolves with the payload, or null on failure.
	 */
	function dispatch( currency, ids, byId, mayPersist ) {
		return send( currency, ids ).then(
			function ( payload ) {
				apply( payload, byId );

				if ( mayPersist ) {
					persist( payload );
				}

				announce( payload );

				return payload;
			},
			function ( error ) {
				debug( 'conversion request failed; base prices kept.', error );

				return null;
			}
		);
	}

	/**
	 * Write a response's prices into their markers.
	 *
	 * @param {Object} payload Decoded response body.
	 * @param {Object} byId    Marker elements keyed by product ID.
	 * @return {void}
	 */
	function apply( payload, byId ) {
		if ( ! payload || ! payload.prices ) {
			return;
		}

		Object.keys( payload.prices ).forEach( function ( id ) {
			const html = payload.prices[ id ];
			const elements = byId[ id ];

			if ( 'string' !== typeof html || '' === html || ! elements ) {
				return;
			}

			elements.forEach( function ( element ) {
				write( element, html );
			} );
		} );
	}

	/**
	 * Convert every batch.
	 *
	 * The detection case is sequenced on purpose. Sending `currency: null` on
	 * every batch in parallel would geolocate the same visitor once per batch,
	 * and two batches could in principle come back in two different currencies.
	 * So the first batch asks, and the rest are sent with the code it resolved.
	 * A page under one batch — the ordinary case — is unaffected.
	 *
	 * @param {string|null} currency Currency to price in, or null to detect.
	 * @param {Array}       batches  Batches of product IDs.
	 * @param {Object}      byId     Marker elements keyed by product ID.
	 * @return {void}
	 */
	function convert( currency, batches, byId ) {
		if ( null !== currency ) {
			batches.forEach( function ( ids ) {
				dispatch( currency, ids, byId, false );
			} );

			return;
		}

		dispatch( null, batches[ 0 ], byId, true ).then( function ( payload ) {
			const code = payload ? sanitizeCode( payload.currency ) : null;
			const rest = batches.slice( 1 );

			if ( ! code || ! rest.length ) {
				return;
			}

			rest.forEach( function ( ids ) {
				dispatch( code, ids, byId, false );
			} );
		} );
	}

	/**
	 * Convert the page once.
	 *
	 * @return {void}
	 */
	function run() {
		const markers = collect();

		if ( ! markers.length ) {
			return;
		}

		const currency = resolveCurrency();

		/*
		 * Nothing detectable and nothing to detect with: the visitor gets the
		 * base currency, which is what the page already shows.
		 */
		if ( null === currency && ! config.autoDetect ) {
			restore( markers );

			return;
		}

		/*
		 * The base currency needs no request. PriceFilter and FormatFilter both
		 * return the value unchanged once DetectionService::is_base_currency()
		 * is true, so the endpoint would answer with byte-identical HTML to
		 * what the page was cached with. restore() matters here rather than
		 * `return`: on a switch BACK to the base currency the markers are still
		 * showing the previous one.
		 */
		if ( currency === config.baseCurrency ) {
			restore( markers );

			return;
		}

		const byId = {};
		const ids = [];

		markers.forEach( function ( element ) {
			const raw = element.getAttribute( config.idAttribute );
			const id = parseInt( raw, 10 );

			if ( ! id || id < 1 || String( id ) !== raw ) {
				return;
			}

			if ( ! byId[ id ] ) {
				byId[ id ] = [];
				ids.push( id );
			}

			byId[ id ].push( element );
		} );

		if ( ! ids.length ) {
			return;
		}

		convert( currency, chunk( ids, BATCH_SIZE ), byId );
	}

	/**
	 * Queue a run, coalescing several triggers in the same tick into one.
	 *
	 * @return {void}
	 */
	function schedule() {
		if ( pending ) {
			return;
		}

		pending = true;

		window.setTimeout( function () {
			pending = false;
			run();
		}, 0 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', schedule );
	} else {
		schedule();
	}

	/*
	 * switcher.js announces a currency change here. Both targets are listened
	 * to so the announcement cannot be missed over where it was dispatched;
	 * schedule() collapses the pair into a single run.
	 */
	document.addEventListener( 'mhmcs:currency-changed', schedule );
	window.addEventListener( 'mhmcs:currency-changed', schedule );
} )();
