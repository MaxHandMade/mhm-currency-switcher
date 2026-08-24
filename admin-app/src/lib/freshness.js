/**
 * Rate-freshness state machine.
 *
 * Shared by the Manage Currencies tab (this module's first consumer) and, in
 * a later task, the Advanced tab's diagnostics — both must agree on exactly
 * the same states in exactly the same order, or a shop could see a green
 * pill on one tab and a stale warning on the other for the same rates.
 *
 * A NEW module rather than an export from ManageCurrencies.jsx: exporting
 * this from one tab component would make another tab import it, a shape the
 * review rubric rejects. Nothing here imports anything outside
 * `@wordpress/i18n`, whose script handle (`wp-i18n`) the bundle already
 * depends on, so this file adds no new dependency to pin.
 *
 * TWO clocks live here, and they answer different questions. `freshnessState()`
 * (backing the header pill) reads the global `mhmcs_rates_last_sync` option —
 * a statement about the BATCH: did the last sync run, against which base, how
 * long ago. `formatRowUpdatedAgo()` (backing each row's own status line) is
 * driven by that row's own `rate.updated_at`, stamped by
 * `RateProvider::apply_rates()` only for a row a sync actually rewrote. The
 * global option cannot answer a per-row question — a row flipped from manual
 * to auto keeps its hand-typed number until the NEXT sync touches it, and
 * dating that number with the batch timestamp describes a sync that never
 * produced it. Reading `rate.updated_at` instead of `lastSync.time` for the
 * row is what keeps that promise honest.
 *
 * @package
 */

import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Freshness states, in evaluation order. First match wins.
 *
 * 🔴 The order is the specification. The conditions overlap — a manual-only
 * shop that never synced satisfies both MANUAL_ONLY and NO_RECORD — so an
 * unordered set of ifs shows the wrong pill on the most ordinary
 * configuration there is.
 *
 * @type {Object}
 */
export const FRESHNESS = {
	MANUAL_ONLY: 'manual-only',
	NO_RECORD: 'no-record',
	STALE_BASE: 'stale-base',
	STALE_AGE: 'stale-age',
	FRESH: 'fresh',
};

/**
 * Seconds after which rates are called stale for a given interval.
 *
 * Twice the configured interval, so one missed tick is tolerated and two are
 * not. A manual shop is given 48 hours: nothing schedules a sync there, so
 * the only honest thing to measure is neglect.
 *
 * @type {Object}
 */
export const STALE_AFTER = {
	hourly: 2 * 3600,
	twicedaily: 24 * 3600,
	daily: 48 * 3600,
	manual: 48 * 3600,
};

/**
 * Decide which freshness state applies.
 *
 * @param {Array}  currencies Currency rows.
 * @param {Object} lastSync   Stored { time, base } or null.
 * @param {string} base       Live base currency code.
 * @param {string} interval   Configured rate update interval.
 * @param {number} nowSeconds Current time, in seconds.
 * @return {string} One of FRESHNESS.
 */
export const freshnessState = (
	currencies,
	lastSync,
	base,
	interval,
	nowSeconds
) => {
	const hasAuto = currencies.some(
		( c ) => ( c.rate?.type || 'auto' ) !== 'manual'
	);

	if ( ! hasAuto ) {
		return FRESHNESS.MANUAL_ONLY;
	}

	if ( ! lastSync || ! lastSync.time ) {
		return FRESHNESS.NO_RECORD;
	}

	if ( lastSync.base !== base ) {
		return FRESHNESS.STALE_BASE;
	}

	const limit = STALE_AFTER[ interval ] || STALE_AFTER.manual;

	return nowSeconds - lastSync.time > limit
		? FRESHNESS.STALE_AGE
		: FRESHNESS.FRESH;
};

