/**
 * ManageCurrencies tab — currency table with rate, fee, and ordering controls.
 *
 * @package MhmCurrencySwitcher
 */

import { useState } from '@wordpress/element';
import {
	Button,
	SelectControl,
	TextControl,
	ToggleControl,
	Notice,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * ManageCurrencies tab component.
 *
 * @param {Object}   props              Component props.
 * @param {Array}    props.currencies   Array of currency config objects.
 * @param {Function} props.onChange      Callback when currencies change.
 * @param {boolean}  props.isPro        Whether Pro license is active.
 * @param {string}   props.baseCurrency WooCommerce base currency code.
 * @param {Object}   props.wcCurrencies Map of code => label for WC currencies.
 * @param {Function} props.onSyncRates  Callback to trigger rate sync.
 * @param {boolean}  props.syncing      Whether a rate sync is in progress.
 * @return {JSX.Element} ManageCurrencies tab.
 */
const ManageCurrencies = ( {
	currencies,
	onChange,
	isPro,
	baseCurrency,
	wcCurrencies,
	onSyncRates,
	syncing,
} ) => {
	const [ showAddForm, setShowAddForm ] = useState( false );
	const [ newCurrencyCode, setNewCurrencyCode ] = useState( '' );

	const limit = isPro ? Infinity : 2;
	const usedCount = currencies.length;
	const limitLabel = isPro
		? __( 'Unlimited', 'mhm-currency-switcher' )
		: `${ usedCount }/${ limit }`;

	// Build available currencies for the "add" dropdown.
	const usedCodes = currencies.map( ( c ) => c.code );
	const availableCurrencies = Object.keys( wcCurrencies || {} )
		.filter( ( code ) => ! usedCodes.includes( code ) && code !== baseCurrency )
		.map( ( code ) => ( {
			label: `${ code } — ${ wcCurrencies[ code ] }`,
			value: code,
		} ) );

	const handleAdd = () => {
		if ( ! newCurrencyCode ) {
			return;
		}

		if ( ! isPro && usedCount >= limit ) {
			return;
		}

		const newCurrency = {
			code: newCurrencyCode,
			enabled: true,
			rate: {
				type: 'auto',
				value: 1,
			},
			fee: {
				type: 'none',
				value: 0,
			},
			format: {},
		};

		onChange( [ ...currencies, newCurrency ] );
		setNewCurrencyCode( '' );
		setShowAddForm( false );
	};

	const handleRemove = ( index ) => {
		const updated = currencies.filter( ( _, i ) => i !== index );
		onChange( updated );
	};

	const handleToggle = ( index ) => {
		const updated = [ ...currencies ];
		updated[ index ] = {
			...updated[ index ],
			enabled: ! updated[ index ].enabled,
		};
		onChange( updated );
	};

	const handleRateTypeChange = ( index, type ) => {
		const updated = [ ...currencies ];
		updated[ index ] = {
			...updated[ index ],
			rate: { ...updated[ index ].rate, type },
		};
		onChange( updated );
	};

	const handleRateValueChange = ( index, value ) => {
		const updated = [ ...currencies ];
		updated[ index ] = {
			...updated[ index ],
			rate: { ...updated[ index ].rate, value: parseFloat( value ) || 0 },
		};
		onChange( updated );
	};

	const handleFeeTypeChange = ( index, type ) => {
		const updated = [ ...currencies ];
		updated[ index ] = {
			...updated[ index ],
			fee: { ...updated[ index ].fee, type },
		};
		onChange( updated );
	};

	const handleFeeValueChange = ( index, value ) => {
		const updated = [ ...currencies ];
		updated[ index ] = {
			...updated[ index ],
			fee: { ...updated[ index ].fee, value: parseFloat( value ) || 0 },
		};
		onChange( updated );
	};

	const handleMoveUp = ( index ) => {
		if ( index === 0 ) {
			return;
		}
		const updated = [ ...currencies ];
		[ updated[ index - 1 ], updated[ index ] ] = [
			updated[ index ],
			updated[ index - 1 ],
		];
		onChange( updated );
	};

	const handleMoveDown = ( index ) => {
		if ( index === currencies.length - 1 ) {
			return;
		}
		const updated = [ ...currencies ];
		[ updated[ index ], updated[ index + 1 ] ] = [
			updated[ index + 1 ],
			updated[ index ],
		];
		onChange( updated );
	};

	return (
		<div className="mhm-cs-tab-content">
			<div className="mhm-cs-currencies-header">
				<h3>
					{ __( 'Currencies', 'mhm-currency-switcher' ) }
					<span className="mhm-cs-currency-count">
						{ ' ' }({ limitLabel }{ ' ' }
						{ __( 'currencies used', 'mhm-currency-switcher' ) })
					</span>
				</h3>
				<div className="mhm-cs-currencies-actions">
					<Button
						variant="secondary"
						onClick={ onSyncRates }
						disabled={ syncing }
						icon={ syncing ? undefined : 'update' }
					>
						{ syncing ? (
							<>
								<Spinner />{ ' ' }
								{ __( 'Syncing...', 'mhm-currency-switcher' ) }
							</>
						) : (
							__( 'Sync Rates', 'mhm-currency-switcher' )
						) }
					</Button>
					<Button
						variant="primary"
						onClick={ () => setShowAddForm( ! showAddForm ) }
						disabled={ ! isPro && usedCount >= limit }
					>
						{ __( '+ New Currency', 'mhm-currency-switcher' ) }
					</Button>
				</div>
			</div>

			{ ! isPro && usedCount >= limit && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Free version is limited to 2 currencies. Upgrade to Pro for unlimited currencies.',
						'mhm-currency-switcher'
					) }
				</Notice>
			) }

			{ showAddForm && (
				<div className="mhm-cs-add-currency-form">
					<SelectControl
						label={ __( 'Currency', 'mhm-currency-switcher' ) }
						value={ newCurrencyCode }
						options={ [
							{
								label: __(
									'Select a currency...',
									'mhm-currency-switcher'
								),
								value: '',
							},
							...availableCurrencies,
						] }
						onChange={ setNewCurrencyCode }
					/>
					<Button variant="primary" onClick={ handleAdd }>
						{ __( 'Add', 'mhm-currency-switcher' ) }
					</Button>
					<Button
						variant="tertiary"
						onClick={ () => setShowAddForm( false ) }
					>
						{ __( 'Cancel', 'mhm-currency-switcher' ) }
					</Button>
				</div>
			) }

			<p className="mhm-cs-base-currency-note">
				{ __( 'Base currency:', 'mhm-currency-switcher' ) }{ ' ' }
				<strong>{ baseCurrency }</strong>{ ' ' }
				<span className="description">
					({ __(
						'Set in WooCommerce > Settings > General',
						'mhm-currency-switcher'
					) })
				</span>
			</p>

			<table className="mhm-cs-currency-table widefat">
				<thead>
					<tr>
						<th>{ __( 'Enabled', 'mhm-currency-switcher' ) }</th>
						<th>{ __( 'Code', 'mhm-currency-switcher' ) }</th>
						<th>{ __( 'Rate', 'mhm-currency-switcher' ) }</th>
						<th>{ __( 'Fee', 'mhm-currency-switcher' ) }</th>
						<th>{ __( 'Order', 'mhm-currency-switcher' ) }</th>
						<th>{ __( 'Actions', 'mhm-currency-switcher' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ currencies.length === 0 && (
						<tr>
							<td colSpan="6" className="mhm-cs-empty-row">
								{ __(
									'No currencies configured. Click "+ New Currency" to add one.',
									'mhm-currency-switcher'
								) }
							</td>
						</tr>
					) }
					{ currencies.map( ( currency, index ) => (
						<tr
							key={ currency.code }
							className={
								! currency.enabled ? 'mhm-cs-row-disabled' : ''
							}
						>
							<td>
								<ToggleControl
									checked={ currency.enabled }
									onChange={ () => handleToggle( index ) }
									__nextHasNoMarginBottom
								/>
							</td>
							<td>
								<strong>{ currency.code }</strong>
								{ wcCurrencies &&
									wcCurrencies[ currency.code ] && (
									<br />
								) }
								<span className="description">
									{ wcCurrencies &&
											wcCurrencies[ currency.code ] }
								</span>
							</td>
							<td>
								<div className="mhm-cs-rate-cell">
									<SelectControl
										value={ currency.rate?.type || 'auto' }
										options={ [
											{
												label: __(
													'Auto',
													'mhm-currency-switcher'
												),
												value: 'auto',
											},
											{
												label: __(
													'Manual',
													'mhm-currency-switcher'
												),
												value: 'manual',
											},
										] }
										onChange={ ( val ) =>
											handleRateTypeChange( index, val )
										}
										__nextHasNoMarginBottom
									/>
									<TextControl
										type="number"
										step="0.000001"
										value={ currency.rate?.value || '' }
										onChange={ ( val ) =>
											handleRateValueChange( index, val )
										}
										disabled={
											currency.rate?.type === 'auto'
										}
										__nextHasNoMarginBottom
									/>
								</div>
							</td>
							<td>
								<div className="mhm-cs-fee-cell">
									<SelectControl
										value={ currency.fee?.type || 'none' }
										options={ [
											{
												label: __(
													'None',
													'mhm-currency-switcher'
												),
												value: 'none',
											},
											{
												label: __(
													'Percent',
													'mhm-currency-switcher'
												),
												value: 'percent',
											},
											{
												label: __(
													'Fixed',
													'mhm-currency-switcher'
												),
												value: 'fixed',
											},
										] }
										onChange={ ( val ) =>
											handleFeeTypeChange( index, val )
										}
										__nextHasNoMarginBottom
									/>
									{ currency.fee?.type !== 'none' && (
										<TextControl
											type="number"
											step="0.01"
											value={
												currency.fee?.value || ''
											}
											onChange={ ( val ) =>
												handleFeeValueChange(
													index,
													val
												)
											}
											__nextHasNoMarginBottom
										/>
									) }
								</div>
							</td>
							<td>
								<div className="mhm-cs-order-buttons">
									<Button
										icon="arrow-up-alt"
										label={ __(
											'Move up',
											'mhm-currency-switcher'
										) }
										onClick={ () =>
											handleMoveUp( index )
										}
										disabled={ index === 0 }
										size="small"
									/>
									<Button
										icon="arrow-down-alt"
										label={ __(
											'Move down',
											'mhm-currency-switcher'
										) }
										onClick={ () =>
											handleMoveDown( index )
										}
										disabled={
											index === currencies.length - 1
										}
										size="small"
									/>
								</div>
							</td>
							<td>
								<Button
									isDestructive
									variant="tertiary"
									onClick={ () => handleRemove( index ) }
									icon="trash"
									label={ __(
										'Remove',
										'mhm-currency-switcher'
									) }
									size="small"
								/>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
};

export default ManageCurrencies;
