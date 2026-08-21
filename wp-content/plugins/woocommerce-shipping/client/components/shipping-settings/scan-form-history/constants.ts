import { __ } from '@wordpress/i18n';

export const tableHeaders = [
	{
		key: 'scanFormId',
		label: __( 'SCAN Form', 'woocommerce-shipping' ).toUpperCase(),
		isLeftAligned: true,
		isSortable: false,
		required: true,
	},
	{
		key: 'originAddress',
		label: __( 'Ship From', 'woocommerce-shipping' ).toUpperCase(),
		isLeftAligned: true,
		isSortable: false,
		required: true,
	},
	{
		key: 'labelCount',
		label: __( 'Labels', 'woocommerce-shipping' ).toUpperCase(),
		isSortable: false,
		isNumeric: true,
		required: true,
	},
	{
		key: 'actions',
		label: __( 'Actions', 'woocommerce-shipping' ).toUpperCase(),
		isSortable: false,
		required: true,
	},
];
