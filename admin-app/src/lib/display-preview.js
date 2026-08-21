/**
 * Display tab live-preview row builder.
 *
 * Pure function: `(currencies, baseCurrency, baseSymbol) → ordered rows`.
 * Extracted out of DisplayOptions.jsx so its two defects can be pinned with
 * real executions instead of a source-text grep — the same reasoning and the
 * same technique as `lib/freshness.js` (see tests/js/freshness.test.js for
 * the loader this module's own test, tests/js/display-preview.test.js, reuses).
 *
 * DEFECT 1 — the base currency was missing entirely. The preview used to be
 * `currencies.filter( (c) => c.enabled ).map( ... )`, and the base currency
 * has no row in `currencies` at all — it is not a conversion target, so
 * ManageCurrencies never lists it. But the real storefront switcher puts the
 * base FIRST, always (see `src/Frontend/Switcher.php`,
 * `// Always include the base currency first.` in `build_options_list()`).
 * So the panel showed a list no visitor would ever see. This function
 * prepends the base row instead of expecting it to appear in `currencies`.
 *
 * DEFECT 2 — a currency that is ALSO present in `currencies` (defensively:
 * this should not happen per Switcher.php's own comment, but the preview
 * must not print the base currency twice if it ever does) is skipped rather
 * than appended a second time.
 *
 * The `show_symbol` toggle is NOT handled here — whether a row's symbol is
 * actually printed is a rendering decision, not a data-shape one, and stays
 * in DisplayOptions.jsx's JSX where it can be pinned as a real branch.
 *
 * @package
 */

/**
 * Build the ordered list of rows the Display tab's live preview renders.
 *
 * @param {Array}  currencies   Currency config objects (never includes the
 *                              base currency — see Switcher.php).
 * @param {string} baseCurrency Base currency code, e.g. 'USD'.
 * @param {string} baseSymbol   Base currency's symbol, from
 *                              `window.mhmCsAdmin.baseSymbol`.
 * @return {Array<{code: string, symbol: string}>} Ordered preview rows, base
 *                                                   currency first.
 */
export const buildPreviewRows = ( currencies, baseCurrency, baseSymbol ) => {
	const rows = [ { code: baseCurrency, symbol: baseSymbol || '' } ];

	( currencies || [] )
		.filter( ( c ) => c.enabled && c.code !== baseCurrency )
		.forEach( ( c ) => {
			rows.push( { code: c.code, symbol: c.format?.symbol || '' } );
		} );

	return rows;
};
