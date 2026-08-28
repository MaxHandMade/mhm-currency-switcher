/**
 * MHM Currency Switcher — Admin React App entry point.
 *
 * @package
 */

import { createRoot } from '@wordpress/element';
import App from './App';
import './style.css';

const container = document.getElementById( 'mhmcs-admin-root' );
if ( container ) {
	const root = createRoot( container );
	root.render( <App /> );
}
