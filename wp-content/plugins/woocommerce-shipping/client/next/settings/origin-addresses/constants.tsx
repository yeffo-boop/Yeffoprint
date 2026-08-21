/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * External dependencies
 */
import type { Field, Form } from '@wordpress/dataviews/wp';
import type { AddressEntityRecord } from 'next/data';
import { ACCEPTED_ORIGIN_COUNTRIES } from 'next/data';

export const fields: Field< Partial< AddressEntityRecord > >[] = [
	{
		id: 'first_name',
		type: 'text',
		label: __( 'Name', 'woocommerce-shipping' ),
		getValue: ( { item } ) => `${ item.first_name } ${ item.last_name }`,
		setValue: ( { item, value } ) => {
			/* eslint-disable camelcase */
			const [ first_name, ...last_name ] = value.split( ' ' );
			return {
				...item,
				first_name,
				last_name: last_name.join( ' ' ),
			};
			/* eslint-enable camelcase */
		},
	},
	{
		id: 'company',
		type: 'text',
		label: __( 'Company', 'woocommerce-shipping' ),
	},
	{
		id: 'email',
		type: 'text',
		label: __( 'Email Address', 'woocommerce-shipping' ),
		placeholder: __( 'mail@example.com', 'woocommerce-shipping' ),
	},
	{
		id: 'phone',
		type: 'text',
		label: __( 'Phone', 'woocommerce-shipping' ),
		placeholder: __( '123456789', 'woocommerce-shipping' ),
	},
	{
		id: 'country',
		type: 'text',
		label: __( 'Country', 'woocommerce-shipping' ),
		elements: Object.entries( ACCEPTED_ORIGIN_COUNTRIES ).map(
			( [ value, label ] ) => ( { value, label: label as string } )
		),
	},
	{
		id: 'address_1',
		type: 'text',
		label: __( 'Address', 'woocommerce-shipping' ),
		placeholder: __( 'Address', 'woocommerce-shipping' ),
	},
	{
		id: 'city',
		type: 'text',
		placeholder: __( 'City', 'woocommerce-shipping' ),
	},
	{
		id: 'state',
		type: 'text',
		placeholder: __( 'State', 'woocommerce-shipping' ),
	},
	{
		id: 'postcode',
		type: 'text',
		placeholder: __( 'Postal Code', 'woocommerce-shipping' ),
	},
	{
		id: 'default_address',
		type: 'boolean',
		label: __( 'Save as default sender address', 'woocommerce-shipping' ),
	},
];

export const form: Form = {
	fields: [
		'first_name',
		'company',
		{
			id: 'email_phone_row',
			layout: { type: 'row' },
			children: [ 'email', 'phone' ],
		},
		'address_1',
		{
			id: 'city_state_row',
			layout: {
				type: 'row',
			},
			children: [ 'city', 'state' ],
		},
		{
			id: 'postcode_country_row',
			layout: {
				type: 'row',
			},
			children: [ 'postcode', 'country' ],
		},
		'default_address',
	],
};

export const defaultAddress: Partial< AddressEntityRecord > = {
	country: 'US',
};
