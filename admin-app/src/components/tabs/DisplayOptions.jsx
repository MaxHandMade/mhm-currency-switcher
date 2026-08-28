/**
 * DisplayOptions tab — switcher appearance and product widget settings.
 *
 * @package
 */

import { useId } from '@wordpress/element';
import { ToggleControl, RadioControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { buildPreviewRows } from '../../lib/display-preview';
import CurrencyPicker from '../shared/CurrencyPicker';

/**
 * How many currencies the product price widget may list.
 *
 * Mirrored from RestAPI::PRODUCT_WIDGET_MAX_CURRENCIES; the server enforces the
 * same number and WidgetCapParityTest pins the two together.
 *
 * @type {number}
 */
const MAX_WIDGET_CURRENCIES = 5;

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

	// Localized by Settings.php: the base currency has no row in `currencies`
	// (it is not a conversion target — see Switcher.php's
	// build_options_list()), so its code and symbol come from here instead.
	const {
		baseCurrency = 'USD',
		baseSymbol = '',
		wcCurrencies = {},
	} = window.mhmCsAdmin || {};

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

	/*
	 * The base currency first, exactly as Switcher::build_options_list()
	 * does it — extracted to a pure function so it can be unit-tested with
	 * real inputs and outputs rather than a source-text grep. See
	 * admin-app/src/lib/display-preview.js and tests/js/display-preview.test.js.
	 */
	const previewRows = buildPreviewRows(
		currencies,
		baseCurrency,
		baseSymbol
	);

	const widgetCurrencies = productWidget.currencies || [];

	const currencyOptions = currencies.map( ( c ) => ( {
		label: c.code,
		value: c.code,
	} ) );

	const availableWidgetCurrencies = currencyOptions.filter(
		( c ) => ! widgetCurrencies.includes( c.value )
	);

	const handleAddWidgetCurrency = ( code ) => {
		if ( ! code || widgetCurrencies.includes( code ) ) {
			return;
		}

		updateProductWidget(
			'currencies',
			[ ...widgetCurrencies, code ].slice( 0, MAX_WIDGET_CURRENCIES )
		);
	};

	const handleRemoveWidgetCurrency = ( code ) => {
		updateProductWidget(
			'currencies',
			widgetCurrencies.filter( ( c ) => c !== code )
		);
	};

	const widgetChipsLabelId = useId();

	return (
		<div className="mhmcs-tab-content">
			<h3>{ __( 'Live Preview', 'mhm-currency-switcher' ) }</h3>

			<div className="mhmcs-switcher-preview">
				<div
					className={ `mhmcs-preview-switcher mhmcs-preview-${
						switcher.size || 'medium'
					}` }
				>
					{ previewRows.map( ( row ) => (
						<span key={ row.code } className="mhmcs-preview-item">
							{ switcher.show_flag !== false && (
								<span className="mhmcs-preview-flag">
									{ row.code.substring( 0, 2 ) }
								</span>
							) }
							{ switcher.show_symbol !== false && (
								<span className="mhmcs-preview-symbol">
									{ row.symbol }
								</span>
							) }
							{ switcher.show_code !== false && (
								<span className="mhmcs-preview-code">
									{ row.code }
								</span>
							) }
							{ switcher.show_name === true && (
								<span className="mhmcs-preview-name">
									{ wcCurrencies[ row.code ] || row.code }
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

			<hr />

			<h3>{ __( 'Switcher Appearance', 'mhm-currency-switcher' ) }</h3>

			<div className="mhmcs-settings-group">
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
					checked={ switcher.show_name === true }
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

			<div className="mhmcs-settings-group">
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
						<div className="mhmcs-chip-field">
							<span
								id={ widgetChipsLabelId }
								className="mhmcs-chip-field__label components-base-control__label"
							>
								{ __(
									'Currencies to display',
									'mhm-currency-switcher'
								) }
							</span>

							<div
								className="mhmcs-chip-list"
								role="group"
								aria-labelledby={ widgetChipsLabelId }
							>
								{ 0 === widgetCurrencies.length && (
									<span className="mhmcs-chip-empty">
										{ __(
											'No currencies selected yet.',
											'mhm-currency-switcher'
										) }
									</span>
								) }
								{ widgetCurrencies.map( ( code ) => (
									<span key={ code } className="mhmcs-chip">
										<span className="mhmcs-chip__code">
											{ code }
										</span>
										<button
											type="button"
											className="mhmcs-chip__remove"
											onClick={ () =>
												handleRemoveWidgetCurrency(
													code
												)
											}
											aria-label={ sprintf(
												/* translators: %s: currency code, for example EUR. */
												__(
													'Remove %s from the product price widget.',
													'mhm-currency-switcher'
												),
												code
											) }
										>
											&times;
										</button>
									</span>
								) ) }
							</div>

							{ widgetCurrencies.length <
								MAX_WIDGET_CURRENCIES && (
								<CurrencyPicker
									currencies={ availableWidgetCurrencies }
									value=""
									onChange={ handleAddWidgetCurrency }
									wcCurrencies={ wcCurrencies }
								/>
							) }

							<span className="mhmcs-chip-counter">
								{ sprintf(
									/* translators: 1: how many currencies are selected. 2: the maximum, for example 5. */
									__(
										'%1$d / %2$d selected',
										'mhm-currency-switcher'
									),
									widgetCurrencies.length,
									MAX_WIDGET_CURRENCIES
								) }
							</span>
						</div>

						<ToggleControl
							label={ __(
								'Show flags in widget',
								'mhm-currency-switcher'
							) }
							checked={ productWidget.show_flags !== false }
							onChange={ ( val ) =>
								updateProductWidget( 'show_flags', val )
							}
							__nextHasNoMarginBottom
						/>
					</>
				) }
			</div>
		</div>
	);
};

export default DisplayOptions;
