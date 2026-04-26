/**
 * DisplayOptions tab — switcher appearance and product widget settings.
 *
 * @package
 */

import {
	ToggleControl,
	RadioControl,
	SelectControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * DisplayOptions tab component.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.settings   Current plugin settings.
 * @param {Function} props.onChange   Callback when settings change.
 * @param {Array}    props.currencies Array of currency config objects.
 * @return {JSX.Element} DisplayOptions tab.
 */
const DisplayOptions = ( { settings, onChange, currencies } ) => {
	const switcher = settings.switcher || {};
	const productWidget = settings.product_widget || {};

	const updateSwitcher = ( key, value ) => {
		onChange( {
			...settings,
			switcher: { ...switcher, [ key ]: value },
		} );
	};

	const updateProductWidget = ( key, value ) => {
		onChange( {
			...settings,
			product_widget: { ...productWidget, [ key ]: value },
		} );
	};

	const currencyOptions = currencies.map( ( c ) => ( {
		label: c.code,
		value: c.code,
	} ) );

	return (
		<div className="mhm-cs-tab-content">
			<h3>{ __( 'Switcher Appearance', 'mhm-currency-switcher' ) }</h3>

			<div className="mhm-cs-settings-group">
				<ToggleControl
					label={ __( 'Show flag icon', 'mhm-currency-switcher' ) }
					help={ __(
						'Display country flag next to currency.',
						'mhm-currency-switcher'
					) }
					checked={ switcher.show_flag !== false }
					onChange={ ( val ) => updateSwitcher( 'show_flag', val ) }
					__nextHasNoMarginBottom
				/>

				<ToggleControl
					label={ __(
						'Show currency name',
						'mhm-currency-switcher'
					) }
					help={ __(
						'Display full currency name (e.g., "US Dollar").',
						'mhm-currency-switcher'
					) }
					checked={ switcher.show_name !== false }
					onChange={ ( val ) => updateSwitcher( 'show_name', val ) }
					__nextHasNoMarginBottom
				/>

				<ToggleControl
					label={ __(
						'Show currency symbol',
						'mhm-currency-switcher'
					) }
					help={ __(
						'Display currency symbol (e.g., "$").',
						'mhm-currency-switcher'
					) }
					checked={ switcher.show_symbol !== false }
					onChange={ ( val ) => updateSwitcher( 'show_symbol', val ) }
					__nextHasNoMarginBottom
				/>

				<ToggleControl
					label={ __(
						'Show currency code',
						'mhm-currency-switcher'
					) }
					help={ __(
						'Display ISO currency code (e.g., "USD").',
						'mhm-currency-switcher'
					) }
					checked={ switcher.show_code !== false }
					onChange={ ( val ) => updateSwitcher( 'show_code', val ) }
					__nextHasNoMarginBottom
				/>

				<RadioControl
					label={ __( 'Switcher size', 'mhm-currency-switcher' ) }
					selected={ switcher.size || 'medium' }
					options={ [
						{
							label: __( 'Small', 'mhm-currency-switcher' ),
							value: 'small',
						},
						{
							label: __( 'Medium', 'mhm-currency-switcher' ),
							value: 'medium',
						},
						{
							label: __( 'Large', 'mhm-currency-switcher' ),
							value: 'large',
						},
					] }
					onChange={ ( val ) => updateSwitcher( 'size', val ) }
				/>
			</div>

			<hr />

			<h3>{ __( 'Product Price Widget', 'mhm-currency-switcher' ) }</h3>

			<div className="mhm-cs-settings-group">
				<ToggleControl
					label={ __(
						'Enable product price widget',
						'mhm-currency-switcher'
					) }
					help={ __(
						'Show prices in multiple currencies on product pages.',
						'mhm-currency-switcher'
					) }
					checked={ productWidget.enabled || false }
					onChange={ ( val ) =>
						updateProductWidget( 'enabled', val )
					}
					__nextHasNoMarginBottom
				/>

				{ productWidget.enabled && (
					<>
						<SelectControl
							multiple
							label={ __(
								'Currencies to display (max 5)',
								'mhm-currency-switcher'
							) }
							value={ productWidget.currencies || [] }
							options={ currencyOptions }
							onChange={ ( val ) => {
								const limited = val.slice( 0, 5 );
								updateProductWidget( 'currencies', limited );
							} }
							__nextHasNoMarginBottom
						/>

						<ToggleControl
							label={ __(
								'Show flags in widget',
								'mhm-currency-switcher'
							) }
							checked={ productWidget.show_flag !== false }
							onChange={ ( val ) =>
								updateProductWidget( 'show_flag', val )
							}
							__nextHasNoMarginBottom
						/>
					</>
				) }
			</div>

			<hr />

			<h3>{ __( 'Live Preview', 'mhm-currency-switcher' ) }</h3>

			<div className="mhm-cs-switcher-preview">
				<div
					className={ `mhm-cs-preview-switcher mhm-cs-preview-${
						switcher.size || 'medium'
					}` }
				>
					{ currencies
						.filter( ( c ) => c.enabled )
						.map( ( c ) => (
							<span
								key={ c.code }
								className="mhm-cs-preview-item"
							>
								{ switcher.show_flag !== false && (
									<span className="mhm-cs-preview-flag">
										{ c.code.substring( 0, 2 ) }
									</span>
								) }
								{ switcher.show_code !== false && (
									<span className="mhm-cs-preview-code">
										{ c.code }
									</span>
								) }
								{ switcher.show_name !== false && (
									<span className="mhm-cs-preview-name">
										{ ( window.mhmCsAdmin?.wcCurrencies &&
											window.mhmCsAdmin.wcCurrencies[
												c.code
											] ) ||
											c.code }
									</span>
								) }
							</span>
						) ) }
				</div>
				<p className="description">
					{ __(
						'This is a simplified preview. The actual switcher may vary based on your theme.',
						'mhm-currency-switcher'
					) }
				</p>
			</div>
		</div>
	);
};

export default DisplayOptions;
