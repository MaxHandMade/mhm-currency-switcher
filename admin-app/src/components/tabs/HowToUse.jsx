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

const HowToUse = () => (
	<div className="mhm-cs-how-to-use">
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

		<h3>
			{ __( 'Placing the currency switcher', 'mhm-currency-switcher' ) }
		</h3>

		<h4>{ __( 'Shortcode', 'mhm-currency-switcher' ) }</h4>
		<CopyableCode
			code={ `[${ SWITCHER }]` }
			label={ __(
				'currency switcher shortcode',
				'mhm-currency-switcher'
			) }
		/>
		<p>
			{ __(
				'Optional attribute: size, which accepts small, medium or large. Leaving it out uses the size saved on the Display Options tab.',
				'mhm-currency-switcher'
			) }
		</p>
		<CopyableCode
			code={ `[${ SWITCHER } size="large"]` }
			label={ __(
				'currency switcher shortcode with a size',
				'mhm-currency-switcher'
			) }
		/>

		<h4>{ __( 'Elementor', 'mhm-currency-switcher' ) }</h4>
		<p>
			{ sprintf(
				/* translators: %s: the widget's name in the Elementor panel. */
				__(
					'Drag the %s widget from the Elementor panel onto your layout.',
					'mhm-currency-switcher'
				),
				ELEMENTOR_WIDGETS[ 0 ]
			) }
		</p>

		<h4>
			{ __(
				'Navigation menu (classic themes)',
				'mhm-currency-switcher'
			) }
		</h4>
		<p>
			{ __(
				'Go to Appearance → Menus and add the Currency item to a menu. This screen only exists on classic themes; on a block theme use the shortcode or Elementor instead.',
				'mhm-currency-switcher'
			) }
		</p>

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
				<tr>
					<td>
						<code>currencies</code>
					</td>
					<td>
						{ __(
							'Comma-separated codes, for example USD,EUR. Without it, the currencies chosen on the Display Options tab are used. Codes you have not configured are ignored.',
							'mhm-currency-switcher'
						) }
					</td>
				</tr>
				<tr>
					<td>
						<code>product_id</code>
					</td>
					<td>
						{ __(
							'Price a specific product instead of the one being viewed.',
							'mhm-currency-switcher'
						) }
					</td>
				</tr>
				<tr>
					<td>
						<code>show_flags</code>
					</td>
					<td>
						{ __(
							'true or false, overriding the saved Display Options setting.',
							'mhm-currency-switcher'
						) }
					</td>
				</tr>
				<tr>
					<td>
						<code>price</code>
					</td>
					<td>
						{ __(
							'Price a fixed amount instead of a product. Mainly useful for testing a layout.',
							'mhm-currency-switcher'
						) }
					</td>
				</tr>
			</tbody>
		</table>
		<p>
			{ sprintf(
				/* translators: %s: the widget's name in the Elementor panel. */
				__(
					'The same list is available as the %s Elementor widget.',
					'mhm-currency-switcher'
				),
				ELEMENTOR_WIDGETS[ 1 ]
			) }
		</p>
		<p>
			{ __(
				'If you have switched the product price widget on under Display Options, the list already appears on every product page. Adding the shortcode to a product template as well will show it twice.',
				'mhm-currency-switcher'
			) }
		</p>

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
