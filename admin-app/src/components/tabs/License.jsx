/**
 * License tab — key management, status display, and Pro feature checklist.
 *
 * @package
 */

import { useState } from '@wordpress/element';
import { Button, TextControl, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * License tab component.
 *
 * @param {Object}  props       Component props.
 * @param {boolean} props.isPro Whether Pro license is active.
 * @return {JSX.Element} License tab.
 */
const License = ( { isPro } ) => {
	const [ licenseKey, setLicenseKey ] = useState( '' );
	const [ status, setStatus ] = useState( isPro ? 'active' : 'inactive' );
	const [ loading, setLoading ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const handleActivate = async () => {
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
				setStatus( 'active' );
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
	};

	const handleDeactivate = async () => {
		setLoading( true );
		setNotice( null );

		try {
			const response = await apiFetch( {
				path: '/mhm-currency/v1/license/deactivate',
				method: 'POST',
			} );

			if ( response.success ) {
				setStatus( 'inactive' );
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
	};

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

	const currentStatus = statusLabels[ status ] || statusLabels.inactive;

	return (
		<div className="mhm-cs-tab-content">
			<h3>{ __( 'License Status', 'mhm-currency-switcher' ) }</h3>

			<div className="mhm-cs-license-status">
				<span
					className={ `mhm-cs-status-badge ${ currentStatus.className }` }
				>
					{ currentStatus.text }
				</span>
			</div>

			{ notice && (
				<Notice
					status={ notice.type }
					isDismissible
					onDismiss={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<div className="mhm-cs-license-form">
				{ status !== 'active' ? (
					<>
						<TextControl
							label={ __(
								'License Key',
								'mhm-currency-switcher'
							) }
							value={ licenseKey }
							onChange={ setLicenseKey }
							placeholder="MHM-XXXX-XXXX-XXXX-XXXX"
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
