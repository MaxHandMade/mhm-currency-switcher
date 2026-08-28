/**
 * CurrencyPicker — searchable dropdown with flags and popular currencies.
 *
 * @package
 */

import {
	useState,
	useRef,
	useEffect,
	useCallback,
	useId,
} from '@wordpress/element';
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
 * @param {Object} props        Component props.
 * @param {string} props.code   Currency code.
 * @param {number} props.width  Image width.
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
				className="mhmcs-picker-flag mhmcs-flag-placeholder"
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
			className="mhmcs-picker-flag"
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
 * @param {Function} props.onChange     Callback when selection changes.
 * @param {Object}   props.wcCurrencies Map of code => label for WC currencies.
 * @return {JSX.Element} CurrencyPicker.
 */
const CurrencyPicker = ( { currencies, value, onChange, wcCurrencies } ) => {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const containerRef = useRef( null );
	const searchRef = useRef( null );
	const triggerRef = useRef( null );

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

	/**
	 * Close the popover and put focus back where the user left it.
	 *
	 * Used by the paths where the user is still driving the keyboard — Escape
	 * and selection. An outside click is deliberately NOT one of them: focus is
	 * already going where that click sent it, and pulling it back would fight
	 * the user.
	 *
	 * @return {void}
	 */
	const closeAndRestoreFocus = () => {
		setIsOpen( false );
		setSearch( '' );
		triggerRef.current?.focus();
	};

	/**
	 * Dismiss the popover from the keyboard.
	 *
	 * @param {Object} event React keyboard event.
	 * @return {void}
	 */
	const handleKeyDown = ( event ) => {
		if ( 'Escape' === event.key && isOpen ) {
			event.preventDefault();
			closeAndRestoreFocus();
		}
	};

	/**
	 * Close when focus leaves the component entirely.
	 *
	 * A disclosure that stays open while focus is elsewhere is a stale popover
	 * covering the page. Focus is not moved — the user is already on their way
	 * somewhere.
	 *
	 * @param {Object} event React focus event.
	 * @return {void}
	 */
	const handleFocusOut = ( event ) => {
		if ( ! containerRef.current?.contains( event.relatedTarget ) ) {
			setIsOpen( false );
			setSearch( '' );
		}
	};

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
		closeAndRestoreFocus();
	};

	const selectedLabel = value
		? `${ value } — ${ wcCurrencies[ value ] || value }`
		: '';

	// The visible label sat next to the trigger without ever being tied to it,
	// so screen readers announced an unlabelled button. The trigger is a
	// <button>, which is a labelable element, so htmlFor is the correct
	// association — but the component can be rendered more than once per
	// screen, so the id has to be unique per instance.
	const triggerId = useId();
	const searchId = useId();

	return (
		// This div is not itself a control; it only catches Escape and
		// focus-out events bubbling up from the real interactive elements
		// inside it (the trigger button, the search field, the option
		// buttons), each of which is already a native, keyboard-operable
		// element with its own handler. No role or tabIndex belongs here.
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions
		<div
			className="mhmcs-currency-picker"
			ref={ containerRef }
			onKeyDown={ handleKeyDown }
			onBlur={ handleFocusOut }
		>
			<label
				className="components-base-control__label"
				htmlFor={ triggerId }
			>
				{ __( 'Currency', 'mhm-currency-switcher' ) }
			</label>
			{ /*
			 * Deliberately NOT aria-haspopup="listbox": what opens is a popover
			 * holding a search field and grouped buttons, with no listbox/option
			 * roles and no arrow-key navigation. Announcing a listbox would
			 * promise keyboard behaviour that is not there. aria-expanded alone
			 * describes what this actually is — a disclosure button.
			 */ }
			<button
				id={ triggerId }
				ref={ triggerRef }
				type="button"
				className="mhmcs-picker-trigger"
				aria-expanded={ isOpen }
				onClick={ () => setIsOpen( ! isOpen ) }
			>
				{ value ? (
					<span className="mhmcs-picker-selected">
						<FlagIcon code={ value } />
						<span>{ selectedLabel }</span>
					</span>
				) : (
					<span className="mhmcs-picker-placeholder">
						{ __( 'Select a currency…', 'mhm-currency-switcher' ) }
					</span>
				) }
				<span className="mhmcs-picker-arrow">&#9662;</span>
			</button>

			{ isOpen && (
				<div className="mhmcs-picker-dropdown">
					<div className="mhmcs-picker-search-wrap">
						{ /*
						 * A placeholder is the weakest source of an accessible
						 * name — it is the last resort in the HTML mapping and
						 * it disappears the moment the field has text in it,
						 * which is exactly when someone might tab back to ask
						 * what this box is. The real label is hidden visually
						 * because the field sits inside an already-labelled
						 * popover where visible label text would be noise.
						 */ }
						<label
							htmlFor={ searchId }
							className="screen-reader-text"
						>
							{ __(
								'Search currencies',
								'mhm-currency-switcher'
							) }
						</label>
						<input
							id={ searchId }
							ref={ searchRef }
							type="text"
							className="mhmcs-picker-search"
							placeholder={ __(
								'Search…',
								'mhm-currency-switcher'
							) }
							value={ search }
							onChange={ ( e ) => setSearch( e.target.value ) }
						/>
					</div>

					<div className="mhmcs-picker-list">
						{ popular.length > 0 && (
							<>
								<div className="mhmcs-picker-section-label">
									{ __( 'Popular', 'mhm-currency-switcher' ) }
								</div>
								{ popular.map( ( c ) => (
									<button
										key={ c.value }
										type="button"
										className={ `mhmcs-picker-option ${
											value === c.value
												? 'is-selected'
												: ''
										}` }
										onClick={ () =>
											handleSelect( c.value )
										}
									>
										<FlagIcon code={ c.value } />
										<span className="mhmcs-picker-code">
											{ c.value }
										</span>
										<span className="mhmcs-picker-name">
											{ wcCurrencies[ c.value ] ||
												c.value }
										</span>
									</button>
								) ) }
							</>
						) }

						{ rest.length > 0 && (
							<>
								<div className="mhmcs-picker-section-label">
									{ __( 'All', 'mhm-currency-switcher' ) }
								</div>
								{ rest.map( ( c ) => (
									<button
										key={ c.value }
										type="button"
										className={ `mhmcs-picker-option ${
											value === c.value
												? 'is-selected'
												: ''
										}` }
										onClick={ () =>
											handleSelect( c.value )
										}
									>
										<FlagIcon code={ c.value } />
										<span className="mhmcs-picker-code">
											{ c.value }
										</span>
										<span className="mhmcs-picker-name">
											{ wcCurrencies[ c.value ] ||
												c.value }
										</span>
									</button>
								) ) }
							</>
						) }

						{ filtered.length === 0 && (
							<div className="mhmcs-picker-empty">
								{ __(
									'No results found',
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
