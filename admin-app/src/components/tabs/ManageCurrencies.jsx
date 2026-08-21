/**
 * ManageCurrencies tab — currency table with rate, fee, and ordering controls.
 *
 * @package
 */

import { Fragment, useEffect, useState } from '@wordpress/element';
import {
	Button,
	SelectControl,
	TextControl,
	ToggleControl,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { previewRates } from '../../api/settings';
import CurrencyPicker from '../shared/CurrencyPicker';
import {
	FRESHNESS,
	freshnessState,
	formatHumanAge,
	formatRowUpdatedAgo,
} from '../../lib/freshness';

/**
 * ManageCurrencies tab component.
 *
 * @param {Object}   props                    Component props.
 * @param {Array}    props.currencies         Array of currency config objects.
 * @param {Function} props.onChange           Callback when currencies change.
 * @param {string}   props.baseCurrency       WooCommerce base currency code.
 * @param {Object}   props.wcCurrencies       Map of code => label for WC currencies.
 * @param {Function} props.onSyncRates        Callback to trigger rate sync.
 * @param {boolean}  props.syncing            Whether a rate sync is in progress.
 * @param {?Object}  props.lastSync           Stored { time, base } from the last
 *                                            successful sync, or null when none has
 *                                            ever been recorded.
 * @param {string}   props.rateUpdateInterval Configured rate_update_interval
 *                                            ('manual' | 'hourly' | 'twicedaily' | 'daily').
 * @return {JSX.Element} ManageCurrencies tab.
 */
/**
 * Get the flag image URL for a currency code.
 *
 * @param {string} code ISO 4217 currency code.
 * @return {string} Flag SVG URL.
 */
const getFlagUrl = ( code ) => {
	const { flagBaseUrl = '', flagMap = {} } = window.mhmCsAdmin || {};
	const country = flagMap[ code ] || code.substring( 0, 2 ).toLowerCase();
	return flagBaseUrl + country + '.svg';
};

const ManageCurrencies = ( {
	currencies,
	onChange,
	baseCurrency,
	wcCurrencies,
	onSyncRates,
	syncing,
	lastSync,
	rateUpdateInterval,
} ) => {
	const [ showAddForm, setShowAddForm ] = useState( false );
	const [ newCurrencyCode, setNewCurrencyCode ] = useState( '' );
	// Which row's format drawer is open, by currency code. A single value,
	// not a set: only one drawer is ever open at a time.
	const [ openDrawer, setOpenDrawer ] = useState( null );
	// Keyed by currency code. Populated from the server's own preview
	// response — never computed here.
	const [ preview, setPreview ] = useState( {} );

	// 🔴 The samples are computed by the server and never in the browser.
	// Re-implementing rate → fee → rounding → format in JS would create a
	// second source of truth for the one thing this plugin exists to get
	// right, and this repository has no React test runner to hold it honest.
	useEffect( () => {
		const controller = new AbortController();
		const timer = setTimeout( () => {
			previewRates(
				{ base_currency: baseCurrency, currencies },
				controller.signal
			)
				.then( ( data ) => {
					const byCode = {};
					( data?.rates || [] ).forEach( ( row ) => {
						byCode[ row.code ] = row;
					} );
					setPreview( byCode );
				} )
				.catch( () => {
					// A superseded or failed preview is not an error the shop
					// owner needs to see: the strip simply shows nothing
					// rather than a number that might be stale.
					setPreview( {} );
				} );
		}, 400 );

		return () => {
			clearTimeout( timer );
			controller.abort();
		};
	}, [ currencies, baseCurrency ] );

	// Build available currencies for the "add" dropdown.
	const usedCodes = currencies.map( ( c ) => c.code );
	const availableCurrencies = Object.keys( wcCurrencies || {} )
		.filter(
			( code ) => ! usedCodes.includes( code ) && code !== baseCurrency
		)
		.map( ( code ) => ( {
			label: `${ code } — ${ wcCurrencies[ code ] }`,
			value: code,
		} ) );

	// Computed once per render and shared by the header pill and every row's
	// status line — a sync timestamps every automatic currency alike, so
	// there is exactly one "how long ago" to say, not one per row.
	const nowSeconds = Math.floor( Date.now() / 1000 );
	const humanAge = lastSync?.time
		? formatHumanAge( Math.max( 0, nowSeconds - lastSync.time ) )
		: '';

	const state = freshnessState(
		currencies,
		lastSync,
		baseCurrency,
		rateUpdateInterval,
		nowSeconds
	);

	// MANUAL_ONLY has no entry here on purpose: a shop with no automatic
	// currency has nothing for a sync signal to say, and the lookup below
	// resolves to undefined for it. Guarded with `{ pill && ( … ) }`.
	const pill = {
		[ FRESHNESS.NO_RECORD ]: {
			tone: 'warn',
			text: __( 'No sync recorded yet', 'mhm-currency-switcher' ),
		},
		[ FRESHNESS.STALE_BASE ]: {
			tone: 'warn',
			text: __(
				"The store's base currency changed since the last sync — rates need re-syncing.",
				'mhm-currency-switcher'
			),
		},
		[ FRESHNESS.STALE_AGE ]: {
			tone: 'warn',
			text: sprintf(
				/* translators: %s: a human-readable interval, for example "3 days". */
				__( 'Rates last updated %s ago', 'mhm-currency-switcher' ),
				humanAge
			),
		},
		[ FRESHNESS.FRESH ]: {
			tone: 'ok',
			text: sprintf(
				/* translators: %s: a human-readable interval, for example "2 hours". */
				__( 'Rates updated %s ago', 'mhm-currency-switcher' ),
				humanAge
			),
		},
	}[ state ];

	/**
	 * The per-row freshness line, in the same first-match order as the pill.
	 *
	 * Reads `currency.rate.updated_at` — RateProvider::apply_rates()'s
	 * per-row sync stamp — rather than the global `lastSync`/`humanAge` the
	 * header pill uses. The global option can only describe the last BATCH;
	 * it says nothing about whether THIS row's number came from it. A row
	 * flipped from manual to auto keeps its hand-typed value until the next
	 * real sync touches it, and dating that value with the batch's timestamp
	 * would describe a sync that never produced it.
	 *
	 * @param {Object} currency One currency config row.
	 * @return {{tone: string, text: string}} Tone and text to render.
	 */
	const rowStatus = ( currency ) => {
		if ( ( currency.rate?.type || 'auto' ) === 'manual' ) {
			return {
				tone: 'warn',
				text: __( 'entered manually', 'mhm-currency-switcher' ),
			};
		}

		if ( ! ( currency.rate?.value > 0 ) ) {
			// The currency is configured but invisible: has_usable_rate() is
			// false, so Switcher.php and ProductWidget.php both leave it out.
			// Nothing said so before this line existed.
			return {
				tone: 'warn',
				text: __(
					'No rate yet — this currency is not shown in the store',
					'mhm-currency-switcher'
				),
			};
		}

		const updatedAt = currency.rate?.updated_at;

		if ( ! updatedAt ) {
			return {
				tone: 'muted',
				text: __(
					'rate saved, no sync recorded',
					'mhm-currency-switcher'
				),
			};
		}

		// The row's own timestamp is real — a sync produced this exact
		// value — but the pill can still be warning that the BATCH it came
		// from is against the wrong base, or old enough to call stale. A
		// confident green row under an amber pill reads as disagreement, so
		// tone defers to the pill whenever it is warning; the text is left
		// alone, since the fact it states ("updated N ago") is still true.
		const tone = pill && 'warn' === pill.tone ? 'warn' : 'ok';

		return {
			tone,
			text: formatRowUpdatedAgo( Math.max( 0, nowSeconds - updatedAt ) ),
		};
	};

	const handleAdd = () => {
		if ( ! newCurrencyCode ) {
			return;
		}

		const newCurrency = {
			code: newCurrencyCode,
			enabled: true,
			rate: {
				type: 'auto',
				// 🔴 Zero, not one. Saving a currency triggers no rate fetch, so
				// whatever is seeded here is what the shop converts at until the
				// first successful sync. A seed of 1 meant one-to-one: a $40
				// product showed as "€40", with the panel and the server in
				// perfect agreement about a number nobody had ever fetched.
				//
				// Zero says what is true — no rate yet — and `has_usable_rate()`
				// then keeps the currency out of the switcher and the product
				// widget until a sync fills it in. A currency that has not
				// appeared yet is recoverable; a wrong price nobody can see is
				// not.
				value: 0,
			},
			fee: {
				type: 'none',
				value: 0,
			},
			rounding: {
				type: 'disabled',
				value: 0,
				subtract: 0,
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
		const rate = { ...updated[ index ].rate, type };

		// Any type change invalidates the sync provenance a leftover
		// `updated_at` would claim. Going manual opens the value up for
		// hand-editing; going back to auto without an intervening sync means
		// whatever value is now in place — possibly just hand-edited — was
		// not produced by the sync that stamp describes. Dropping it here is
		// what makes rowStatus's "no updated_at" branch ("rate saved, no
		// sync recorded") the honest fallback until a real sync runs again.
		delete rate.updated_at;

		updated[ index ] = {
			...updated[ index ],
			rate,
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

	const handleRoundingChange = ( index, field, value ) => {
		const updated = [ ...currencies ];
		updated[ index ] = {
			...updated[ index ],
			rounding: {
				...updated[ index ].rounding,
				[ field ]: field === 'type' ? value : parseFloat( value ) || 0,
			},
		};
		onChange( updated );
	};

	const handleFormatChange = ( index, field, value ) => {
		const updated = [ ...currencies ];
		updated[ index ] = {
			...updated[ index ],
			format: { ...( updated[ index ].format || {} ), [ field ]: value },
		};
		onChange( updated );
	};

	const toggleDrawer = ( code ) => {
		setOpenDrawer( ( current ) => ( current === code ? null : code ) );
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

	const columnLabels = {
		enabled: __( 'Enabled', 'mhm-currency-switcher' ),
		currency: __( 'Currency', 'mhm-currency-switcher' ),
		rate: __( 'Rate', 'mhm-currency-switcher' ),
		fee: __( 'Fee', 'mhm-currency-switcher' ),
		rounding: __( 'Rounding', 'mhm-currency-switcher' ),
		order: __( 'Order', 'mhm-currency-switcher' ),
		actions: __( 'Actions', 'mhm-currency-switcher' ),
	};

	return (
		<div className="mhm-cs-tab-content">
			<div className="mhm-cs-currencies-header">
				<div className="mhm-cs-currencies-heading">
					<h3>{ __( 'Currencies', 'mhm-currency-switcher' ) }</h3>
					{ pill && (
						<span
							className={ `mhm-cs-status mhm-cs-status--${ pill.tone }` }
						>
							{ pill.text }
						</span>
					) }
				</div>
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
								{ __( 'Syncing…', 'mhm-currency-switcher' ) }
							</>
						) : (
							__( 'Sync Rates', 'mhm-currency-switcher' )
						) }
					</Button>
					<Button
						variant="primary"
						onClick={ () => setShowAddForm( ! showAddForm ) }
					>
						{ __( '+ New Currency', 'mhm-currency-switcher' ) }
					</Button>
				</div>
			</div>

			{ showAddForm && (
				<div className="mhm-cs-add-currency-form">
					<CurrencyPicker
						currencies={ availableCurrencies }
						value={ newCurrencyCode }
						onChange={ setNewCurrencyCode }
						wcCurrencies={ wcCurrencies || {} }
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
					(
					{ __(
						'Set in WooCommerce > Settings > General',
						'mhm-currency-switcher'
					) }
					)
				</span>
			</p>

			<div className="mhm-cs-currency-grid" role="table">
				<div className="mhm-cs-row mhm-cs-row--head" role="row">
					<div className="mhm-cs-cell" role="columnheader">
						{ columnLabels.enabled }
					</div>
					<div className="mhm-cs-cell" role="columnheader">
						{ columnLabels.currency }
					</div>
					<div className="mhm-cs-cell" role="columnheader">
						{ columnLabels.rate }
					</div>
					<div className="mhm-cs-cell" role="columnheader">
						{ columnLabels.fee }
					</div>
					<div className="mhm-cs-cell" role="columnheader">
						{ columnLabels.rounding }
					</div>
					<div className="mhm-cs-cell" role="columnheader">
						{ columnLabels.order }
					</div>
					<div className="mhm-cs-cell" role="columnheader">
						{ columnLabels.actions }
					</div>
				</div>

				{ currencies.length === 0 && (
					<div className="mhm-cs-empty-row">
						{ __(
							'No currencies configured. Click "+ New Currency" to add one.',
							'mhm-currency-switcher'
						) }
					</div>
				) }

				{ currencies.map( ( currency, index ) => {
					const status = rowStatus( currency );

					const rowPreview = preview[ currency.code ];

					return (
						<Fragment key={ currency.code }>
							<div
								className={ `mhm-cs-row${
									! currency.enabled
										? ' mhm-cs-row--disabled'
										: ''
								}` }
								role="row"
							>
								<div
									className="mhm-cs-cell"
									role="cell"
									data-label={ columnLabels.enabled }
								>
									<ToggleControl
										label={ sprintf(
											/* translators: %s: currency code, for example EUR. */
											__(
												'Enable %s',
												'mhm-currency-switcher'
											),
											currency.code
										) }
										checked={ currency.enabled }
										onChange={ () => handleToggle( index ) }
										__nextHasNoMarginBottom
									/>
								</div>
								<div
									className="mhm-cs-cell mhm-cs-cell--currency"
									role="cell"
									data-label={ columnLabels.currency }
								>
									<div className="mhm-cs-currency-code-cell">
										<img
											src={ getFlagUrl( currency.code ) }
											alt={ currency.code }
											className="mhm-cs-admin-flag"
											width="24"
											height="18"
										/>
										<div>
											<strong>{ currency.code }</strong>
											{ wcCurrencies &&
												wcCurrencies[
													currency.code
												] && (
													<>
														<br />
														<span className="description">
															{
																wcCurrencies[
																	currency
																		.code
																]
															}
														</span>
													</>
												) }
										</div>
									</div>
									<span
										className={ `mhm-cs-status mhm-cs-status--${ status.tone }` }
									>
										{ status.text }
									</span>
								</div>
								<div
									className="mhm-cs-cell"
									role="cell"
									data-label={ columnLabels.rate }
								>
									<div className="mhm-cs-rate-cell">
										<SelectControl
											__next40pxDefaultSize
											label={ sprintf(
												/* translators: %s: currency code, for example EUR. */
												__(
													'Rate type for %s',
													'mhm-currency-switcher'
												),
												currency.code
											) }
											hideLabelFromVision
											value={
												currency.rate?.type || 'auto'
											}
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
												handleRateTypeChange(
													index,
													val
												)
											}
											__nextHasNoMarginBottom
										/>
										<TextControl
											__next40pxDefaultSize
											type="number"
											step="0.000001"
											label={ sprintf(
												/* translators: %s: currency code, for example EUR. */
												__(
													'Exchange rate for %s',
													'mhm-currency-switcher'
												),
												currency.code
											) }
											hideLabelFromVision
											value={ currency.rate?.value || '' }
											onChange={ ( val ) =>
												handleRateValueChange(
													index,
													val
												)
											}
											disabled={
												currency.rate?.type === 'auto'
											}
											__nextHasNoMarginBottom
										/>
									</div>
								</div>
								<div
									className="mhm-cs-cell"
									role="cell"
									data-label={ columnLabels.fee }
								>
									<div className="mhm-cs-fee-cell">
										<SelectControl
											__next40pxDefaultSize
											label={ sprintf(
												/* translators: %s: currency code, for example EUR. */
												__(
													'Fee type for %s',
													'mhm-currency-switcher'
												),
												currency.code
											) }
											hideLabelFromVision
											value={
												currency.fee?.type || 'none'
											}
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
													value: 'percentage',
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
												handleFeeTypeChange(
													index,
													val
												)
											}
											__nextHasNoMarginBottom
										/>
										{ currency.fee?.type !== 'none' && (
											<TextControl
												__next40pxDefaultSize
												type="number"
												step="0.01"
												label={ sprintf(
													/* translators: %s: currency code, for example EUR. */
													__(
														'Fee amount for %s',
														'mhm-currency-switcher'
													),
													currency.code
												) }
												hideLabelFromVision
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
								</div>
								<div
									className="mhm-cs-cell"
									role="cell"
									data-label={ columnLabels.rounding }
								>
									<div className="mhm-cs-rounding-cell">
										<SelectControl
											__next40pxDefaultSize
											label={ sprintf(
												/* translators: %s: currency code, for example EUR. */
												__(
													'Rounding mode for %s',
													'mhm-currency-switcher'
												),
												currency.code
											) }
											hideLabelFromVision
											value={
												currency.rounding?.type ||
												'disabled'
											}
											options={ [
												{
													label: __(
														'None',
														'mhm-currency-switcher'
													),
													value: 'disabled',
												},
												{
													label: __(
														'Nearest',
														'mhm-currency-switcher'
													),
													value: 'nearest',
												},
												{
													label: __(
														'Round up',
														'mhm-currency-switcher'
													),
													value: 'up',
												},
												{
													label: __(
														'Round down',
														'mhm-currency-switcher'
													),
													value: 'down',
												},
											] }
											onChange={ ( val ) =>
												handleRoundingChange(
													index,
													'type',
													val
												)
											}
											__nextHasNoMarginBottom
										/>
										{ ( currency.rounding?.type ||
											'disabled' ) !== 'disabled' && (
											<>
												<TextControl
													__next40pxDefaultSize
													label={ sprintf(
														/* translators: %s: currency code, for example EUR. */
														__(
															'Rounding step for %s',
															'mhm-currency-switcher'
														),
														currency.code
													) }
													hideLabelFromVision
													type="number"
													step="0.01"
													value={
														currency.rounding
															?.value || ''
													}
													onChange={ ( val ) =>
														handleRoundingChange(
															index,
															'value',
															val
														)
													}
													__nextHasNoMarginBottom
												/>
												<TextControl
													__next40pxDefaultSize
													type="number"
													step="0.01"
													label={ sprintf(
														/* translators: %s: currency code, for example EUR. */
														__(
															'Subtract for %s',
															'mhm-currency-switcher'
														),
														currency.code
													) }
													hideLabelFromVision
													placeholder={ __(
														'Subtract',
														'mhm-currency-switcher'
													) }
													value={
														currency.rounding
															?.subtract || ''
													}
													onChange={ ( val ) =>
														handleRoundingChange(
															index,
															'subtract',
															val
														)
													}
													__nextHasNoMarginBottom
												/>
											</>
										) }
									</div>
								</div>
								<div
									className="mhm-cs-cell"
									role="cell"
									data-label={ columnLabels.order }
								>
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
								</div>
								<div
									className="mhm-cs-cell"
									role="cell"
									data-label={ columnLabels.actions }
								>
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
								</div>
							</div>

							<div className="mhm-cs-preview-strip">
								<span>
									{ sprintf(
										/* translators: 1: an amount in the store's currency, for example "100,00 $". 2: the same amount converted, for example "3.518,99 ₺". */
										__(
											'Customer sees: %1$s → %2$s',
											'mhm-currency-switcher'
										),
										rowPreview?.sample_from || '—',
										rowPreview?.sample_to || '—'
									) }
								</span>
								<Button
									variant="link"
									onClick={ () =>
										toggleDrawer( currency.code )
									}
									aria-expanded={
										openDrawer === currency.code
									}
								>
									{ openDrawer === currency.code
										? __( 'Close', 'mhm-currency-switcher' )
										: __(
												'Edit format',
												'mhm-currency-switcher'
										  ) }
								</Button>
							</div>

							{ openDrawer === currency.code && (
								<div className="mhm-cs-format-drawer">
									<h4 className="mhm-cs-format-drawer__heading">
										{ sprintf(
											/* translators: %s: currency code, for example TRY. */
											__(
												'Number format for %s',
												'mhm-currency-switcher'
											),
											currency.code
										) }
									</h4>
									<p className="mhm-cs-format-drawer__reference">
										{ sprintf(
											/* translators: %s: the store's own base-currency amount, in the store's own format, for example "100,00 $". */
											__(
												'Store setting: %s',
												'mhm-currency-switcher'
											),
											rowPreview?.sample_from || '—'
										) }
									</p>
									<div className="mhm-cs-format-fields">
										<TextControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											label={ __(
												'Symbol',
												'mhm-currency-switcher'
											) }
											value={
												currency.format?.symbol || ''
											}
											onChange={ ( val ) =>
												handleFormatChange(
													index,
													'symbol',
													val
												)
											}
										/>
										<SelectControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											label={ __(
												'Position',
												'mhm-currency-switcher'
											) }
											value={
												currency.format?.position ||
												'left'
											}
											options={ [
												{
													label: __(
														'Left',
														'mhm-currency-switcher'
													),
													value: 'left',
												},
												{
													label: __(
														'Right',
														'mhm-currency-switcher'
													),
													value: 'right',
												},
												{
													label: __(
														'Left, with space',
														'mhm-currency-switcher'
													),
													value: 'left_space',
												},
												{
													label: __(
														'Right, with space',
														'mhm-currency-switcher'
													),
													value: 'right_space',
												},
											] }
											onChange={ ( val ) =>
												handleFormatChange(
													index,
													'position',
													val
												)
											}
										/>
										<TextControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											type="number"
											min="0"
											max="4"
											label={ __(
												'Decimals',
												'mhm-currency-switcher'
											) }
											value={
												currency.format?.decimals ?? ''
											}
											onChange={ ( val ) =>
												handleFormatChange(
													index,
													'decimals',
													val
												)
											}
										/>
										<TextControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											maxLength="1"
											label={ __(
												'Decimal separator',
												'mhm-currency-switcher'
											) }
											value={
												currency.format?.decimal_sep ||
												''
											}
											onChange={ ( val ) =>
												handleFormatChange(
													index,
													'decimal_sep',
													val
												)
											}
										/>
										<TextControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											maxLength="1"
											label={ __(
												'Thousand separator',
												'mhm-currency-switcher'
											) }
											value={
												currency.format?.thousand_sep ||
												''
											}
											onChange={ ( val ) =>
												handleFormatChange(
													index,
													'thousand_sep',
													val
												)
											}
										/>
									</div>
								</div>
							) }
						</Fragment>
					);
				} ) }
			</div>
		</div>
	);
};

export default ManageCurrencies;
