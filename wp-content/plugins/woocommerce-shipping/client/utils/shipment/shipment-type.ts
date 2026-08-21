/**
 * Shipment Type Constants and Utilities
 *
 * Centralized constants and utility functions for managing different types
 * of shipments in the WooCommerce Shipping plugin.
 *
 * @since 1.7.0
 */

/**
 * Available shipment types in the WooCommerce Shipping plugin
 *
 * @readonly
 * @enum {string}
 */
export const SHIPMENT_TYPE = {
	/**
	 * Regular outbound shipments from merchant to customer
	 * - Standard purchase flow
	 * - Includes shipping date selection
	 * - Ship from: merchant address
	 * - Ship to: customer address
	 */
	OUTBOUND: 'outbound',

	/**
	 * Return shipments from customer back to merchant
	 * - Return label functionality
	 * - No shipping date selection (returns have extended windows)
	 * - Ship from: customer address (swapped)
	 * - Return to: merchant address (swapped)
	 * - Currently domestic-only (US to US)
	 * - Requires address validation for both endpoints
	 */
	RETURN: 'return',
} as const;

/**
 * Type definition for shipment types
 */
export type ShipmentType =
	( typeof SHIPMENT_TYPE )[ keyof typeof SHIPMENT_TYPE ];

/**
 * Utility functions for working with shipment types
 */
