import { __ } from '@wordpress/i18n';

export const tableHeaders = [
	{
		key: 'createdDate',
		label: __( 'Date', 'woocommerce-shipping' ),
		isLeftAligned: true,
		isSortable: false,
		required: true,
	},
	{
		key: 'orderId',
		label: __( 'Order', 'woocommerce-shipping' ),
		isSortable: false,
		isNumeric: false,
		required: true,
	},
	{
		key: 'rate',
		label: __( 'Price', 'woocommerce-shipping' ),
		isSortable: false,
		isNumeric: true,
		required: true,
	},
	{
		key: 'serviceName',
		label: __( 'Service', 'woocommerce-shipping' ),
		isSortable: false,
		isNumeric: false,
		required: true,
	},
	{
		key: 'tracking',
		label: __( 'Tracking Number', 'woocommerce-shipping' ),
		isSortable: false,
		isLeftAligned: true,
		isNumeric: true,
		required: false,
	},
	{
		key: 'refund',
		label: __( 'Refund status', 'woocommerce-shipping' ),
		isSortable: false,
		isNumeric: false,
	},
];
