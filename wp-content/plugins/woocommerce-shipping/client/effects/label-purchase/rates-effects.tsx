import { TAB_NAMES } from 'components/label-purchase/packages';
import { useThrottledStateChange } from '../utils';
import { LabelPurchaseContextType } from 'context/label-purchase';
import { CustomPackage } from 'types';
import { useCallback } from '@wordpress/element';

export const useRatesEffects = ( {
	rates: { updateRates, fetchRates },
	customs: { isCustomsNeeded, customsFingerprint },
	shipment: { getCurrentShipmentDate },
	weight: { getShipmentTotalWeight },
	packages: { getCustomPackage, getSelectedPackage, currentPackageTab },
}: LabelPurchaseContextType ) => {
	// Update rates when isCustomsNeeded changes
	useThrottledStateChange( isCustomsNeeded(), updateRates );

	// Update rates when customs item values change (price, weight, quantity, etc.)
	useThrottledStateChange( customsFingerprint, updateRates );

	// Update rates when the shipment dates changes
	useThrottledStateChange( getCurrentShipmentDate(), updateRates );

	// Update rates when the custom package dimensions change
	const hasValidDimensions = ( pkg: CustomPackage ) => {
		const { length, width, height } = pkg;
		return (
			parseInt( length ?? '0', 10 ) > 0 &&
			parseInt( width ?? '0', 10 ) > 0 &&
			parseInt( height ?? '0', 10 ) > 0
		);
	};

	const hasValidWeight = ( weight: number ) => weight > 0;

	const customPackage = getCustomPackage();
	const selectedPackage = getSelectedPackage();
	const totalWeight = getShipmentTotalWeight();

	// Memoize the custom package state to avoid unnecessary re-renders
	const customPackageState = useCallback( () => {
		if (
			customPackage &&
			currentPackageTab === TAB_NAMES.CUSTOM_PACKAGE &&
			hasValidWeight( totalWeight ) &&
			hasValidDimensions( customPackage )
		) {
			return `${ customPackage.type }-${ customPackage?.width }x${ customPackage?.height }x${ customPackage?.length }-${ totalWeight }`;
		}
		return null;
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		customPackage?.type,
		customPackage?.width,
		customPackage?.height,
		customPackage?.length,
		totalWeight,
		currentPackageTab,
	] );

	// Update rates when the custom package dimensions change
	useThrottledStateChange( customPackageState(), () =>
		fetchRates( customPackage )
	);

	// Memoize the selected package state to avoid unnecessary re-renders
	const selectedPackageState = useCallback( () => {
		if (
			selectedPackage &&
			hasValidWeight( totalWeight ) &&
			( currentPackageTab === TAB_NAMES.CARRIER_PACKAGE ||
				currentPackageTab === TAB_NAMES.SAVED_TEMPLATES )
		) {
			return selectedPackage.id + '-' + totalWeight;
		}
		return null;
	}, [ selectedPackage?.id, totalWeight, currentPackageTab ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Update rates when the selected package or weight changes
	useThrottledStateChange( selectedPackageState(), updateRates );
};
