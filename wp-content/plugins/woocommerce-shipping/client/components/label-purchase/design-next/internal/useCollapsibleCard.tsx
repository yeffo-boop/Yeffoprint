/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useCallback } from '@wordpress/element';
import { chevronDown, chevronUp } from '@wordpress/icons';
import { CardHeader, Button } from '@wordpress/components';

/**
 * Hook for creating collapsible card functionality
 *
 * @param initialIsOpen - Whether the card should be initially open (default: true)
 * @return Object containing isOpen state, toggle function, and CardHeader component
 */
export function useCollapsibleCard( initialIsOpen = true ) {
	const [ isOpen, setIsOpen ] = useState( initialIsOpen );

	const toggle = useCallback( () => {
		setIsOpen( ( prev ) => ! prev );
	}, [] );

	const CollapsibleCardHeader = useCallback(
		( {
			children,
			...props
		}: {
			children: React.ReactNode;
			[ key: string ]: any; // eslint-disable-line @typescript-eslint/no-explicit-any
		} ) => (
			<CardHeader
				{ ...props }
				onClick={ toggle }
				style={ {
					cursor: 'pointer',
					...props.style,
				} }
			>
				<div
					style={ {
						width: '100%',
						display: 'flex',
						alignItems: 'center',
						gap: '8px',
					} }
				>
					{ children }
				</div>
				<Button
					variant="tertiary"
					icon={ isOpen ? chevronUp : chevronDown }
					aria-expanded={ isOpen }
					aria-label={
						isOpen
							? __( 'Collapse', 'woocommerce-shipping' )
							: __( 'Expand', 'woocommerce-shipping' )
					}
					className="collapsible-card-toggle"
					style={ {
						color: '#1e1e1e', // grey-900,
						pointerEvents: 'none', // Prevent button click from bubbling
					} }
					size={ props.iconSize ?? undefined }
				/>
			</CardHeader>
		),
		[ isOpen, toggle ]
	);

	return {
		isOpen,
		toggle,
		CardHeader: CollapsibleCardHeader,
	};
}
