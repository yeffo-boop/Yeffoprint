import React from 'react';
import { Label } from 'types';
import { __ } from '@wordpress/i18n';
import { Tooltip } from '@wordpress/components';
import { Icon, shipping, keyboardReturn } from '@wordpress/icons';
import { LABEL_PURCHASE_STATUS } from 'data/constants';
import { ShipmentTrackingLink } from '../link/link';

interface ShipmentTrackingEventProps {
	label: Label;
}

export const ShipmentTrackingEvent = ( {
	label,
}: ShipmentTrackingEventProps ) => {
	const showIcon = label.status === LABEL_PURCHASE_STATUS.PURCHASED;

	return (
		<div className="shipment-tracking__event">
			<div className="shipment-tracking__event__meta">
				{ showIcon &&
					( label.isReturn ? (
						<Tooltip
							text={ __( 'Return', 'woocommerce-shipping' ) }
						>
							<div className="shipment-tracking__event__meta__icon">
								<Icon icon={ keyboardReturn } size={ 30 } />
							</div>
						</Tooltip>
					) : (
						<Tooltip
							text={ __( 'Shipment', 'woocommerce-shipping' ) }
						>
							<div className="shipment-tracking__event__meta__icon">
								<Icon icon={ shipping } size={ 30 } />
							</div>
						</Tooltip>
					) ) }
			</div>
			<div className="shipment-tracking__event__body">
				<ShipmentTrackingLink label={ label } />
				<small>{ `(  ${ label.serviceName }  )` }</small>
			</div>
		</div>
	);
};
