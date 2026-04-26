/**
 * ProGate — locks Pro-only content behind a blurred overlay.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';

/**
 * ProGate component.
 *
 * When isPro is false, renders a blur overlay with an upgrade CTA.
 * When isPro is true, simply renders children.
 *
 * @param {Object}  props          Component props.
 * @param {boolean} props.isPro    Whether the Pro license is active.
 * @param {Object}  props.children Child elements to gate.
 * @return {JSX.Element} ProGate wrapper.
 */
const ProGate = ( { isPro, children } ) => {
	if ( isPro ) {
		return children;
	}

	return (
		<div className="mhm-cs-pro-gate">
			<div className="mhm-cs-pro-overlay">
				<span className="dashicons dashicons-lock mhm-cs-pro-lock-icon"></span>
				<p>
					{ __(
						'This feature requires MHM Currency Switcher Pro',
						'mhm-currency-switcher'
					) }
				</p>
				<a href="#license" className="button button-primary">
					{ __( 'Unlock with Pro', 'mhm-currency-switcher' ) }
				</a>
			</div>
			<div className="mhm-cs-pro-blurred">{ children }</div>
		</div>
	);
};

export default ProGate;
