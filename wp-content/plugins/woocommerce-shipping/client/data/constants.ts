export const STORE_NAME = 'wcshipping';
export const LABEL_PURCHASE_STORE_NAME = 'wcshipping/label-purchase';
export const SETTINGS_STORE_NAME = 'wcshipping/settings';
export const ADDRESS_STORE_NAME = 'wcshipping/address';
export const WPCOM_CONNECTION_STORE_NAME = 'wcshipping/wpcom-connection';
export const ANALYTICS_STORE_NAME = 'wcshipping/analytics';
export const BULK_LABELS_STORE_NAME = 'wcshipping/bulk-labels';
export const NAMESPACE = '/wcshipping/v1';
export const WC_NAMESPACE = '/wc/v3';
export const LABEL_RATE_TYPE = {
	DEFAULT: 'default',
	SIGNATURE_REQUIRED: 'signature_required',
	ADULT_SIGNATURE_REQUIRED: 'adult_signature_required',
	CARBON_NEUTRAL: 'carbon_neutral',
	ADDITIONAL_HANDLING: 'additional_handling',
	SATURDAY_DELIVERY: 'saturday_delivery',
} as const;

export const LABEL_RATE_OPTION = {
	SIGNATURE: 'signature',
	CARBON_NEUTRAL: 'carbon_neutral',
	ADDITIONAL_HANDLING: 'additional_handling',
	SATURDAY_DELIVERY: 'saturday_delivery',
} as const;

export const LABEL_PURCHASE_STATUS = {
	UNKNOWN: 'UNKNOWN',
	PURCHASE_IN_PROGRESS: 'PURCHASE_IN_PROGRESS',
	PURCHASED: 'PURCHASED',
	PURCHASE_ERROR: 'PURCHASE_ERROR',
	ANONYMIZED: 'ANONYMIZED',
} as const;

export const ADDRESS_TYPES = {
	ORIGIN: 'origin',
	DESTINATION: 'destination',
} as const;
