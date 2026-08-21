import React, { JSX } from 'react';
import { StoreNotice as Notice } from 'types';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
// @ts-ignore
import { extensionCartUpdate } from '@woocommerce/blocks-checkout';
import { __ } from '@wordpress/i18n';
import './styles.scss';

const noticesContext = 'wc/checkout/shipping-address';
const noticeIdPrefix = 'wcshipping-av-';
const wcCheckoutScope =
	document.querySelector( '.woocommerce-checkout' ) ?? document;

type Extensions = Record<
	string,
	{
		notices: Notice[];
	}
>;

interface Props {
	extensions: Extensions;
	cart: {
		shippingAddress: {
			country: string;
		};
	};
}

interface WPNotice {
	id: string;
}

export const AddressValidationNotices = ( {
	extensions,
	cart,
}: Props ): JSX.Element => {
	const shipToCountry = cart?.shippingAddress?.country;
	const { createNotice, removeNotices } = useDispatch( 'core/notices' );

	// Get all existing notices that are related to the address validation.
	const existingNoticeIds = useSelect( ( select ) => {
		// @ts-ignore
		const notices = select( 'core/notices' ).getNotices( noticesContext );

		return notices
			.map( ( notice: WPNotice ) => notice.id )
			.filter( ( id: string ) => id.startsWith( noticeIdPrefix ) );
	}, [] );

	// If the shipToCountry changes, remove the notices.
	useEffect(
		() => {
			if ( ! shipToCountry ) {
				return;
			}

			removeNotices( existingNoticeIds, noticesContext );
		}, // The effect should only rely on shipToCountry
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ shipToCountry ]
	);

	// If the notices change, update the notices.
	useEffect(
		() => {
			removeNotices( existingNoticeIds, noticesContext );

			const newNotices =
				extensions[ 'woocommerce-shipping' ]?.notices ?? [];

			if ( newNotices.length === 0 ) {
				return;
			}

			newNotices.forEach( ( notice: Notice, index: number ) => {
				const { type, message } = notice;

				createNotice( type, message, {
					id: noticeIdPrefix + index,
					context: noticesContext,
				} );
			} );
		}, // The effect should only rely on extensions
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ extensions ]
	);

	// Handle the event to apply the suggested address.
	useEffect( () => {
		const applySuggestedAddressHandler = ( event: Event ) => {
			const customEvent = event as CustomEvent;

			const {
				suggestedAddress,
				useShippingAsBilling,
				storeApiIdentifier,
			} = customEvent.detail;

			extensionCartUpdate( {
				namespace: storeApiIdentifier,
				data: {
					action: 'apply_suggested_shipping_address',
					suggested_address: suggestedAddress,
					use_shipping_as_billing: useShippingAsBilling,
				},
			} )
				.then( () => {
					removeNotices( existingNoticeIds, noticesContext );
				} )
				.catch( () => {
					createNotice(
						'warning',
						__(
							'We were unable to update your shipping address. Please verify that the entered address is correct.',
							'woocommerce-shipping'
						),
						{
							id: noticeIdPrefix + '0',
							context: noticesContext,
						}
					);
				} );
		};

		// Listen for custom wcShippingApplySuggestedAddress event to apply the suggested address.
		wcCheckoutScope.addEventListener(
			'wcShippingApplySuggestedAddress',
			applySuggestedAddressHandler
		);

		return () => {
			// Remove the event listener when the component is unmounted.
			wcCheckoutScope.removeEventListener(
				'wcShippingApplySuggestedAddress',
				applySuggestedAddressHandler
			);
		};
	} );

	return <></>;
};
