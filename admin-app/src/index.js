/**
 * MHM Currency Switcher — Admin React App entry point.
 *
 * @package MhmCurrencySwitcher
 */

import { createRoot } from '@wordpress/element';
import App from './App';
import './style.css';

const container = document.getElementById( 'mhm-cs-admin-root' );
if ( container ) {
	const root = createRoot( container );
	root.render( <App /> );
}
