/**
 * Jest configuration for the front-end script tests.
 *
 * Deliberately NOT the @wordpress/scripts preset. That preset runs every test in
 * Jest's own jsdom environment and loads a setup file that touches `window` at
 * import time; these tests instead build a FRESH JSDOM window per test, which
 * needs the plain node environment (and which Jest's jsdom environment breaks
 * anyway, by not exposing TextEncoder to `require('jsdom')`).
 *
 * A window per test is not fussiness. The converter attaches a MutationObserver
 * and two document listeners; sharing one document across tests would leave the
 * previous test's observer converting this test's markers, and the regression
 * test would pass with the bug still in place.
 *
 * @package MhmCurrencySwitcher
 */

module.exports = {
	testEnvironment: 'node',
	testMatch: [ '<rootDir>/tests/js/**/*.test.js' ],

	// A JS suite that silently matched nothing would be a green that means
	// nothing — the same failure mode phpunit.xml's failOnEmptyTestSuite closes
	// on the PHP side.
	passWithNoTests: false,
};
