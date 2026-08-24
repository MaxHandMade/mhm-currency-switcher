/**
 * MHM Currency Switcher — Admin App.
 *
 * Tab-based layout with persistent save bar.
 *
 * @package
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { TabPanel, Button, Spinner, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import {
	getSettings,
	saveSettings,
	getCurrencies,
	saveCurrencies,
	syncRates,
} from './api/settings';
import ManageCurrencies from './components/tabs/ManageCurrencies';
import DisplayOptions from './components/tabs/DisplayOptions';
import AdvancedSettings from './components/tabs/AdvancedSettings';
import HowToUse from './components/tabs/HowToUse';
import About from './components/tabs/About';

/**
 * Admin config injected via wp_localize_script.
 *
 * @type {Object}
 */
const config = window.mhmCsAdmin || {};

/**
 * Human-readable text for a server-side clamp.
 *
 * Every reason is a full sentence with its own msgid: a fragment glued to a
 * field name gives a translator nothing to reorder, and the field name is not
 * a word in any language.
 *
 * Most reasons are about one currency and carry its ISO code in
 * `adjustment.code`. The product-widget cap is not about any single
 * currency — it is about the widget as a whole — so the server sends
 * `code: ''` for it rather than omitting the field. That empty string is a
 * deliberate "this is about the widget" marker, not a missing value, so it
 * is never interpolated into a sentence here.
 *
 * @param {Object} adjustment One entry of the API's adjustments array.
 * @return {string} Sentence to show.
 */
