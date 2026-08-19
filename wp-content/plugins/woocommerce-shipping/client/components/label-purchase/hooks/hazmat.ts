import { useCallback, useEffect, useState } from '@wordpress/element';
import { applyShipmentHazmat } from 'utils/rates';
import {
	Hazmat,
	HazmatState,
	LabelRequestPackages,
	RequestPackage,
} from 'types';
import { select } from '@wordpress/data';
import { labelPurchaseStore } from 'data/label-purchase';

const defaultHazmatStateForShipment: Hazmat = {
	isHazmat: false,
	category: '',
};

export function useHazmatState( currentShipmentId: string | number ) {
	const [ state, set ] = useState< HazmatState >(
		select( labelPurchaseStore ).getSelectedHazmatConfig() ?? {
			0: defaultHazmatStateForShipment,
		}
	);

	const setShipmentHazmat = useCallback(
		( isHazmat: boolean, category: string ) => {
			const newShipmentHazmat = { ...state };
			newShipmentHazmat[ currentShipmentId ] = {
				isHazmat,
				category,
			};
			set( newShipmentHazmat );
		},
		[ state, currentShipmentId ]
	);

	useEffect( () => {
		if ( ! state[ currentShipmentId ] ) {
			setShipmentHazmat( false, '' );
		}
	}, [ currentShipmentId, setShipmentHazmat, state ] );

	const getShipmentHazmat = useCallback(
		() => state[ currentShipmentId ] ?? defaultHazmatStateForShipment,
		[ state, currentShipmentId ]
	);

	const applyHazmatToPackage = useCallback(
		( shipmentPackage: RequestPackage | LabelRequestPackages ) =>
			applyShipmentHazmat( shipmentPackage, getShipmentHazmat() ),
		[ getShipmentHazmat ]
	);

	const isHazmatSpecified = useCallback( () => {
		const hazmat = getShipmentHazmat();
		if ( ! hazmat.isHazmat ) {
			return true;
		}
		return Boolean( hazmat.category );
	}, [ getShipmentHazmat ] );

	return {
		getShipmentHazmat,
		setShipmentHazmat,
		applyHazmatToPackage,
		isHazmatSpecified,
	};
}
