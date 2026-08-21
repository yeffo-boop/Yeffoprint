import { __ } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';
import type { ComponentType, ReactNode } from 'react';
import { Label } from 'types';
import { Conditional } from '../../HOC';
import { getLabelTrackingUrl } from './label-panel-actions';

interface TrackShipmentProps {
	label?: Label;
	className?: string;
	onClick?: () => void;
	children?: ReactNode;
}

export const TrackShipment = Conditional(
	( { label }: TrackShipmentProps ) => {
		const trackingUrl = getLabelTrackingUrl( label );
		const render = Boolean( label && trackingUrl );
		return {
			render,
			props: {
				trackingUrl,
			},
		};
	},
	// @ts-expect-error // Conditional is written in js
	( {
		trackingUrl,
		className,
		onClick,
		children,
	}: {
		isBusy: boolean;
		trackingUrl: string;
		className?: string;
		onClick?: () => void;
		children?: ReactNode;
	} ) => (
		<ExternalLink
			href={ trackingUrl }
			className={ className }
			onClick={ onClick }
		>
			{ children ?? __( 'Track shipment', 'woocommerce-shipping' ) }
		</ExternalLink>
	),
	() => null
) as ComponentType< TrackShipmentProps >;
