/**
 * License tab — key management, status display, and Pro feature checklist.
 *
 * @package
 */

import { useState, useCallback } from '@wordpress/element';
import { Button, TextControl, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const config = window.mhmCsAdmin || {};

/**
 * Format an ISO date string for display.
 *
 * @param {string} isoDate ISO 8601 date string.
 * @return {string} Formatted date, or '—'.
 */
const formatDate = ( isoDate ) => {
	if ( ! isoDate ) {
		return '—';
	}

	try {
		const date = new Date( isoDate );

		return date.toLocaleDateString( undefined, {
			year: 'numeric',
			month: 'long',
			day: 'numeric',
		} );
	} catch {
		return isoDate;
	}
};

/**
 * Format an ISO date string as relative time.
 *
 * @param {string} isoDate ISO 8601 date string.
 * @return {string} Relative time, or '—'.
 */
const formatRelativeTime = ( isoDate ) => {
	if ( ! isoDate ) {
		return '—';
	}

	try {
		const date = new Date( isoDate );
		const now = new Date();
		const diffMs = now - date;
		const diffMins = Math.floor( diffMs / 60000 );
		const diffHours = Math.floor( diffMs / 3600000 );
		const diffDays = Math.floor( diffMs / 86400000 );

		if ( diffMins < 1 ) {
			return __( 'Just now', 'mhm-currency-switcher' );
		}
		if ( diffMins < 60 ) {
			return diffMins + ' ' + __( 'minutes ago', 'mhm-currency-switcher' );
		}
		if ( diffHours < 24 ) {
			return diffHours + ' ' + __( 'hours ago', 'mhm-currency-switcher' );
		}
		return diffDays + ' ' + __( 'days ago', 'mhm-currency-switcher' );
	} catch {
		return isoDate;
	}
};

/**
 * Check if a license expiration date is approaching (within 30 days).
 *
 * @param {string} expiresAt ISO 8601 date string.
 * @return {boolean} True if expiring within 30 days.
 */
const isExpiringSoon = ( expiresAt ) => {
	if ( ! expiresAt ) {
		return false;
	}

	try {
		const expires = new Date( expiresAt );
		const now = new Date();
		const daysLeft = Math.ceil( ( expires - now ) / 86400000 );
		return daysLeft > 0 && daysLeft <= 30;
	} catch {
		return false;
	}
};

/**
 * Calculate days remaining until expiration.
 *
 * @param {string} expiresAt ISO 8601 date string.
 * @return {number|null} Days remaining, or null.
 */
const daysRemaining = ( expiresAt ) => {
	if ( ! expiresAt ) {
		return null;
	}

	try {
		const expires = new Date( expiresAt );
		const now = new Date();
		return Math.ceil( ( expires - now ) / 86400000 );
	} catch {
		return null;
	}
};

/**
 * License tab component.
 *
 * @param {Object}  props       Component props.
 * @param {boolean} props.isPro Whether Pro license is active.
 * @return {JSX.Element} License tab.
 */
const License = ( { isPro } ) => {
	const initialLicense = config.license || {};
	const [ licenseKey, setLicenseKey ] = useState( '' );
	const [ license, setLicense ] = useState( {
		status: initialLicense.status || ( isPro ? 'active' : 'inactive' ),
		plan: initialLicense.plan || '',
		expiresAt: initialLicense.expiresAt || '',
		lastCheck: initialLicense.lastCheck || '',
		activated: initialLicense.activated || '',
		maskedKey: initialLicense.maskedKey || '',
	} );
	const [ loading, setLoading ] = useState( false );
	const [ refreshing, setRefreshing ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const isActive = license.status === 'active';

	const handleActivate = useCallback( async () => {
		if ( ! licenseKey.trim() ) {
			setNotice( {
				type: 'error',
				message: __(
					'Please enter a license key.',
					'mhm-currency-switcher'
				),
			} );
			return;
		}

		setLoading( true );
		setNotice( null );

		try {
			const response = await apiFetch( {
				path: '/mhm-currency/v1/license/activate',
				method: 'POST',
				data: { license_key: licenseKey },
			} );

			if ( response.success ) {
				if ( response.license ) {
					setLicense( response.license );
				} else {
					setLicense( ( prev ) => ( {
						...prev,
						status: 'active',
					} ) );
				}
				setNotice( {
					type: 'success',
					message: __(
						'License activated successfully! Pro features are now available.',
						'mhm-currency-switcher'
					),
				} );
			} else {
				setNotice( {
					type: 'error',
					message:
						response.message ||
						__(
							'Activation failed. Please check your license key.',
							'mhm-currency-switcher'
						),
				} );
			}
		} catch ( error ) {
			setNotice( {
				type: 'error',
				message:
					error.message ||
					__(
						'An error occurred during activation.',
						'mhm-currency-switcher'
					),
			} );
		}

		setLoading( false );
	}, [ licenseKey ] );

	const handleDeactivate = useCallback( async () => {
		setLoading( true );
		setNotice( null );

		try {
			const response = await apiFetch( {
				path: '/mhm-currency/v1/license/deactivate',
				method: 'POST',
			} );

			if ( response.success ) {
				setLicense( {
					status: 'inactive',
					plan: '',
					expiresAt: '',
					lastCheck: '',
					activated: '',
					maskedKey: '',
				} );
				setLicenseKey( '' );
				setNotice( {
					type: 'success',
					message: __(
						'License deactivated.',
						'mhm-currency-switcher'
					),
				} );
			}
		} catch ( error ) {
			setNotice( {
				type: 'error',
				message:
					error.message ||
					__(
						'An error occurred during deactivation.',
						'mhm-currency-switcher'
					),
			} );
		}

		setLoading( false );
	}, [] );

	const handleRefresh = useCallback( async () => {
		setRefreshing( true );

		try {
			const response = await apiFetch( {
				path: '/mhm-currency/v1/license/status',
			} );

			setLicense( response );
			setNotice( {
				type: 'success',
				message: __(
					'License status refreshed.',
					'mhm-currency-switcher'
				),
			} );
		} catch ( error ) {
			setNotice( {
				type: 'error',
				message:
					error.message ||
					__(
						'Failed to refresh license status.',
						'mhm-currency-switcher'
					),
			} );
		}

		setRefreshing( false );
	}, [] );

	const features = [
		{
			label: __( 'Unlimited currencies', 'mhm-currency-switcher' ),
			pro: true,
		},
		{
			label: __(
				'Geolocation-based currency detection',
				'mhm-currency-switcher'
			),
			pro: true,
		},
		{
			label: __(
				'Per-currency payment method restrictions',
				'mhm-currency-switcher'
			),
			pro: true,
		},
		{
			label: __(
				'Automatic rate updates (cron)',
				'mhm-currency-switcher'
			),
			pro: true,
		},
		{
			label: __(
				'Multilingual currency mapping',
				'mhm-currency-switcher'
			),
			pro: true,
		},
		{
			label: __( 'REST API currency filter', 'mhm-currency-switcher' ),
			pro: true,
		},
		{
			label: __( 'Premium rate providers', 'mhm-currency-switcher' ),
			pro: true,
		},
		{
			label: __( 'MHM Rentiva integration', 'mhm-currency-switcher' ),
			pro: true,
		},
		{
			label: __( 'Priority support', 'mhm-currency-switcher' ),
			pro: true,
		},
	];

	const statusLabels = {
		active: {
			text: __( 'Active', 'mhm-currency-switcher' ),
			className: 'mhm-cs-status-active',
		},
		inactive: {
			text: __( 'Inactive', 'mhm-currency-switcher' ),
			className: 'mhm-cs-status-inactive',
		},
		expired: {
			text: __( 'Expired', 'mhm-currency-switcher' ),
			className: 'mhm-cs-status-expired',
		},
	};

	const planLabels = {
		pro: 'Pro',
		monthly: __( 'Monthly', 'mhm-currency-switcher' ),
		yearly: __( 'Yearly', 'mhm-currency-switcher' ),
	};

	const currentStatus = statusLabels[ license.status ] || statusLabels.inactive;
	const remaining = daysRemaining( license.expiresAt );
	const expiringSoon = isExpiringSoon( license.expiresAt );

	return (
		<div className="mhm-cs-tab-content">
			{ /* License info card for active/expired licenses */ }
			{ license.status !== 'inactive' && (
				<div className={ `mhm-cs-license-card mhm-cs-license-card--${ license.status }` }>
					<div className="mhm-cs-license-card__header">
						<div className="mhm-cs-license-card__title">
							<span
								className={ `mhm-cs-status-badge ${ currentStatus.className }` }
							>
								{ currentStatus.text }
							</span>
							{ license.plan && (
								<span className="mhm-cs-license-plan">
									{ planLabels[ license.plan ] || license.plan }
								</span>
							) }
						</div>
						<Button
							variant="tertiary"
							icon="update"
							onClick={ handleRefresh }
							disabled={ refreshing }
							label={ __( 'Refresh status', 'mhm-currency-switcher' ) }
							className="mhm-cs-refresh-btn"
						>
							{ refreshing && <Spinner /> }
						</Button>
					</div>

					{ license.maskedKey && (
						<div className="mhm-cs-license-card__key">
							<code>{ license.maskedKey }</code>
						</div>
					) }

					<div className="mhm-cs-license-card__details">
						{ license.expiresAt && (
							<div className="mhm-cs-license-detail">
								<span className="mhm-cs-license-detail__label">
									{ __( 'Expires', 'mhm-currency-switcher' ) }
								</span>
								<span className={ `mhm-cs-license-detail__value${ expiringSoon ? ' mhm-cs-expiring-soon' : '' }` }>
									{ formatDate( license.expiresAt ) }
									{ remaining !== null && remaining > 0 && (
										<span className="mhm-cs-days-remaining">
											({ remaining } { __( 'days left', 'mhm-currency-switcher' ) })
										</span>
									) }
								</span>
							</div>
						) }
						{ license.activated && (
							<div className="mhm-cs-license-detail">
								<span className="mhm-cs-license-detail__label">
									{ __( 'Activated', 'mhm-currency-switcher' ) }
								</span>
								<span className="mhm-cs-license-detail__value">
									{ formatDate( license.activated ) }
								</span>
							</div>
						) }
						{ license.lastCheck && (
							<div className="mhm-cs-license-detail">
								<span className="mhm-cs-license-detail__label">
									{ __( 'Last verified', 'mhm-currency-switcher' ) }
								</span>
								<span className="mhm-cs-license-detail__value">
									{ formatRelativeTime( license.lastCheck ) }
								</span>
							</div>
						) }
					</div>

					{ expiringSoon && (
						<div className="mhm-cs-license-warning">
							<span className="dashicons dashicons-warning"></span>
							{ __( 'Your license is expiring soon. Renew to keep Pro features.', 'mhm-currency-switcher' ) }
						</div>
					) }
				</div>
			) }

			{ notice && (
				<Notice
					status={ notice.type }
					isDismissible
					onDismiss={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			{ /* Activation form or deactivate button */ }
			<div className="mhm-cs-license-form">
				{ ! isActive ? (
					<>
						<TextControl
							label={ __(
								'License Key',
								'mhm-currency-switcher'
							) }
							value={ licenseKey }
							onChange={ setLicenseKey }
							placeholder="XXXX-XXXX-XXXX-XXXX"
							__nextHasNoMarginBottom
						/>
						<Button
							variant="primary"
							onClick={ handleActivate }
							disabled={ loading }
						>
							{ loading ? (
								<>
									<Spinner />{ ' ' }
									{ __(
										'Activating…',
										'mhm-currency-switcher'
									) }
								</>
							) : (
								__(
									'Activate License',
									'mhm-currency-switcher'
								)
							) }
						</Button>
					</>
				) : (
					<>
						{ config.revalidateUrl && (
							<Button
								variant="secondary"
								onClick={ () => {
									window.location.href = config.revalidateUrl;
								} }
								disabled={ loading }
								style={ { marginRight: '8px' } }
							>
								{ __( 'Re-validate Now', 'mhm-currency-switcher' ) }
							</Button>
						) }
						<Button
							variant="secondary"
							isDestructive
							onClick={ handleDeactivate }
							disabled={ loading }
						>
							{ loading ? (
								<>
									<Spinner />{ ' ' }
									{ __(
										'Deactivating…',
										'mhm-currency-switcher'
									) }
								</>
							) : (
								__( 'Deactivate License', 'mhm-currency-switcher' )
							) }
						</Button>
					</>
				) }
			</div>

			<hr />

			<h3>{ __( 'Pro Features', 'mhm-currency-switcher' ) }</h3>

			<ul className="mhm-cs-feature-checklist">
				{ features.map( ( feature, index ) => (
					<li key={ index } className="mhm-cs-feature-item">
						<span
							className={ `dashicons ${
								isPro
									? 'dashicons-yes-alt mhm-cs-feature-check'
									: 'dashicons-lock mhm-cs-feature-lock'
							}` }
						></span>
						<span>{ feature.label }</span>
					</li>
				) ) }
			</ul>

			{ ! isPro && (
				<div className="mhm-cs-upgrade-cta">
					<p>
						{ __(
							'Unlock all features with MHM Currency Switcher Pro.',
							'mhm-currency-switcher'
						) }
					</p>
					<a
						href="https://maxhandmade.com/plugins/mhm-currency-switcher-pro"
						target="_blank"
						rel="noopener noreferrer"
						className="button button-primary button-hero"
					>
						{ __( 'Get Pro License', 'mhm-currency-switcher' ) }
					</a>
				</div>
			) }
		</div>
	);
};

export default License;
