/**
 * AdvancedSettings tab — geolocation, auto-update, cache, multilingual.
 *
 * Wrapped in ProGate for Lite users.
 *
 * @package
 */

import {
	ToggleControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ProGate from '../shared/ProGate';

/**
 * AdvancedSettings tab component.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.settings   Current plugin settings.
 * @param {Function} props.onChange   Callback when settings change.
 * @param {boolean}  props.isPro      Whether Pro license is active.
 * @param {Array}    props.currencies Array of currency config objects.
 * @return {JSX.Element} AdvancedSettings tab.
 */
const AdvancedSettings = ( { settings, onChange, isPro, currencies } ) => {
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
			<ProGate isPro={ isPro }>
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
						<SelectControl
							label={ __(
								'Geolocation provider',
								'mhm-currency-switcher'
							) }
							value={ settings.geo_provider || 'woocommerce' }
							options={ [
								{
									label: __(
										'WooCommerce MaxMind',
										'mhm-currency-switcher'
									),
									value: 'woocommerce',
								},
								{
									label: 'CloudFlare',
									value: 'cloudflare',
								},
								{
									label: 'ipinfo.io',
									value: 'ipinfo',
								},
							] }
							onChange={ ( val ) =>
								update( 'geo_provider', val )
							}
							__nextHasNoMarginBottom
						/>
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

					<SelectControl
						label={ __( 'Rate provider', 'mhm-currency-switcher' ) }
						value={ settings.provider || 'exchangerate' }
						options={ [
							{
								label: 'ExchangeRate-API (free)',
								value: 'exchangerate',
							},
							{
								label: 'Open Exchange Rates',
								value: 'openexchangerates',
							},
							{
								label: 'CurrencyLayer',
								value: 'currencylayer',
							},
						] }
						onChange={ ( val ) => update( 'provider', val ) }
						__nextHasNoMarginBottom
					/>

					{ settings.provider &&
						settings.provider !== 'exchangerate' && (
							<TextControl
								label={ __(
									'API Key',
									'mhm-currency-switcher'
								) }
								value={ settings.provider_api_key || '' }
								onChange={ ( val ) =>
									update( 'provider_api_key', val )
								}
								type="password"
								__nextHasNoMarginBottom
							/>
						) }
				</div>

				<hr />

				<h3>{ __( 'Cache Settings', 'mhm-currency-switcher' ) }</h3>

				<div className="mhm-cs-settings-group">
					<ToggleControl
						label={ __(
							'Cache compatibility mode',
							'mhm-currency-switcher'
						) }
						help={ __(
							'Use cookie-based detection to work with page caching plugins.',
							'mhm-currency-switcher'
						) }
						checked={ settings.cache_compat || false }
						onChange={ ( val ) => update( 'cache_compat', val ) }
						__nextHasNoMarginBottom
					/>

					<TextControl
						label={ __(
							'Rate cache duration (seconds)',
							'mhm-currency-switcher'
						) }
						type="number"
						value={ settings.cache_duration || 3600 }
						onChange={ ( val ) =>
							update(
								'cache_duration',
								parseInt( val, 10 ) || 3600
							)
						}
						help={ __(
							'How long to cache exchange rates before fetching new ones.',
							'mhm-currency-switcher'
						) }
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
			</ProGate>
		</div>
	);
};

export default AdvancedSettings;
