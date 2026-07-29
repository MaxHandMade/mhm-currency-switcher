/**
 * The "How to use" tab.
 *
 * Every sample rendered here comes from the two constants below, and
 * tests/Unit/Compliance/HelpTabAccuracyTest.php parses those same constants and
 * checks them against the plugin's real registrations. A sample written by hand
 * into the markup is invisible to that gate — put it in the registry instead.
 *
 * Shortcode tags and Elementor titles are deliberately outside __(): a
 * translator who translates a shortcode name breaks the sample the user copies.
 */

import { __ } from '@wordpress/i18n';

export const PLACEMENT_SAMPLES = [
	{ shortcode: 'mhm_currency_switcher', attrs: [ 'size' ] },
	{
		shortcode: 'mhm_currency_prices',
		attrs: [ 'currencies', 'product_id', 'show_flags', 'price' ],
	},
];

export const ELEMENTOR_WIDGETS = [ 'Currency Switcher', 'Currency Prices' ];

const HowToUse = () => (
	<div className="mhm-cs-how-to-use">
		<h3>{ __( 'How to use', 'mhm-currency-switcher' ) }</h3>
	</div>
);

export default HowToUse;
