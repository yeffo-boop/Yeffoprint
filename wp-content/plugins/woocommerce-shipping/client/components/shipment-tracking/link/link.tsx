import React from 'react';
import { Icon, external } from '@wordpress/icons';
import { Label } from 'types';
import { trackingUrls } from '../../label-purchase/label/constants';
import { __, sprintf } from '@wordpress/i18n';

import './style.scss';

interface ShipmentTrackingLinkProps {
	label: Label;
}

export const ShipmentTrackingLink = ( {
	label,
}: ShipmentTrackingLinkProps ) => {
	if ( ! label.tracking ) {
		return null;
	}

	const trackingUrl =
		label?.carrierId && label?.tracking
			? trackingUrls[ label.carrierId ]?.( label.tracking )
			: '';

	if ( trackingUrl === '' ) {
		return <span>{ label.tracking }</span>;
	}

	return (
		<div className="shipment-tracking__link">
			{ !! label?.refund && (
				<small className="shipment-tracking__pending-status">
					{ sprintf(
						// translators: %s: the status of the refund (e.g. "pending").
						__( '[ Refund - %s ]', 'woocommerce-shipping' ),
						label.refund.status
					) }
				</small>
			) }
			<a href={ trackingUrl } target="__blank" rel="noopener noreferrer">
				{ label.tracking }
			</a>
			<Icon icon={ external } size={ 15 } />
		</div>
	);
};
