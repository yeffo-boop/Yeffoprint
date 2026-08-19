/**
 * Centralized utilities for syncing data to global window config
 *
 * These utilities handle the necessary side effect of updating the global
 * WCShipping_Config object, which maintains state across different parts
 * of the application. While mutating global state is generally discouraged,
 * it's currently required for compatibility with the existing architecture.
 */

/**
 * Syncs package dimensions to the global config
 *
 * @param packageDimensions - Package dimensions data to sync
 */
export const syncPackageDimensionsToGlobalConfig = (
	packageDimensions?: Record< string, unknown >
): void => {
	if (
		! packageDimensions ||
		! window.WCShipping_Config ||
		! ( 'shippingLabelData' in window.WCShipping_Config )
	) {
		return;
	}

	// Ensure shippingLabelData exists
	if ( ! window.WCShipping_Config.shippingLabelData ) {
		window.WCShipping_Config.shippingLabelData = {} as never;
	}

	// Ensure storedData exists
	if ( ! window.WCShipping_Config.shippingLabelData.storedData ) {
		window.WCShipping_Config.shippingLabelData.storedData = {} as never;
	}

	// Merge package dimensions
	window.WCShipping_Config.shippingLabelData.storedData.package_dimensions = {
		...( window.WCShipping_Config.shippingLabelData.storedData
			.package_dimensions || {} ),
		...packageDimensions,
	};
};
