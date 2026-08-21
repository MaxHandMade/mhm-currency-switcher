/**
 * The "How to use" tab.
 *
 * Every sample rendered here comes from the two constants below, and
 * tests/Unit/Compliance/HelpTabAccuracyTest.php parses those same constants and
 * checks them against the plugin's real registrations. A sample written by hand
 * into the markup is invisible to that gate — put it in the registry instead.
 *
 * Shortcode tags are deliberately outside __(): a translator who translates
 * a shortcode name breaks the sample the user copies.
 *
 * ELEMENTOR_WIDGETS itself stays in English source literals too — the gate
 * pins it against the get_title() source strings in
 * src/Integration/Elementor/. What gets rendered to the reader is a separate
 * __() call on the same literal, below, so it resolves to the widget's own
 * translated title instead of the English registry value.
 */

import { __, sprintf } from '@wordpress/i18n';
import CopyableCode from '../shared/CopyableCode';

export const PLACEMENT_SAMPLES = [
	{ shortcode: 'mhm_currency_switcher', attrs: [ 'size' ] },
	{
		shortcode: 'mhm_currency_prices',
		attrs: [ 'currencies', 'product_id', 'show_flags', 'price' ],
	},
];

export const ELEMENTOR_WIDGETS = [ 'Currency Switcher', 'Currency Prices' ];

const SWITCHER = PLACEMENT_SAMPLES[ 0 ].shortcode;
const PRICES = PLACEMENT_SAMPLES[ 1 ].shortcode;
const PRICE_LIST_ATTRS = PLACEMENT_SAMPLES[ 1 ].attrs;

/*
 * Description prose for each price-list attribute, keyed to the attribute
 * name so the table below can be driven entirely by PLACEMENT_SAMPLES[1].attrs.
 * The attribute NAME itself must never pass through __() — a translator who
 * translates it breaks the sample the user copies. Only the description does.
 */
const PRICE_LIST_ATTR_DESCRIPTIONS = {
	currencies: __(
		'Comma-separated codes, for example USD,EUR. Without it, the currencies chosen on the Display Options tab are used. Codes you have not configured are ignored.',
		'mhm-currency-switcher'
	),
	product_id: __(
		'Price a specific product instead of the one being viewed.',
		'mhm-currency-switcher'
	),
	show_flags: __(
		'true or false, overriding the saved Display Options setting.',
		'mhm-currency-switcher'
	),
	price: __(
		'Price a fixed amount instead of a product. Mainly useful for testing a layout.',
		'mhm-currency-switcher'
	),
};

const HowToUse = () => (
	<div className="mhm-cs-tab-content mhm-cs-how-to-use">
		<h3>{ __( 'Quick start', 'mhm-currency-switcher' ) }</h3>
		<ol>
			<li>
				{ __(
					'Add a currency on the Manage Currencies tab.',
					'mhm-currency-switcher'
				) }
			</li>
			<li>
				{ __(
					'Give it a rate, or set the update interval on the Advanced tab.',
					'mhm-currency-switcher'
				) }
			</li>
			<li>
				{ __(
					'Place the switcher somewhere your visitors can reach it, using one of the options below.',
					'mhm-currency-switcher'
				) }
			</li>
		</ol>

		<hr />

		<h3>
			{ __( 'Placing the currency switcher', 'mhm-currency-switcher' ) }
		</h3>

		<div className="mhm-cs-card">
			<h4 className="mhm-cs-card__label">
				{ __( 'Shortcode', 'mhm-currency-switcher' ) }
			</h4>
			<div className="mhm-cs-card__body">
				<CopyableCode
					code={ `[${ SWITCHER }]` }
					label={ __(
						'currency switcher shortcode',
						'mhm-currency-switcher'
					) }
				/>
				<p>
					{ sprintf(
						/* translators: 1: attribute name, 2: the three accepted values, all shown as code and not translated. */
						__(
							'Optional attribute: %1$s, which accepts %2$s. Leaving it out uses the size saved on the Display Options tab.',
							'mhm-currency-switcher'
						),
						'size',
						'small, medium, large'
					) }
				</p>
				<CopyableCode
					code={ `[${ SWITCHER } size="large"]` }
					label={ __(
						'currency switcher shortcode with a size',
						'mhm-currency-switcher'
					) }
				/>
			</div>
		</div>

		<div className="mhm-cs-card">
			<h4 className="mhm-cs-card__label">
				{ __( 'Elementor', 'mhm-currency-switcher' ) }
			</h4>
			<div className="mhm-cs-card__body">
				<p>
					{ sprintf(
						/* translators: %s: the widget's name exactly as it appears in the Elementor panel. */
						__(
							'Drag the %s widget from the Elementor panel onto your layout.',
							'mhm-currency-switcher'
						),
						/* translators: this is a UI element name that must stay identical everywhere it appears in the plugin. */
						__( 'Currency Switcher', 'mhm-currency-switcher' )
					) }
				</p>
			</div>
		</div>

		<div className="mhm-cs-card">
			<h4 className="mhm-cs-card__label">
				{ __( 'Navigation menu', 'mhm-currency-switcher' ) }
			</h4>
			<div className="mhm-cs-card__body">
				<p>
					{ __(
						'Open Appearance → Menus. The "Currency Switcher" box adds the switcher as a menu item, and it renders as the live switcher on the front end.',
						'mhm-currency-switcher'
					) }
				</p>
				<p>
					{ __(
						'The menu item follows the same Display Options settings as every other placement.',
						'mhm-currency-switcher'
					) }
				</p>
			</div>
		</div>

		<hr />

		<h3>
			{ __(
				'Showing a product price in several currencies',
				'mhm-currency-switcher'
			) }
		</h3>
		<CopyableCode
			code={ `[${ PRICES }]` }
			label={ __(
				'product price list shortcode',
				'mhm-currency-switcher'
			) }
		/>
		<table>
			<thead>
				<tr>
					<th>{ __( 'Attribute', 'mhm-currency-switcher' ) }</th>
					<th>{ __( 'What it does', 'mhm-currency-switcher' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ PRICE_LIST_ATTRS.map( ( attr ) => (
					<tr key={ attr }>
						<td>
							<code>{ attr }</code>
						</td>
						<td>{ PRICE_LIST_ATTR_DESCRIPTIONS[ attr ] }</td>
					</tr>
				) ) }
			</tbody>
		</table>
		<p>
			{ sprintf(
				/* translators: %s: the widget's name exactly as it appears in the Elementor panel. */
				__(
					'The same list is available as the %s Elementor widget.',
					'mhm-currency-switcher'
				),
				/* translators: this is a UI element name that must stay identical everywhere it appears in the plugin. */
				__( 'Currency Prices', 'mhm-currency-switcher' )
			) }
		</p>
		<p>
			{ __(
				'If you have switched the product price widget on under Display Options, the list already appears on every product page. Adding the shortcode to a product template as well will show it twice.',
				'mhm-currency-switcher'
			) }
		</p>

		<hr />

		<h3>{ __( 'Block themes', 'mhm-currency-switcher' ) }</h3>
		<p>
			{ __(
				'This plugin does not provide its own block yet. Use the core Shortcode block and paste either shortcode into it. The Elementor widgets remain available if you use Elementor.',
				'mhm-currency-switcher'
			) }
		</p>
	</div>
);

export default HowToUse;
