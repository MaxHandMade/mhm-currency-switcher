/**
 * AdvancedSettings tab — geolocation, auto-update, cache, multilingual.
 *
 * @package
 */

import { ToggleControl, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * AdvancedSettings tab component.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.settings   Current plugin settings.
 * @param {Function} props.onChange   Callback when settings change.
 * @param {Array}    props.currencies Array of currency config objects.
 * @return {JSX.Element} AdvancedSettings tab.
 */
const AdvancedSettings = ( { settings, onChange, currencies } ) => {
	const update = ( key, value ) => {
		onChange( { ...settings, [ key ]: value } );
	};

	const multilingual = settings.multilingual_mapping || {};

	const updateMultilingual = ( lang, currencyCode ) => {
		onChange( {
			...settings,
			multilingual_mapping: {
				...multilingual,
				[ lang ]: currencyCode,
			},
		} );
	};

	const currencyOptions = [
		{
			label: __( 'Default', 'mhm-currency-switcher' ),
			value: '',
		},
		...currencies.map( ( c ) => ( {
			label: c.code,
			value: c.code,
		} ) ),
	];

	const commonLanguages = [
		{ code: 'en', label: 'English' },
		{ code: 'tr', label: 'Turkish' },
		{ code: 'de', label: 'German' },
		{ code: 'fr', label: 'French' },
		{ code: 'es', label: 'Spanish' },
		{ code: 'ar', label: 'Arabic' },
		{ code: 'ru', label: 'Russian' },
		{ code: 'ja', label: 'Japanese' },
		{ code: 'zh', label: 'Chinese' },
		{ code: 'pt', label: 'Portuguese' },
	];

	return (
		<div className="mhm-cs-tab-content">
			<h3>
				{ __( 'Geolocation Detection', 'mhm-currency-switcher' ) }
			</h3>

			<div className="mhm-cs-settings-group">
				<ToggleControl
					label={ __(
						'Enable geolocation-based currency detection',
						'mhm-currency-switcher'
					) }
					help={ __(
						'Automatically detect visitor country and show matching currency.',
						'mhm-currency-switcher'
					) }
					checked={ settings.auto_detect || false }
					onChange={ ( val ) => update( 'auto_detect', val ) }
					__nextHasNoMarginBottom
				/>

				{ settings.auto_detect && (
					<p className="description">
						{ __(
							'CloudFlare sites are detected automatically. Other sites use WooCommerce MaxMind GeoIP database.',
							'mhm-currency-switcher'
						) }
					</p>
				) }
			</div>

			<hr />

			<h3>
				{ __( 'Automatic Rate Updates', 'mhm-currency-switcher' ) }
			</h3>

			<div className="mhm-cs-settings-group">
				<SelectControl
					label={ __(
						'Update interval',
						'mhm-currency-switcher'
					) }
					value={ settings.rate_update_interval || 'daily' }
					options={ [
						{
							label: __(
								'Manual only',
								'mhm-currency-switcher'
							),
							value: 'manual',
						},
						{
							label: __( 'Hourly', 'mhm-currency-switcher' ),
							value: 'hourly',
						},
						{
							label: __(
								'Twice daily',
								'mhm-currency-switcher'
							),
							value: 'twicedaily',
						},
						{
							label: __( 'Daily', 'mhm-currency-switcher' ),
							value: 'daily',
						},
					] }
					onChange={ ( val ) =>
						update( 'rate_update_interval', val )
					}
					__nextHasNoMarginBottom
				/>
			</div>

			<hr />

			<h3>
				{ __( 'Multilingual Mapping', 'mhm-currency-switcher' ) }
			</h3>
			<p className="description">
				{ __(
					'Map languages to default currencies. When a visitor switches language (via WPML, Polylang, etc.), the currency will switch automatically.',
					'mhm-currency-switcher'
				) }
			</p>

			<table className="mhm-cs-currency-table widefat">
				<thead>
					<tr>
						<th>
							{ __( 'Language', 'mhm-currency-switcher' ) }
						</th>
						<th>
							{ __(
								'Default Currency',
								'mhm-currency-switcher'
							) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ commonLanguages.map( ( lang ) => (
						<tr key={ lang.code }>
							<td>{ lang.label }</td>
							<td>
								<SelectControl
									value={
										multilingual[ lang.code ] || ''
									}
									options={ currencyOptions }
									onChange={ ( val ) =>
										updateMultilingual( lang.code, val )
									}
									__nextHasNoMarginBottom
								/>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
};

export default AdvancedSettings;