const describeAdjustment = ( adjustment ) => {
	switch ( adjustment.reason ) {
		case 'separator_truncated':
			/*
			 * Names the character that was STORED rather than saying "the first
			 * character". Those are not always the same: the sanitiser strips
			 * control characters and angle brackets before taking the first of
			 * what is left, so an input of "<b>" stores "b" — the second raw
			 * character. The old wording was written for the "," + "." case and
			 * became false once the truncation check started firing on inputs
			 * that lose their first character to the strip.
			 */
			return sprintf(
				/* translators: 1: currency code, for example TRY. 2: the separator character that was stored, for example ",". */
				__(
					'%1$s: a separator can only be one character, so "%2$s" was used.',
					'mhm-currency-switcher'
				),
				adjustment.code,
				adjustment.value
			);
		case 'separator_invalid':
			return sprintf(
				/* translators: %s: currency code, for example TRY. */
				__(
					'%s: the separator had no usable character, so it was cleared.',
					'mhm-currency-switcher'
				),
				adjustment.code
			);
		case 'decimals_invalid':
			/*
			 * The value is interpolated rather than written into the sentence.
			 * It used to say "so 2 was used", which stopped being true once the
			 * fallback started coming from the currency's own minor unit: the
			 * yen falls back to 0 and the dinar to 3.
			 *
			 * 🔴 This note sits ABOVE the sprintf() on purpose. Between the
			 * translators comment and the __() call it becomes the FIRST
			 * leading comment, which is the one @wordpress/i18n-translator-comments
			 * reads — so the rule saw a non-translators comment and reported the
			 * call as uncommented. The string had a translator note the whole
			 * time; the lint error was about ordering, and it stood for four
			 * releases because the JS lint baseline was recorded as "clean".
			 */
			return sprintf(
				/* translators: 1: currency code, for example TRY. 2: the number of decimals that was stored instead, a whole number from 0 to 4. */
				__(
					'%1$s: the number of decimals must be a number, so %2$d was used.',
					'mhm-currency-switcher'
				),
				adjustment.code,
				adjustment.value
			);
		case 'decimals_out_of_range':
			return sprintf(
				/* translators: 1: currency code, for example TRY. 2: the number of decimals that was stored instead, a whole number from 0 to 4. */
				__(
					'%1$s: currencies use between 0 and 4 decimals, so the value was adjusted to %2$d.',
					'mhm-currency-switcher'
				),
				adjustment.code,
				adjustment.value
			);
		case 'decimal_sep_empty':
			return sprintf(
				/* translators: %s: currency code, for example TRY. */
				__(
					'%s: a decimal separator is required while decimals are shown, so the store setting was used.',
					'mhm-currency-switcher'
				),
				adjustment.code
			);
		case 'separators_equal':
			return sprintf(
				/* translators: %s: currency code, for example TRY. */
				__(
					'%s: the thousands and decimal separators cannot be the same, so the thousands separator was removed.',
					'mhm-currency-switcher'
				),
				adjustment.code
			);
		case 'position_invalid':
			return sprintf(
				/* translators: %s: currency code, for example TRY. */
				__(
					'%s: that symbol position is not one this plugin offers, so the symbol was placed on the left.',
					'mhm-currency-switcher'
				),
				adjustment.code
			);
		/*
		 * The three numeric corrections. Each names the field rather than
		 * assuming which one it was: one sanitiser serves the rate, the fee and
		 * both rounding steps, so the sentence has to work for all of them.
		 */
		case 'not_a_number':
			return sprintf(
				/* translators: 1: currency code, for example TRY. 2: setting field name, for example rate. */
				__(
					'%1$s: the value for %2$s has to be a number, so 0 was used.',
					'mhm-currency-switcher'
				),
				adjustment.code,
				adjustment.field
			);
		case 'not_finite':
			return sprintf(
				/* translators: 1: currency code, for example TRY. 2: setting field name, for example rate. */
				__(
					'%1$s: the value for %2$s was too large to store, so 0 was used.',
					'mhm-currency-switcher'
				),
				adjustment.code,
				adjustment.field
			);
		case 'negative':
			return sprintf(
				/* translators: 1: currency code, for example TRY. 2: setting field name, for example rate. */
				__(
					'%1$s: the value for %2$s cannot be negative, so 0 was used.',
					'mhm-currency-switcher'
				),
				adjustment.code,
				adjustment.field
			);
		case 'widget_currencies_too_many':
			return sprintf(
				/* translators: %d: maximum number of currencies, for example 5. */
				__(
					'The product price list shows at most %d currencies, so the extra ones were dropped.',
					'mhm-currency-switcher'
				),
				adjustment.value
			);
		default:
			return adjustment.code
				? sprintf(
						/* translators: 1: currency code, 2: setting field name. */
						__(
							'%1$s: the value for %2$s was adjusted before saving.',
							'mhm-currency-switcher'
						),
						adjustment.code,
						adjustment.field
				  )
				: sprintf(
						/* translators: %s: setting field name. */
						__(
							'The value for %s was adjusted before saving.',
							'mhm-currency-switcher'
						),
						adjustment.field
				  );
	}
};

/**
 * Main App component.
 *
 * @return {JSX.Element} App root.
 */
