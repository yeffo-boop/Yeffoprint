/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	Modal,
	__experimentalSpacer as Spacer,
	Spinner,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { Notice } from '@wordpress/ui';

/*
 * Internal dependencies
 */
import { camelCaseKeys, formatAddressFields, snakeCaseKeys } from 'utils';
import { AddressStep } from 'components/address-step';
import { ADDRESS_STORE_NAME } from 'data/constants';
import { useConfigLoader } from 'next/utils';
import type { OriginAddress } from 'types';
import { defaultAddress } from './constants';

interface OriginAddressModalProps {
	addressId?: string;
	initialAddress?: OriginAddress;
	onClose: () => void;
	onComplete: () => void;
	clearStoreAddressDriftOnSave?: boolean;
}

/**
 * Renders the modal content that uses the address store. Only mounted after
 * config is loaded so the store is registered. Uses store name (not reference)
 * to avoid getStoreName( undefined ) when addressStore variable is not set.
 */
const OriginAddressModalContent = ( {
	addressId,
	initialAddress,
	onClose,
	onComplete,
	clearStoreAddressDriftOnSave,
}: OriginAddressModalProps ) => {
	const origins = useSelect( ( select ) => {
		const store = select( ADDRESS_STORE_NAME ) as {
			getOriginAddresses: () => OriginAddress[];
		};
		return store.getOriginAddresses();
	}, [] );

	const address =
		initialAddress ??
		origins.find( ( origin: OriginAddress ) => origin.id === addressId );
	const isAdd = ! address;

	return (
		<Modal
			size="medium"
			onRequestClose={ onClose }
			title={
				isAdd
					? __( 'Add address', 'woocommerce-shipping' )
					: __( 'Edit address', 'woocommerce-shipping' )
			}
		>
			{ clearStoreAddressDriftOnSave && (
				<>
					<Notice.Root intent="warning">
						<Notice.Description>
							{ __(
								'The store address is already filled in below. Validate and save this sender address if you want to sync it with your store address.',
								'woocommerce-shipping'
							) }
						</Notice.Description>
					</Notice.Root>
					<Spacer marginBottom={ 4 } />
				</>
			) }
			<AddressStep
				type={ 'origin' }
				address={ camelCaseKeys(
					formatAddressFields(
						snakeCaseKeys( address ?? defaultAddress )
					)
				) }
				onCompleteCallback={ onComplete }
				onCancelCallback={ onClose }
				isAdd={ ! address }
				nextDesign
				surfaceArea="settings_shipping"
				clearStoreAddressDriftOnSave={ clearStoreAddressDriftOnSave }
			/>
		</Modal>
	);
};

export const OriginAddressModal = ( props: OriginAddressModalProps ) => {
	const isConfigReady = useConfigLoader();

	if ( ! isConfigReady ) {
		return (
			<Modal size="medium" onRequestClose={ props.onClose }>
				<Spinner />
			</Modal>
		);
	}

	return <OriginAddressModalContent { ...props } />;
};
