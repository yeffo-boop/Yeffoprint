import React from 'react';
import { isEmpty } from 'lodash';
import { date } from '@wordpress/date';
import { __ } from '@wordpress/i18n';
import { Label } from 'types';
import { ShipmentTrackingDay } from './day';
import { LABEL_PURCHASE_STATUS } from 'data/constants';

interface ShipmentTrackingProps {
	labels: Label[] | [];
}

export const ShipmentTracking = ( { labels }: ShipmentTrackingProps ) => {
	const purchasedLabels = labels.filter( ( label ) =>
		[
			LABEL_PURCHASE_STATUS.PURCHASE_IN_PROGRESS,
			LABEL_PURCHASE_STATUS.PURCHASED,
		].includes( label.status )
	);

	// If there are no labels, show a create labels button.
	if ( isEmpty( purchasedLabels ) ) {
		return (
			<p>
				{ __(
					'No shipping labels have been created for this order yet.',
					'woocommerce-shipping'
				) }
			</p>
		);
	}

	// Loop through labels and reorganise them by date.
	const labelsByDate: Record< string, Label[] > = {};
	purchasedLabels.forEach( ( label ) => {
		const labelDateObject = new Date( label.created );
		const labelDate = date( 'M d, Y', labelDateObject, undefined );
		if ( ! labelsByDate[ labelDate ] ) {
			labelsByDate[ labelDate ] = [];
		}
		labelsByDate[ labelDate ].push( label );
	} );

	// Now loop through the labelsByDate object and sort the labels by time in descending order.
	const labelsByDateSorted: Record< string, Label[] > = {};
	Object.keys( labelsByDate ).forEach( ( labelDate ) => {
		labelsByDateSorted[ labelDate ] = labelsByDate[ labelDate ].sort(
			( a, b ) =>
				new Date( b.created ).getTime() -
				new Date( a.created ).getTime()
		);
	} );

	return (
		<div className="shipment-tracking">
			{ Object.keys( labelsByDateSorted ).map( ( labelDate ) => (
				<ShipmentTrackingDay
					key={ labelDate }
					date={ labelDate }
					labels={ labelsByDateSorted[ labelDate ] }
				/>
			) ) }
		</div>
	);
};