const App = () => {
	const [ settings, setSettings ] = useState( {} );
	const [ currencies, setCurrencies ] = useState( [] );
	const [ baseCurrency, setBaseCurrency ] = useState(
		config.baseCurrency || 'USD'
	);
	// { time, base } from RateProvider::LAST_SYNC_OPTION, or null when no
	// sync has ever been recorded — a real, renderable state, not an
	// intermediate one, since every install upgrading to this release starts
	// here with working rates already in place.
	const [ lastSync, setLastSync ] = useState( null );
	// Rides along with lastSync from the same GET /currencies response: the two
	// answer the same question from opposite ends and would drift if fetched
	// apart.
	const [ nextSync, setNextSync ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ syncing, setSyncing ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ dirty, setDirty ] = useState( false );

	/**
	 * Load settings and currencies on mount.
	 */
	useEffect( () => {
		const loadData = async () => {
			try {
				const [ settingsData, currenciesData ] = await Promise.all( [
					getSettings(),
					getCurrencies(),
				] );

				setSettings( settingsData || {} );
				setCurrencies( currenciesData?.currencies || [] );
				setBaseCurrency(
					currenciesData?.base_currency ||
						config.baseCurrency ||
						'USD'
				);
				setLastSync( currenciesData?.last_sync || null );
				setNextSync( currenciesData?.next_sync || null );
			} catch ( error ) {
				setNotice( {
					type: 'error',
					message:
						error.message ||
						__(
							'Failed to load settings.',
							'mhm-currency-switcher'
						),
				} );
			}
			setLoading( false );
		};

		loadData();
	}, [] );

	/**
	 * Handle currencies change.
	 */
	const handleCurrenciesChange = useCallback( ( updated ) => {
		setCurrencies( updated );
		setDirty( true );
	}, [] );

	/**
	 * Handle settings change.
	 */
	const handleSettingsChange = useCallback( ( updated ) => {
		setSettings( updated );
		setDirty( true );
	}, [] );

	/**
	 * Save all changes.
	 */
	const handleSave = async () => {
		setSaving( true );
		setNotice( null );

		try {
			const [ settingsResult, currencyResult ] = await Promise.all( [
				saveSettings( settings ),
				saveCurrencies( {
					base_currency: baseCurrency,
					currencies,
				} ),
			] );

			// 🔴 Re-seat from the response, not from local state. The server
			// clamps separators, decimals and the widget list; showing what was
			// typed instead of what was stored is the "control that lies" class
			// this panel has spent three rounds removing.
			if ( currencyResult?.currencies ) {
				setCurrencies( currencyResult.currencies );
			}

			// Both endpoints can adjust input on save — save_settings() clamps
			// the product widget's currency list, save_currencies() clamps
			// separators and decimals — so both responses' adjustments must be
			// shown. Reading only one silently drops the other endpoint's
			// notices.
			const adjustments = [
				...( settingsResult?.adjustments || [] ),
				...( currencyResult?.adjustments || [] ),
			];

			setDirty( false );
			setNotice(
				adjustments.length
					? {
							type: 'warning',
							message: [
								__(
									'Settings saved, with changes:',
									'mhm-currency-switcher'
								),
								...adjustments.map( describeAdjustment ),
							].join( ' ' ),
					  }
					: {
							type: 'success',
							message: __(
								'Settings saved successfully.',
								'mhm-currency-switcher'
							),
					  }
			);
		} catch ( error ) {
			setNotice( {
				type: 'error',
				message:
					error.message ||
					__( 'Failed to save settings.', 'mhm-currency-switcher' ),
			} );
		}

		setSaving( false );
	};

	/**
	 * Trigger rate sync.
	 */
	const handleSyncRates = async () => {
		// 🔴 Syncing with unsaved edits on screen destroyed them: the handler
		// re-fetched the server's list and overwrote local state without asking,
		// so a currency the admin had just added — or a rate they had just
		// typed — vanished from the table while the "unsaved changes" bar was
		// still showing.
		//
		// Refusing is not merely the safe option, it is the correct one. The
		// server syncs against ITS OWN stored list; unsaved edits are not in it.
		// So a sync started from a dirty panel would apply rates to a list the
		// admin is no longer looking at, and then present the result as theirs.
		// There is no merge that makes that coherent — the save has to happen
		// first.
		if ( dirty ) {
			setNotice( {
				type: 'warning',
				message: __(
					'Save your changes before syncing rates. Syncing updates the currencies already saved, so unsaved edits would be lost.',
					'mhm-currency-switcher'
				),
			} );
			return;
		}

		setSyncing( true );
		setNotice( null );

		try {
			const response = await syncRates();
			if ( response?.rates ) {
				// Safe to replace wholesale: the guard above guarantees there is
				// nothing local that the server does not already have.
				const currenciesData = await getCurrencies();
				setCurrencies( currenciesData?.currencies || [] );
				setLastSync( currenciesData?.last_sync || null );
				setNextSync( currenciesData?.next_sync || null );
			}

			setNotice( {
				type: 'success',
				message: __(
					'Exchange rates synced successfully.',
					'mhm-currency-switcher'
				),
			} );
		} catch ( error ) {
			setNotice( {
				type: 'error',
				message:
					error.message ||
					__(
						'Failed to sync exchange rates.',
						'mhm-currency-switcher'
					),
			} );
		}

		setSyncing( false );
	};

	if ( loading ) {
		return (
			<div className="mhm-cs-admin mhm-cs-loading">
				<Spinner />
				<p>{ __( 'Loading settings…', 'mhm-currency-switcher' ) }</p>
			</div>
		);
	}

	const tabs = [
		{
			name: 'currencies',
			title: __( 'Manage Currencies', 'mhm-currency-switcher' ),
			className: 'mhm-cs-tab-currencies',
		},
		{
			name: 'display',
			title: __( 'Display Options', 'mhm-currency-switcher' ),
			className: 'mhm-cs-tab-display',
		},
		{
			name: 'advanced',
			title: __( 'Advanced', 'mhm-currency-switcher' ),
			className: 'mhm-cs-tab-advanced',
		},
		{
			name: 'help',
			title: __( 'How to use', 'mhm-currency-switcher' ),
			className: 'mhm-cs-tab-help',
		},
		{
			name: 'about',
			title: __( 'About', 'mhm-currency-switcher' ),
			className: 'mhm-cs-tab-about',
		},
	];

	return (
		<div className="mhm-cs-admin">
			<div className="mhm-cs-brandbar">
				{ /*
				 * Decorative: the plugin name is real text immediately beside
				 * it, so the mark carries no information of its own. It is
				 * also the one place the brand gradient starts at brand-1,
				 * which is why nothing here may become the sole carrier of a
				 * meaning — 2.94:1 against white is below the graphics floor.
				 */ }
				<span className="mhm-cs-brandbar__mark" aria-hidden="true">
					<span className="dashicons dashicons-update" />
				</span>
				<h1>
					{ __( 'MHM Currency Switcher', 'mhm-currency-switcher' ) }
				</h1>
			</div>
			<div className="mhm-cs-brandbar__rule" aria-hidden="true" />

			{ dirty && (
				<div className="mhm-cs-save-bar">
					<span className="mhm-cs-unsaved-label">
						{ __(
							'You have unsaved changes.',
							'mhm-currency-switcher'
						) }
					</span>
					<Button
						variant="primary"
						onClick={ handleSave }
						disabled={ saving }
						isBusy={ saving }
					>
						{ saving
							? __( 'Saving…', 'mhm-currency-switcher' )
							: __( 'Save Changes', 'mhm-currency-switcher' ) }
					</Button>
				</div>
			) }

			{ notice && (
				<Notice
					status={ notice.type }
					isDismissible
					onDismiss={ () => setNotice( null ) }
					className="mhm-cs-notice"
				>
					{ notice.message }
				</Notice>
			) }

			<TabPanel tabs={ tabs }>
				{ ( tab ) => {
					switch ( tab.name ) {
						case 'currencies':
							return (
								<ManageCurrencies
									currencies={ currencies }
									onChange={ handleCurrenciesChange }
									baseCurrency={ baseCurrency }
									wcCurrencies={ config.wcCurrencies || {} }
									onSyncRates={ handleSyncRates }
									syncing={ syncing }
									lastSync={ lastSync }
									rateUpdateInterval={
										settings.rate_update_interval ||
										'manual'
									}
								/>
							);
						case 'display':
							return (
								<DisplayOptions
									settings={ settings }
									onChange={ handleSettingsChange }
									currencies={ currencies }
								/>
							);
						case 'advanced':
							return (
								<AdvancedSettings
									settings={ settings }
									onChange={ handleSettingsChange }
									currencies={ currencies }
									lastSync={ lastSync }
									nextSync={ nextSync }
									baseCurrency={ baseCurrency }
								/>
							);
						case 'help':
							return <HowToUse />;
						case 'about':
							return <About about={ config.about } />;
						default:
							return null;
					}
				} }
			</TabPanel>

			<div className="mhm-cs-footer-save">
				<Button
					variant="primary"
					onClick={ handleSave }
					disabled={ saving || ! dirty }
					isBusy={ saving }
				>
					{ saving
						? __( 'Saving…', 'mhm-currency-switcher' )
						: __( 'Save Changes', 'mhm-currency-switcher' ) }
				</Button>
			</div>
		</div>
	);
};

export default App;
