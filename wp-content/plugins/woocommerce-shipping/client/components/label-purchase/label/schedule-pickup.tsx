import { ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { Label } from 'types';
import { getLabelPickupUrl } from './label-panel-actions';

interface SchedulePickupProps {
	selectedLabel?: Label;
	className?: string;
	onClick?: () => void;
	children?: ReactNode;
}

export const SchedulePickup = ( {
	selectedLabel,
	className,
	onClick,
	children,
}: SchedulePickupProps ) => {
	const pickupUrl = getLabelPickupUrl( selectedLabel );

	if ( ! pickupUrl ) {
		return null;
	}

	return (
		<ExternalLink
			href={ pickupUrl }
			className={ className }
			onClick={ onClick }
		>
			{ children ?? __( 'Schedule pickup', 'woocommerce-shipping' ) }
		</ExternalLink>
	);
};
