/**
 * MHM Currency Switcher — Snooze button for the cache-compatibility notices.
 *
 * CacheCompatDiagnostic prints up to two admin notices, each with a "Snooze
 * until this changes" button. This script POSTs to whichever endpoint the
 * clicked button names and, on success, removes that notice from the page —
 * it never re-shows it, because the very next admin_notices render already
 * will not: the meta this write creates is what render_notice() checks.
 *
 * The endpoint takes no payload. It reads the anomaly's current signature on
 * the server and snoozes exactly that; this script only has to say WHICH of
 * the two anomalies was dismissed, via the button's own data attribute, and
 * that value only ever selects between the two fixed routes below — it is
 * never sent to the server.
 *
 * @package MhmCurrencySwitcher
 * @since 2.1.0
 */
( function () {
	'use strict';

	const config = window.mhmcsCacheNotice;

	if ( ! config || ! config.restUrl || ! config.nonce ) {
		return;
	}

	/**
	 * Maps a button's `data-mhmcs-snooze` value to the endpoint it snoozes.
	 * Only these two keys are ever consulted — an unrecognised value finds
	 * nothing here and the click is ignored.
	 *
	 * @type {Object}
	 */
	const ROUTES = {
		anomaly: 'cache-notice/snooze-anomaly',
		fragments: 'cache-notice/snooze-fragments',
	};

	/**
	 * Remove a notice from the page once its snooze is confirmed stored.
	 *
	 * @param {Element} button The button that was clicked.
	 * @return {void}
	 */
	function dismiss( button ) {
		const notice =
			'function' === typeof button.closest
				? button.closest( '.notice' )
				: null;

		if ( notice && notice.parentNode ) {
			notice.parentNode.removeChild( notice );
		}
	}

	/**
	 * Handle a click anywhere in the admin page, and act only when it landed
	 * on a Snooze button. Delegated on `document` rather than bound per
	 * button, so it keeps working if WordPress or another plugin re-prints
	 * the notices area.
	 *
	 * @param {MouseEvent} event Click event.
	 * @return {void}
	 */
	function onClick( event ) {
		const button =
			'function' === typeof event.target.closest
				? event.target.closest( '[data-mhmcs-snooze]' )
				: null;

		if ( ! button ) {
			return;
		}

		const kind = button.getAttribute( 'data-mhmcs-snooze' );
		const route = Object.prototype.hasOwnProperty.call( ROUTES, kind )
			? ROUTES[ kind ]
			: null;

		if ( ! route || 'function' !== typeof window.fetch ) {
			return;
		}

		event.preventDefault();
		button.disabled = true;

		window
			.fetch( config.restUrl + route, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': config.nonce,
				},
			} )
			.then( function ( response ) {
				if ( response.ok ) {
					dismiss( button );
				} else {
					button.disabled = false;
				}
			} )
			.catch( function () {
				button.disabled = false;
			} );
	}

	document.addEventListener( 'click', onClick );
} )();
