/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Entity } from './types';

export const ADDRESS_ENTITY: Entity = {
	name: 'address',
	kind: 'root',
	baseURL: '/wcshipping/v2/addresses',
	label: __( 'Address', 'woocommerce-shipping' ),
	plural: __( 'Addresses', 'woocommerce-shipping' ),
	key: 'id',
	supportsPagination: false,
};

export const ACCEPTED_ORIGIN_COUNTRIES = {
	US: 'United States',
	PR: 'Puerto Rico',
	VI: 'Virgin Islands',
	GU: 'Guam',
	AS: 'American Samoa',
	UM: 'United States Minor Outlying Islands',
	MH: 'Marshall Islands',
	FM: 'Micronesia',
	MP: 'Northern Mariana Islands',
} as const;

/** Route path for Shipping → Operations settings (use with WCShipping_Config.navigate). */
export const SHIPPING_OPERATIONS_PATH =
	'/woocommerce/settings/shipping/operations';
