/**
 * A code sample with a copy button.
 *
 * The sample container takes focus on purpose: it scrolls horizontally, and a
 * scrollable region that cannot be focused is unreachable by keyboard
 * (WCAG 2.1.1). The button's accessible name carries the sample's identity,
 * because a screen-reader user meeting four buttons all called "Copy" cannot
 * tell them apart. The result is spoken, because a visual "Copied!" is not
 * feedback for everyone.
 */

import { useState, useRef } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

const CopyableCode = ( { code, label } ) => {
	const [ copied, setCopied ] = useState( false );

	/*
	 * A ref, not an id built from the sample. Samples contain spaces —
	 * `[mhm_currency_switcher size="large"]` — and a DOM id with a space is
	 * invalid, so getElementById would quietly return null and the fallback
	 * would do nothing on exactly the installs that need it.
	 */
	const codeRef = useRef( null );

	const announceAndFlag = ( message ) => {
		speak( message );
		setCopied( true );
		setTimeout( () => setCopied( false ), 2000 );
	};

	const selectFallback = () => {
		const node = codeRef.current;

		if ( ! node ) {
			return;
		}

		const range = document.createRange();
		range.selectNodeContents( node );
		const selection = node.ownerDocument.defaultView.getSelection();
		selection.removeAllRanges();
		selection.addRange( range );

		announceAndFlag(
			sprintf(
				/* translators: %s: name of the code sample. */
				__(
					'%s selected. Press Ctrl+C to copy.',
					'mhm-currency-switcher'
				),
				label
			)
		);
	};

	const handleCopy = () => {
		// Absent on plain HTTP, which is the ordinary case for a local install.
		if ( ! window.navigator.clipboard ) {
			selectFallback();
			return;
		}

		window.navigator.clipboard.writeText( code ).then( () => {
			announceAndFlag(
				sprintf(
					/* translators: %s: name of the code sample. */
					__( '%s copied to clipboard.', 'mhm-currency-switcher' ),
					label
				)
			);
		}, selectFallback );
	};

	return (
		<div className="mhm-cs-copyable">
			<code
				ref={ codeRef }
				className="mhm-cs-copyable__code"
				tabIndex={ 0 }
				role="group"
				aria-label={ label }
			>
				{ code }
			</code>
			<Button
				variant="secondary"
				onClick={ handleCopy }
				label={ sprintf(
					/* translators: %s: name of the code sample. */
					__( 'Copy %s to clipboard', 'mhm-currency-switcher' ),
					label
				) }
				showTooltip
			>
				{ copied
					? __( 'Copied', 'mhm-currency-switcher' )
					: __( 'Copy', 'mhm-currency-switcher' ) }
			</Button>
		</div>
	);
};

export default CopyableCode;
