export const TAB_NAMES = {
	CUSTOM_PACKAGE: 'custom-package',
	CARRIER_PACKAGE: 'carrier-package',
	SAVED_TEMPLATES: 'saved-templates',
};

export const CUSTOM_PACKAGE_TYPES = {
	BOX: 'box',
	ENVELOPE: 'envelope',
};

export const CARRIER_ID_TO_NAME = {
	usps: 'USPS',
	ups: 'UPS®',
	upsdap: 'UPS®',
	fedex: 'FedEx',
	dhl: 'DHL',
	dhlexpress: 'DHL Express',
	dhlecommerce: 'DHL eCommerce',
	dhlecommerceasia: 'DHL eCommerce Asia',
};

export const CUSTOM_BOX_ID_PREFIX = 'custom_box';
export const PACKAGE_CATEGORIES = {
	PREDEFINED: 'predefined',
	CUSTOM: 'custom',
} as const;
