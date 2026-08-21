import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useCallback, useState } from '@wordpress/element';
import type { ReactNode } from 'react';
import { Label, Rate } from 'types';
import { RefundConfirmation } from './refund-confirmation';
import { canRequestLabelRefund } from './label-panel-actions';

interface RefundShipmentProps {
	label?: Label;
	selectedRate: { rate: Rate; parent: Rate | null } | null | undefined;
	isBusy: boolean;
	isDisabled: boolean;
	className?: string;
	onClick?: () => void;
	children?: ReactNode;
}

export const RefundShipment = ( {
	label,
	selectedRate,
	isBusy,
	isDisabled,
	className,
	onClick,
	children,
}: RefundShipmentProps ) => {
	const [ isRefunding, setIsRefunding ] = useState( false );
	const toggleRefund = useCallback(
		( open: boolean ) => () => {
			setIsRefunding( open );
		},
		[ setIsRefunding ]
	);
	const openRefund = useCallback( () => {
		onClick?.();
		setIsRefunding( true );
	}, [ onClick, setIsRefunding ] );

	// All hooks and computations called first, then conditional logic
	const caveats = selectedRate?.rate.caveats ?? [];
	const isNonRefundable = caveats.includes( 'non-refundable' );
	// Determine what to render based on computed values
	if ( isNonRefundable ) {
		return (
			<span className={ className }>
				{ __( 'Label is non-refundable', 'woocommerce-shipping' ) }
			</span>
		);
	}

	if ( ! canRequestLabelRefund( label, selectedRate ) ) {
		return null;
	}

	return (
		<>
			{ isRefunding && (
				<RefundConfirmation close={ toggleRefund( false ) } />
			) }
			<Button
				variant="tertiary"
				onClick={ openRefund }
				isBusy={ isBusy }
				aria-busy={ isBusy }
				disabled={ isDisabled }
				aria-disabled={ isDisabled }
				className={ className }
			>
				{ children ?? __( 'Request refund', 'woocommerce-shipping' ) }
			</Button>
		</>
	);
};