/**
 * Map a freshness state to the { tone, text } pair both consumer tabs
 * render for it.
 *
 * Extracted here rather than left as two copies: freshnessState() was
 * already shared, but each tab still built its own tone/text lookup from
 * the same four msgids, so a wording change made on one tab could silently
 * leave the other describing the same option differently, and a careless
 * rewrite could fork a msgid the catalogue is supposed to track once. The
 * markup each tab wraps this in (a header pill vs. a line under a control)
 * is the only genuine difference between the two call sites, so only the
 * markup stays local.
 *
 * MANUAL_ONLY has no entry on purpose: a shop with no automatic currency
 * has nothing for a sync signal to say. Callers must guard the result for
 * `undefined` — e.g. `{ pill && ( … ) }`.
 *
 * @param {string} state    One of FRESHNESS, as returned by freshnessState().
 * @param {string} humanAge Pre-formatted "N days"-style string from
 *                          formatHumanAge(), used only by the two states
 *                          that need it (STALE_AGE, FRESH). Pass '' when the
 *                          caller has no lastSync.time to format.
 * @return {{tone: string, text: string}|undefined} Tone and text to render,
 *                                                   or undefined for MANUAL_ONLY.
 */
export const freshnessMessage = ( state, humanAge ) =>
	( {
		[ FRESHNESS.NO_RECORD ]: {
			tone: 'warn',
			text: __( 'No sync recorded yet', 'mhm-currency-switcher' ),
		},
		[ FRESHNESS.STALE_BASE ]: {
			tone: 'warn',
			text: __(
				"The store's base currency changed since the last sync — rates need re-syncing.",
				'mhm-currency-switcher'
			),
		},
		[ FRESHNESS.STALE_AGE ]: {
			tone: 'warn',
			text: sprintf(
				/* translators: %s: a human-readable interval, for example "3 days". */
				__( 'Rates last updated %s ago', 'mhm-currency-switcher' ),
				humanAge
			),
		},
		[ FRESHNESS.FRESH ]: {
			tone: 'ok',
			text: sprintf(
				/* translators: %s: a human-readable interval, for example "2 hours". */
				__( 'Rates updated %s ago', 'mhm-currency-switcher' ),
				humanAge
			),
		},
	} )[ state ];

/**
 * Render a duration as a human-readable, translated age string such as
 * "3 hours" or "2 days", for use inside a larger "%s ago" sentence.
 *
 * Each unit is its own plural-aware msgid via `_n()` rather than a shared
 * "%d %s" template built from a separately translated unit word — the same
 * "glued fragment" shape the rest of this bundle's i18n avoids, because a
 * unit word translated in isolation cannot be reordered or inflected
 * correctly for every locale.
 *
 * @param {number} seconds Age in seconds. Must be >= 0.
 * @return {string} Human-readable age, e.g. "5 minutes", "3 hours", "2 days".
 */
export const formatHumanAge = ( seconds ) => {
	const minutes = Math.floor( seconds / 60 );

	if ( minutes < 60 ) {
		const count = Math.max( 1, minutes );
		return sprintf(
			/* translators: %d: number of minutes. */
			_n( '%d minute', '%d minutes', count, 'mhm-currency-switcher' ),
			count
		);
	}

	const hours = Math.floor( seconds / 3600 );

	if ( hours < 24 ) {
		return sprintf(
			/* translators: %d: number of hours. */
			_n( '%d hour', '%d hours', hours, 'mhm-currency-switcher' ),
			hours
		);
	}

	const days = Math.floor( seconds / 86400 );

	return sprintf(
		/* translators: %d: number of days. */
		_n( '%d day', '%d days', days, 'mhm-currency-switcher' ),
		days
	);
};

/**
 * Render "how long ago" a specific row's rate was produced by a sync, as one
 * complete translated sentence per unit.
 *
 * Deliberately NOT `sprintf( __( '%s ago' ), formatHumanAge( seconds ) )`. A
 * bare placeholder-plus-preposition msgid — "%s ago" — is not a translatable
 * unit on its own: a translator sees a blank and the word "ago", with no
 * verb, no subject, and no way to know whether the placeholder is a
 * duration, a name, or a date, let alone reorder it for a language whose
 * grammar puts "ago" somewhere else entirely. Every unit gets its own
 * full-sentence, plural-aware msgid instead; more msgids is the correct
 * cost.
 *
 * @param {number} seconds Age in seconds. Must be >= 0.
 * @return {string} A complete sentence, e.g. "Rate updated 3 hours ago".
 */
