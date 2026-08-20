/**
 * MHM Currency Switcher — Admin App.
 *
 * Tab-based layout with persistent save bar.
 *
 * @package
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { TabPanel, Button, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
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

/**
 * Admin config injected via wp_localize_script.
 *
 * @type {Object}
 */
const config = window.mhmCsAdmin || {};

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
			await Promise.all( [
				saveSettings( settings ),
				saveCurrencies( {
					base_currency: baseCurrency,
					currencies,
				} ),
			] );

			setDirty( false );
			setNotice( {
				type: 'success',
				message: __(
					'Settings saved successfully.',
					'mhm-currency-switcher'
				),
			} );
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
	];

	return (
		<div className="mhm-cs-admin">
			<div className="mhm-cs-header">
				<h1>
					{ __( 'MHM Currency Switcher', 'mhm-currency-switcher' ) }
				</h1>
			</div>

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
								/>
							);
						case 'help':
							return <HowToUse />;
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
