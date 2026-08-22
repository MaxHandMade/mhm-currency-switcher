/**
 * The contract between a stored numeric setting and the text its input shows.
 *
 * 🔴 WHY THIS IS A MODULE AND NOT FOUR INLINE EXPRESSIONS
 * -------------------------------------------------------
 * Every numeric field in this panel is a CONTROLLED `<input type="number">`:
 * the value it displays comes from a prop, and every keystroke round-trips
 * through the parent's state. Two innocent-looking idioms turn that loop into
 * a silent 10.000x error, and this project shipped both of them in v1.2.0:
 *
 *     value={ currency.rate?.value || '' }          // prop side
 *     value: parseFloat( value ) || 0               // state side
 *
 * `0 || ''` is `''`. So the moment a keystroke makes the value 0 — which the
 * FIRST character of "0.0211" does — the prop becomes an empty string while
 * the input holds "0". React's controlled-input reconciliation for number
 * inputs (react-dom 18.3.1, updateWrapper: `node.value != value` with a LOOSE
 * comparison) then sees "0" != "" and overwrites the field with "". The
 * leading zero is gone, the decimal point that follows lands in an empty box,
 * and the digits after it build a whole number.
 *
 * Measured in Chrome on a real store (maxhandmade, WP 7.0.3 / WC 11.0.0,
 * base currency TRY), typing with real keystrokes:
 *
 *     typed "0.05"    -> field held 5     (preview: 100,00 ₺ -> 500,00 $)
 *     typed "0.0211"  -> field held 211   (preview: 100,00 ₺ -> 21.100,00 ¥)
 *     typed "1.5"     -> field held 1.5   (correct — the bug needs a zero)
 *
 * A store whose base currency is TRY has NO foreign rate above 1, so the
 * panel's central field could not accept a single realistic rate, and it
 * failed silently: 211 in the box looks like a plausible yen rate.
 *
 * The two rules below are what make the loop stable. They are together in one
 * module so the pair cannot drift apart, and so a fifth numeric field cannot
 * be added without meeting them — tests/js/numeric-field.test.js counts the
 * fields and reads each one's value prop.
 *
 * @package
 */

/**
 * Value to hand a controlled numeric input.
 *
 * Nullish-coalescing, never `||`: a stored 0 is a real value and must reach
 * the input as `0`, not as the empty string that wipes the field mid-edit.
 *
 * @param {number|string|null|undefined} value Stored value.
 * @return {number|string} Value for the input's `value` prop.
 */
export const toFieldValue = ( value ) => value ?? '';

/**
 * Value to store for what a numeric input reports.
 *
 * Returns the empty string — NOT 0 — for an empty reading, because "empty" is
 * how a number input reports a half-typed value: per the HTML value
 * sanitization algorithm, an input holding "0." reads back as "" (measured:
 * Chrome and jsdom both do this). Coercing that to 0 would push a `0` prop at
 * an input whose text is "0.", and react-dom's number branch force-writes
 * whenever `value === 0 && node.value === ''` — wiping the decimal point the
 * user just typed.
 *
 * An empty string can reach the REST payload only if the user saves with the
 * field blank; `RestAPI::sanitize_currency()` casts it with `(float)`, which
 * stores 0.0 — the same value the old `parseFloat( value ) || 0` produced for
 * a blank field.
 *
 * @param {string} raw Value reported by the input.
 * @return {number|string} Number to store, or '' while the edit is partial.
 */
export const parseFieldValue = ( raw ) => {
	if ( '' === raw ) {
		return '';
	}

	const parsed = parseFloat( raw );

	return Number.isNaN( parsed ) ? '' : parsed;
};
