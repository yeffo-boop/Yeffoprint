import React from 'react';
import { Label } from 'types';
import { ShipmentTrackingEvent } from '../event';

interface ShipmentTrackingDayProps {
	date: string;
	labels: Label[] | [];
}

export const ShipmentTrackingDay = ( {
	date,
	labels,
}: ShipmentTrackingDayProps ) => {
	return (
		<div key={ date } className="shipment-tracking__date">
			<h3>{ date }</h3>
			{ labels.map( ( label ) => (
				<ShipmentTrackingEvent key={ label.id } label={ label } />
			) ) }
		</div>
	);
};
