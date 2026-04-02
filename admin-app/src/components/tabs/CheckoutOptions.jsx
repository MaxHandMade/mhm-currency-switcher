/**
 * CheckoutOptions tab — per-currency payment method restrictions.
 *
 * Wrapped in ProGate for Lite users.
 *
 * @package MhmCurrencySwitcher
 */

import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ProGate from '../shared/ProGate';

/**
 * CheckoutOptions tab component.
 *
 * @param {Object}   props                  Component props.
 * @param {Object}   props.settings         Current plugin settings.
 * @param {Function} props.onChange          Callback when settings change.
 * @param {boolean}  props.isPro            Whether Pro license is active.
 * @param {Array}    props.currencies       Array of currency config objects.
 * @param {Object}   props.wcPaymentMethods Map of gateway_id => label.
 * @return {JSX.Element} CheckoutOptions tab.
 */
const CheckoutOptions = ( {
	settings,
	onChange,
	isPro,
	currencies,
	wcPaymentMethods,
} ) => {
	const paymentRestrictions = settings.payment_restrictions || {};

	const methodOptions = Object.keys( wcPaymentMethods || {} ).map(
		( id ) => ( {
			label: wcPaymentMethods[ id ]?.title || id,
			value: id,
		} )
	);

	const allMethodsOption = {
		label: __( 'All payment methods', 'mhm-currency-switcher' ),
		value: '__all__',
	};

	const updateRestriction = ( currencyCode, methods ) => {
		onChange( {
			...settings,
			payment_restrictions: {
				...paymentRestrictions,
				[ currencyCode ]: methods,
			},
		} );
	};

	const enabledCurrencies = currencies.filter( ( c ) => c.enabled );

	return (
		<div className="mhm-cs-tab-content">
			<ProGate isPro={ isPro }>
				<h3>
					{ __(
						'Payment Method Restrictions',
						'mhm-currency-switcher'
					) }
				</h3>
				<p className="description">
					{ __(
						'Choose which payment methods are available for each currency. Leave on "All" to allow every gateway.',
						'mhm-currency-switcher'
					) }
				</p>

				{ enabledCurrencies.length === 0 ? (
					<p>
						{ __(
							'No enabled currencies. Add and enable currencies first.',
							'mhm-currency-switcher'
						) }
					</p>
				) : (
					<table className="mhm-cs-currency-table widefat">
						<thead>
							<tr>
								<th>
									{ __( 'Currency', 'mhm-currency-switcher' ) }
								</th>
								<th>
									{ __(
										'Allowed Payment Methods',
										'mhm-currency-switcher'
									) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ enabledCurrencies.map( ( currency ) => {
								const current =
									paymentRestrictions[ currency.code ] || [
										'__all__',
									];
								return (
									<tr key={ currency.code }>
										<td>
											<strong>{ currency.code }</strong>
										</td>
										<td>
											<SelectControl
												multiple
												value={ current }
												options={ [
													allMethodsOption,
													...methodOptions,
												] }
												onChange={ ( val ) =>
													updateRestriction(
														currency.code,
														val
													)
												}
												__nextHasNoMarginBottom
											/>
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				) }
			</ProGate>
		</div>
	);
};

export default CheckoutOptions;
