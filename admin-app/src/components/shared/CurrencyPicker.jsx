/**
 * CurrencyPicker — searchable dropdown with flags and popular currencies.
 *
 * @package
 */

import { useState, useRef, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Popular currency codes — shown at the top of the dropdown.
 *
 * @type {string[]}
 */
const POPULAR_CODES = [
	'USD',
	'EUR',
	'GBP',
	'TRY',
	'JPY',
	'CAD',
	'AUD',
	'CHF',
	'CNY',
	'INR',
	'BRL',
	'KRW',
	'MXN',
	'SGD',
	'HKD',
	'SEK',
	'NOK',
	'DKK',
	'NZD',
	'ZAR',
];

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

/**
 * FlagIcon — shows flag image with fallback placeholder on error.
 *
 * @param {Object} props       Component props.
 * @param {string} props.code  Currency code.
 * @param {number} props.width Image width.
 * @param {number} props.height Image height.
 * @return {JSX.Element} Flag image or placeholder.
 */
const FlagIcon = ( { code, width = 20, height = 15 } ) => {
	const [ failed, setFailed ] = useState( false );

	const handleError = useCallback( () => {
		setFailed( true );
	}, [] );

	if ( failed ) {
		return (
			<span
				className="mhm-cs-picker-flag mhm-cs-flag-placeholder"
				style={ { width: `${ width }px`, height: `${ height }px` } }
			>
				{ code.substring( 0, 2 ) }
			</span>
		);
	}

	return (
		<img
			src={ getFlagUrl( code ) }
			alt={ code }
			className="mhm-cs-picker-flag"
			width={ width }
			height={ height }
			onError={ handleError }
		/>
	);
};

/**
 * CurrencyPicker component.
 *
 * @param {Object}   props              Component props.
 * @param {Array}    props.currencies   Available currencies [{label, value, name}].
 * @param {string}   props.value        Currently selected currency code.
 * @param {Function} props.onChange      Callback when selection changes.
 * @param {Object}   props.wcCurrencies Map of code => label for WC currencies.
 * @return {JSX.Element} CurrencyPicker.
 */
const CurrencyPicker = ( { currencies, value, onChange, wcCurrencies } ) => {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const containerRef = useRef( null );
	const searchRef = useRef( null );

	// Close on outside click.
	useEffect( () => {
		const handleClickOutside = ( e ) => {
			if (
				containerRef.current &&
				! containerRef.current.contains( e.target )
			) {
				setIsOpen( false );
				setSearch( '' );
			}
		};
		document.addEventListener( 'mousedown', handleClickOutside );
		return () =>
			document.removeEventListener( 'mousedown', handleClickOutside );
	}, [] );

	// Focus search input when dropdown opens.
	useEffect( () => {
		if ( isOpen && searchRef.current ) {
			searchRef.current.focus();
		}
	}, [ isOpen ] );

	const searchLower = search.toLowerCase();

	const filtered = currencies.filter(
		( c ) =>
			c.value.toLowerCase().includes( searchLower ) ||
			( wcCurrencies[ c.value ] || '' )
				.toLowerCase()
				.includes( searchLower )
	);

	const popular = filtered.filter( ( c ) =>
		POPULAR_CODES.includes( c.value )
	);
	const rest = filtered.filter(
		( c ) => ! POPULAR_CODES.includes( c.value )
	);

	const handleSelect = ( code ) => {
		onChange( code );
		setIsOpen( false );
		setSearch( '' );
	};

	const selectedLabel = value
		? `${ value } — ${ wcCurrencies[ value ] || value }`
		: '';

	return (
		<div className="mhm-cs-currency-picker" ref={ containerRef }>
			<label className="components-base-control__label">
				{ __( 'Currency', 'mhm-currency-switcher' ) }
			</label>
			<button
				type="button"
				className="mhm-cs-picker-trigger"
				onClick={ () => setIsOpen( ! isOpen ) }
			>
				{ value ? (
					<span className="mhm-cs-picker-selected">
						<FlagIcon code={ value } />
						<span>{ selectedLabel }</span>
					</span>
				) : (
					<span className="mhm-cs-picker-placeholder">
						{ __(
							'Para birimi seçin…',
							'mhm-currency-switcher'
						) }
					</span>
				) }
				<span className="mhm-cs-picker-arrow">&#9662;</span>
			</button>

			{ isOpen && (
				<div className="mhm-cs-picker-dropdown">
					<div className="mhm-cs-picker-search-wrap">
						<input
							ref={ searchRef }
							type="text"
							className="mhm-cs-picker-search"
							placeholder={ __(
								'Ara…',
								'mhm-currency-switcher'
							) }
							value={ search }
							onChange={ ( e ) => setSearch( e.target.value ) }
						/>
					</div>

					<div className="mhm-cs-picker-list">
						{ popular.length > 0 && (
							<>
								<div className="mhm-cs-picker-section-label">
									{ __(
										'Popüler',
										'mhm-currency-switcher'
									) }
								</div>
								{ popular.map( ( c ) => (
									<button
										key={ c.value }
										type="button"
										className={ `mhm-cs-picker-option ${
											value === c.value
												? 'is-selected'
												: ''
										}` }
										onClick={ () =>
											handleSelect( c.value )
										}
									>
										<FlagIcon code={ c.value } />
										<span className="mhm-cs-picker-code">
											{ c.value }
										</span>
										<span className="mhm-cs-picker-name">
											{ wcCurrencies[ c.value ] ||
												c.value }
										</span>
									</button>
								) ) }
							</>
						) }

						{ rest.length > 0 && (
							<>
								<div className="mhm-cs-picker-section-label">
									{ __(
										'Tümü',
										'mhm-currency-switcher'
									) }
								</div>
								{ rest.map( ( c ) => (
									<button
										key={ c.value }
										type="button"
										className={ `mhm-cs-picker-option ${
											value === c.value
												? 'is-selected'
												: ''
										}` }
										onClick={ () =>
											handleSelect( c.value )
										}
									>
										<FlagIcon code={ c.value } />
										<span className="mhm-cs-picker-code">
											{ c.value }
										</span>
										<span className="mhm-cs-picker-name">
											{ wcCurrencies[ c.value ] ||
												c.value }
										</span>
									</button>
								) ) }
							</>
						) }

						{ filtered.length === 0 && (
							<div className="mhm-cs-picker-empty">
								{ __(
									'Sonuç bulunamadı',
									'mhm-currency-switcher'
								) }
							</div>
						) }
					</div>
				</div>
			) }
		</div>
	);
};

export default CurrencyPicker;
