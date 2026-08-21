import { useState } from '@wordpress/element';
export function useEssentialDetails() {
	const [ customsCompleted, setCustomsCompleted ] = useState( false );
	const [ shippingServiceCompleted, setShippingServiceCompleted ] =
		useState( false );
	const [ extraLabelPurchaseCompleted, setExtraLabelPurchaseCompleted ] =
		useState( false );
	const [ focusArea, setFocusArea ] = useState( '' );

	const isCustomsCompleted = () => {
		return customsCompleted;
	};

	const isShippingServiceCompleted = () => {
		return shippingServiceCompleted;
	};

	const isExtraLabelPurchaseCompleted = () => {
		return extraLabelPurchaseCompleted;
	};

	const resetFocusArea = () => {
		setFocusArea( '' );
	};

	return {
		isCustomsCompleted,
		setCustomsCompleted,
		isShippingServiceCompleted,
		setShippingServiceCompleted,
		isExtraLabelPurchaseCompleted,
		setExtraLabelPurchaseCompleted,
		focusArea,
		resetFocusArea,
		setFocusArea,
	};
}
