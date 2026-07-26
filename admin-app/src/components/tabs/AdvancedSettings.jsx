/**
 * AdvancedSettings tab — geolocation, auto-update, cache.
 *
 * @package
 */

import { ToggleControl, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * AdvancedSettings tab component.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.settings Current plugin settings.
 * @param {Function} props.onChange Callback when settings change.
 * @return {JSX.Element} AdvancedSettings tab.
 */
const AdvancedSettings = ( { settings, onChange } ) => {
	const update = ( key, value ) => {
		onChange( { ...settings, [ key ]: value } );
	};

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

			<h3>{ __( 'Cache Compatibility', 'mhm-currency-switcher' ) }</h3>

			<div className="mhm-cs-settings-group">
				<ToggleControl
					label={ __(
						'Cache pages in the base currency',
						'mhm-currency-switcher'
					) }
					help={ __(
						'Pages are cached in the store base currency and displayed prices are converted in the browser, so page caching plugins work without any configuration. Cart, checkout and order totals are always converted on the server. Turning this off makes the storefront convert prices on the server again, which caches badly. It does not restore the 1.0.0 behaviour exactly: the admin, REST API and scheduled-task fixes stay active either way.',
						'mhm-currency-switcher'
					) }
					/*
					 * An absent key means ON — exactly how ConversionContext
					 * decision 4 and the activation default read it. Writing
					 * `|| false` here would show OFF while the server behaved
					 * as ON.
					 */
					checked={ settings.cache_compat !== false }
					onChange={ ( val ) => update( 'cache_compat', val ) }
					__nextHasNoMarginBottom
				/>
			</div>
		</div>
	);
};

export default AdvancedSettings;
