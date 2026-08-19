import { useCallback } from '@wordpress/element';
import { useState } from 'react';

interface UseTotalWeightProps {
	currentShipmentId: string;
	shipmentWeight: number;
}

export const useTotalWeight = ( {
	currentShipmentId,
	shipmentWeight,
}: UseTotalWeightProps ) => {
	const [ totalWeight, setTotalWeight ] = useState<
		Record< string, number >
	>( {
		[ currentShipmentId ]: shipmentWeight || 0,
	} );

	const setShipmentTotalWeight = useCallback(
		( weight: number ) => {
			setTotalWeight( ( prev ) => ( {
				...prev,
				[ currentShipmentId ]: weight,
			} ) );
		},
		[ currentShipmentId ]
	);

	const getShipmentTotalWeight = useCallback(
		(): number => totalWeight[ currentShipmentId ],
		[ currentShipmentId, totalWeight ]
	);

	return {
		getShipmentTotalWeight,
		setShipmentTotalWeight,
	};
};
