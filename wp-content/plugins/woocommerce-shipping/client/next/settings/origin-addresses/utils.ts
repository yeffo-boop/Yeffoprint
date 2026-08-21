/**
 * External dependencies
 */

/**
 * Internal dependencies
 */
import type { AddressEntityRecord } from '../../data';

export const getItemId = ( item: AddressEntityRecord ) => item.id;

export const getAddressTitle = ( { item }: { item: AddressEntityRecord } ) =>
	item.company || `${ item.first_name } ${ item.last_name }`;

export const getAddressDetail = ( { item }: { item: AddressEntityRecord } ) =>
	`${ item.address_1 }, ${ item.city }, ${ item.state }, ${ item.postcode }, ${ item.country }`;
