import { __ } from '@wordpress/i18n';

// USPS Labels v3 enforces a 30-character maximum on customsForm/contents/itemDescription.
export const MAX_ITEM_DESCRIPTION_LENGTH = 30;

export const contentTypes = [
	{
		label: __( 'Merchandise', 'woocommerce-shipping' ),
		value: 'merchandise',
	},
	{
		label: __( 'Gift', 'woocommerce-shipping' ),
		value: 'gift',
	},
	{
		label: __( 'Returned Goods', 'woocommerce-shipping' ),
		value: 'returned_goods',
	},
	{
		label: __( 'Sample', 'woocommerce-shipping' ),
		value: 'sample',
	},
	{
		label: __( 'Documents', 'woocommerce-shipping' ),
		value: 'documents',
	},
	{
		label: __( 'Other', 'woocommerce-shipping' ),
		value: 'other',
	},
];

export const restrictionTypes = [
	{
		label: __( 'None', 'woocommerce-shipping' ),
		value: 'none',
	},
	{
		label: __( 'Quarantine', 'woocommerce-shipping' ),
		value: 'quarantine',
	},
	{
		label: __(
			'Sanitary/Phytosanitary Inspection',
			'woocommerce-shipping'
		),
		value: 'sanitary_phytosanitary_inspection',
	},
	{
		label: __( 'Other…', 'woocommerce-shipping' ),
		value: 'other',
	},
];

// Validates AES/ITN (International Transaction Number) or NOEEI (No EEI) exemption codes
// Accepts formats like:
// - AES ITN: X12345678901234, AES 12345678901234 or AES ITN: 12345678901234
// - NOEEI exemptions: NOEEI 30.36 or NOEEI 30.36(a) or NOEEI 30.36(a)(1)
// AES/ITN numbers which are 14 digits long, optionally prefixed with 'X', 'AES', and/or 'ITN'
// NOEEI exemption codes in the format "NOEEI 30.XX" with optional subsection letters and numbers
export const itnMatchingRegex =
	/^(?:(?:AES\s*ITN:?\s*)?(?:AES\s*)?X?\d{14}|(?:NOEEI\s+30\.\d{1,2}(?:\([a-z]\)(?:\(\d\))?)?))$/i;