export const ShipmentTypeUtils = {
	/**
	 * Check if a value is a valid shipment type
	 *
	 * @param value - Value to check
	 * @return True if value is a valid shipment type
	 *
	 * @example
	 * ```typescript
	 * ShipmentTypeUtils.isValidType('outbound'); // true
	 * ShipmentTypeUtils.isValidType('invalid'); // false
	 * ```
	 */
	isValidType: ( value: unknown ): value is ShipmentType => {
		return Object.values( SHIPMENT_TYPE ).includes( value as ShipmentType );
	},

	/**
	 * Check if a shipment type is for outbound shipments
	 *
	 * @param type - Shipment type to check
	 * @return True if type is outbound
	 *
	 * @example
	 * ```typescript
	 * ShipmentTypeUtils.isOutbound('outbound'); // true
	 * ShipmentTypeUtils.isOutbound('return'); // false
	 * ```
	 */
	isOutbound: ( type: ShipmentType ): boolean => {
		return type === SHIPMENT_TYPE.OUTBOUND;
	},

	/**
	 * Check if a shipment type is for return shipments
	 *
	 * @param type - Shipment type to check
	 * @return True if type is return
	 *
	 * @example
	 * ```typescript
	 * ShipmentTypeUtils.isReturn('return'); // true
	 * ShipmentTypeUtils.isReturn('outbound'); // false
	 * ```
	 */
	isReturn: ( type: ShipmentType ): boolean => {
		return type === SHIPMENT_TYPE.RETURN;
	},

	/**
	 * Get user-friendly display name for a shipment type
	 *
	 * @param type - Shipment type
	 * @return Human-readable name
	 *
	 * @example
	 * ```typescript
	 * ShipmentTypeUtils.getDisplayName('outbound'); // "Outbound Shipment"
	 * ShipmentTypeUtils.getDisplayName('return'); // "Return Shipment"
	 * ```
	 */
	getDisplayName: ( type: ShipmentType ): string => {
		switch ( type ) {
			case SHIPMENT_TYPE.OUTBOUND:
				return 'Outbound Shipment';
			case SHIPMENT_TYPE.RETURN:
				return 'Return Shipment';
			default:
				return 'Unknown Shipment Type';
		}
	},

	/**
	 * Get description for a shipment type
	 *
	 * @param type - Shipment type
	 * @return Description of the shipment type
	 *
	 * @example
	 * ```typescript
	 * ShipmentTypeUtils.getDescription('return');
	 * // "Customer returning items to merchant"
	 * ```
	 */
	getDescription: ( type: ShipmentType ): string => {
		switch ( type ) {
			case SHIPMENT_TYPE.OUTBOUND:
				return 'Merchant shipping items to customer';
			case SHIPMENT_TYPE.RETURN:
				return 'Customer returning items to merchant';
			default:
				return 'Unknown shipment type';
		}
	},

	/**
	 * Get the appropriate address labels for a shipment type
	 *
	 * @param type - Shipment type
	 * @return Object with appropriate address labels
	 *
	 * @example
	 * ```typescript
	 * ShipmentTypeUtils.getAddressLabels('outbound');
	 * // { from: "Ship from", to: "Ship to" }
	 *
	 * ShipmentTypeUtils.getAddressLabels('return');
	 * // { from: "Ship from", to: "Return to" }
	 * ```
	 */
	getAddressLabels: ( type: ShipmentType ): { from: string; to: string } => {
		switch ( type ) {
			case SHIPMENT_TYPE.OUTBOUND:
				return {
					from: 'Ship from',
					to: 'Ship to',
				};
			case SHIPMENT_TYPE.RETURN:
				return {
					from: 'Ship from',
					to: 'Return to',
				};
			default:
				return {
					from: 'Ship from',
					to: 'Ship to',
				};
		}
	},

	/**
	 * Check if a shipment type supports certain features
	 *
	 * @param type - Shipment type
	 * @return Object indicating feature support
	 *
	 * @example
	 * ```typescript
	 * ShipmentTypeUtils.getFeatureSupport('return');
	 * // {
	 * //   shippingDate: false,
	 * //   pickupScheduling: false,
	 * //   internationalShipping: false
	 * // }
	 * ```
	 */
	getFeatureSupport: (
		type: ShipmentType
	): {
		shippingDate: boolean;
		pickupScheduling: boolean;
		internationalShipping: boolean;
		addressSwapping: boolean;
	} => {
		switch ( type ) {
			case SHIPMENT_TYPE.OUTBOUND:
				return {
					shippingDate: true,
					pickupScheduling: true,
					internationalShipping: true,
					addressSwapping: false,
				};
			case SHIPMENT_TYPE.RETURN:
				return {
					shippingDate: false, // Returns have extended time windows
					pickupScheduling: false, // Customer drops off or schedules their own pickup
					internationalShipping: false, // Currently domestic-only
					addressSwapping: true, // Customer ships to merchant
				};
			default:
				return {
					shippingDate: false,
					pickupScheduling: false,
					internationalShipping: false,
					addressSwapping: false,
				};
		}
	},

	/**
	 * Get validation requirements for a shipment type
	 *
	 * @param type - Shipment type
	 * @return Object with validation requirements
	 *
	 * @example
	 * ```typescript
	 * ShipmentTypeUtils.getValidationRequirements('return');
	 * // {
	 * //   domesticOnly: true,
	 * //   verifiedAddresses: true,
	 * //   businessReturnAddress: true
	 * // }
	 * ```
	 */
	getValidationRequirements: (
		type: ShipmentType
	): {
		domesticOnly: boolean;
		verifiedAddresses: boolean;
		businessReturnAddress: boolean;
		requiredContactInfo: boolean;
	} => {
		switch ( type ) {
			case SHIPMENT_TYPE.OUTBOUND:
				return {
					domesticOnly: false,
					verifiedAddresses: false,
					businessReturnAddress: false,
					requiredContactInfo: false,
				};
			case SHIPMENT_TYPE.RETURN:
				return {
					domesticOnly: true,
					verifiedAddresses: true,
					businessReturnAddress: true,
					requiredContactInfo: true,
				};
			default:
				return {
					domesticOnly: false,
					verifiedAddresses: false,
					businessReturnAddress: false,
					requiredContactInfo: false,
				};
		}
	},

	/**
	 * Get all available shipment types
	 *
	 * @return Array of all shipment types
	 *
	 * @example
	 * ```typescript
	 * ShipmentTypeUtils.getAllTypes(); // ['outbound', 'return']
	 * ```
	 */
	getAllTypes: (): ShipmentType[] => {
		return Object.values( SHIPMENT_TYPE );
	},

	/**
	 * Parse shipment type from string with fallback
	 *
	 * @param value    - String value to parse
	 * @param fallback - Fallback value if parsing fails
	 * @return Parsed shipment type or fallback
	 *
	 * @example
	 * ```typescript
	 * ShipmentTypeUtils.parseType('return'); // 'return'
	 * ShipmentTypeUtils.parseType('invalid', 'outbound'); // 'outbound'
	 * ```
	 */
	parseType: (
		value: string | null | undefined,
		fallback: ShipmentType = SHIPMENT_TYPE.OUTBOUND
	): ShipmentType => {
		if ( ! value ) {
			return fallback;
		}

		const normalized = value.toLowerCase().trim();
		return ShipmentTypeUtils.isValidType( normalized )
			? normalized
			: fallback;
	},
};

/**
 * Type guard to check if a shipment is a return shipment
 *
 * @param shipment - Shipment object or shipment type
 * @return True if shipment is a return shipment
 *
 * @example
 * ```typescript
 * if (isReturnShipment(shipmentType)) {
 *   // Handle return shipment logic
 *   showReturnSpecificUI();
 * }
 * ```
 */
export const isReturnShipment = (
	shipment: { type?: string } | string | null | undefined
): boolean => {
	if ( ! shipment ) {
		return false;
	}
	const type = typeof shipment === 'string' ? shipment : shipment.type;
	return ShipmentTypeUtils.isReturn( type as ShipmentType );
};

/**
 * Type guard to check if a shipment is an outbound shipment
 *
 * @param shipment - Shipment object or shipment type
 * @return True if shipment is an outbound shipment
 *
 * @example
 * ```typescript
 * if (isOutboundShipment(shipmentType)) {
 *   // Handle outbound shipment logic
 *   enableShippingDateSelector();
 * }
 * ```
 */
export const isOutboundShipment = (
	shipment: { type?: string } | string | null | undefined
): boolean => {
	if ( ! shipment ) {
		return false;
	}
	const type = typeof shipment === 'string' ? shipment : shipment.type;
	return ShipmentTypeUtils.isOutbound( type as ShipmentType );
};