export const formatRowUpdatedAgo = ( seconds ) => {
	const minutes = Math.floor( seconds / 60 );

	if ( minutes < 60 ) {
		const count = Math.max( 1, minutes );
		return sprintf(
			/* translators: %d: number of minutes. */
			_n(
				'Rate updated %d minute ago',
				'Rate updated %d minutes ago',
				count,
				'mhm-currency-switcher'
			),
			count
		);
	}

	const hours = Math.floor( seconds / 3600 );

	if ( hours < 24 ) {
		return sprintf(
			/* translators: %d: number of hours. */
			_n(
				'Rate updated %d hour ago',
				'Rate updated %d hours ago',
				hours,
				'mhm-currency-switcher'
			),
			hours
		);
	}

	const days = Math.floor( seconds / 86400 );

	return sprintf(
		/* translators: %d: number of days. */
		_n(
			'Rate updated %d day ago',
			'Rate updated %d days ago',
			days,
			'mhm-currency-switcher'
		),
		days
	);
};

/**
 * When the next automatic sync is due, as a line the Advanced tab can print.
 *
 * The interval control names a RECURRENCE and stops there, so "Twice daily"
 * left the shop owner unable to tell 02:00 from 14:00, or to see that nothing
 * was scheduled at all.
 *
 * 🔴 The absolute time is NOT built here. `nextSync.formatted` arrives already
 * rendered by PHP's wp_date(), which is the only thing that applies the SHOP's
 * timezone and date format; wp_next_scheduled() answers in UTC, so formatting
 * that number in the browser would quietly show the VISITOR's zone instead.
 * This function passes that string through and adds only what is genuinely
 * client-side: the relative wait.
 *
 * 🔴 And it never promises a clock time on its own. WordPress cron is
 * visit-triggered: on a site with no traffic the event simply waits past its
 * hour. Saying "at 02:00" without that caveat is a promise the software cannot
 * keep, so the caveat is part of the sentence rather than a footnote.
 *
 * @param {?Object} nextSync   { time, formatted } from GET /currencies, or null.
 * @param {string}  interval   Stored rate_update_interval.
 * @param {number}  nowSeconds Current unix time, seconds.
 * @return {?{tone: string, text: string}} Line to render, or null for silence.
 */
export const formatNextSync = ( nextSync, interval, nowSeconds ) => {
	// The radio already reads "Manual only". A second line saying so is noise.
	if ( 'manual' === interval || ! interval ) {
		return null;
	}

	if ( ! nextSync || ! nextSync.time ) {
		return {
			tone: 'warning',
			text: __(
				'Automatic updates are switched on, but no update is scheduled. Re-save this setting to schedule one.',
				'mhm-currency-switcher'
			),
		};
	}

	const remaining = nextSync.time - nowSeconds;

	/*
	 * Overdue is the NORMAL state of a low-traffic shop, not an error: the
	 * event waits for the visit that runs it. Rendering "in -3 hours" would
	 * read as a bug.
	 */
	if ( remaining <= 0 ) {
		return {
			tone: 'info',
			text: sprintf(
				/* translators: %s: formatted date and time of the scheduled update. */
				__(
					'Next update was due %s and is waiting for the next visit to the site.',
					'mhm-currency-switcher'
				),
				nextSync.formatted
			),
		};
	}

	return {
		tone: 'info',
		text: sprintf(
			/* translators: 1: formatted date and time. 2: human-readable duration, e.g. "2 hours". */
			__(
				'Next update: %1$s, about %2$s from now. It runs on the first visit to the site after that time.',
				'mhm-currency-switcher'
			),
			nextSync.formatted,
			formatHumanAge( remaining )
		),
	};
};
