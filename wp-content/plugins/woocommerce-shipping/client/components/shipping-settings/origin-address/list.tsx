import React from 'react';
import {
	__experimentalConfirmDialog as ConfirmDialog,
	__experimentalHeading as Heading,
	__experimentalHStack as HStack,
	__experimentalSpacer as Spacer,
	__experimentalText as Text,
	__experimentalVStack as VStack,
	Button,
	Card,
	CardBody,
	CardDivider,
	CardFooter,
	CardHeader,
	Flex,
	Modal,
	Notice,
} from '@wordpress/components';
import { dispatch, useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { help } from '@wordpress/icons';
import { addressStore } from 'data/address';
import { OriginAddress } from 'types';
import { AddressStep } from 'components/address-step';
import { ADDRESS_TYPES } from 'data/constants';
import { useShippingSettingsContext } from 'context/shipping-settings';
import { AddressColumn } from './address-column';
import { AddressVerifiedIcon } from '../../address-verified-icon';
import { ControlledPopover } from '../../controlled-popover';
import {
	camelCaseKeys,
	formatAddressFields,
	isOriginAddress,
	snakeCaseKeys,
} from 'utils';
import { emptyOriginAddress } from '../constants';

export const OriginAddressList = () => {
	const {
		originAddresses: {
			openOriginAddressForm,
			closeOriginAddressForm,
			isOriginAddressFormOpen,
			isOriginAddressDestroyConfirmationOpen,
			selectedOriginAddress,
			openOriginAddressDestroyConfirmation,
			closeOriginAddressDestroyConfirmation,
		},
	} = useShippingSettingsContext();

	const [ deletionError, setDeletionError ] = useState( '' );
	const addresses = useSelect(
		( select ) => select( addressStore ).getOriginAddresses(),
		[]
	);
	const openNewOriginAddressForm = () => {
		openOriginAddressForm( emptyOriginAddress );
	};

	const openEditAddressForm = ( address: OriginAddress ) => () => {
		openOriginAddressForm( address );
	};
	const destroyOriginAddress = async () => {
		if ( ! selectedOriginAddress ) {
			return;
		}
		setDeletionError( '' );
		try {
			await dispatch( addressStore ).deleteOriginAddress(
				selectedOriginAddress.id
			);
			closeOriginAddressDestroyConfirmation();
		} catch ( e ) {
			setDeletionError(
				( e as Error )?.message ??
					__(
						'An error occurred while deleting the address',
						'woocommerce-shipping'
					)
			);
		}
	};
	return (
		<Flex
			align="flex-start"
			gap={ 6 }
			justify="flex-start"
			className="wcshipping-settings"
		>
			<Flex direction="column">
				<Spacer marginTop={ 6 } marginBottom={ 0 } />
				<Heading level={ 4 }>
					{ __( 'Origin addresses', 'woocommerce-shipping' ) }
				</Heading>

				<Text>
					{ __(
						'Origin address sets where your products are shipped from, influencing shipping rates and taxes. You can always change your default address.',
						'woocommerce-shipping'
					) }
				</Text>
			</Flex>
			<Card className="wcshipping-settings__card" size="large">
				<CardHeader>
					<Heading level={ 5 }>
						{ __( 'Locations', 'woocommerce-shipping' ) }
					</Heading>
				</CardHeader>
				{ addresses.map( ( address: OriginAddress, index: number ) => (
					<VStack
						key={ `origin-address-${ index }` }
						className="origin-address-list-item"
					>
						<CardBody>
							<HStack>
								<AddressColumn address={ address } />

								<VStack justify="flex-start" alignment="left">
									{ address.isVerified !== true && (
										<HStack
											className="origin-address-list-item__default-address-container"
											justify="flex-start"
										>
											<AddressVerifiedIcon
												isVerified={ false }
												errorMessage={ __(
													'Verify your address',
													'woocommerce-shipping'
												) }
												onClick={ openEditAddressForm(
													address
												) }
												addressType={
													ADDRESS_TYPES.ORIGIN
												}
											/>
										</HStack>
									) }
								</VStack>

								<HStack className="origin-address-list-item__action-buttons-container">
									<Button
										variant="link"
										className="origin-address-list-item__action-buttons-button"
										onClick={ openEditAddressForm(
											address
										) }
									>
										{ __( 'Edit', 'woocommerce-shipping' ) }
									</Button>
									<Text color="#A0A0A0">|</Text>
									<Button
										isDestructive
										variant="link"
										className="origin-address-list-item__action-buttons-button"
										onClick={ () =>
											openOriginAddressDestroyConfirmation(
												address
											)
										}
										disabled={
											Boolean( address.defaultAddress ) ||
											Boolean(
												address.defaultReturnAddress
											) ||
											addresses.length === 1
										}
									>
										{ __(
											'Delete',
											'woocommerce-shipping'
										) }
									</Button>
									{ ( Boolean( address.defaultAddress ) ||
										Boolean(
											address.defaultReturnAddress
										) ||
										addresses.length === 1 ) && (
										<div className="origin-address-list-item__action-tooltip">
											<ControlledPopover
												icon={ help }
												trigger="hover"
											>
												<span className="tooltip-content">
													{ __(
														'Default addresses cannot be deleted.',
														'woocommerce-shipping'
													) }
												</span>
											</ControlledPopover>
										</div>
									) }
									{ ! address.defaultAddress &&
										! address.defaultReturnAddress &&
										addresses.length !== 1 && (
											<div className="origin-address-list-item__action-spacer">
												&nbsp;
											</div>
										) }
								</HStack>
							</HStack>
						</CardBody>
						{ index !== addresses.length - 1 ? (
							<CardDivider />
						) : null }
					</VStack>
				) ) }
				<CardFooter>
					<Button
						variant="secondary"
						onClick={ openNewOriginAddressForm }
					>
						{ __( 'Add new address', 'woocommerce-shipping' ) }
					</Button>
				</CardFooter>
				{ isOriginAddressFormOpen && selectedOriginAddress && (
					<Modal
						className="edit-address-modal"
						onRequestClose={ closeOriginAddressForm }
						focusOnMount
						shouldCloseOnClickOutside={ false }
						title={
							Boolean( selectedOriginAddress?.id )
								? sprintf(
										// translators: %s: origin or destination
										__(
											'Edit %s address',
											'woocommerce-shipping'
										),
										isOriginAddress( selectedOriginAddress )
											? __(
													'origin',
													'woocommerce-shipping'
											  )
											: __(
													'destination',
													'woocommerce-shipping'
											  )
								  )
								: __(
										'Add new origin address',
										'woocommerce-shipping'
								  )
						}
					>
						<AddressStep
							type={ 'origin' }
							address={ camelCaseKeys(
								formatAddressFields(
									snakeCaseKeys( selectedOriginAddress )
								)
							) }
							onCompleteCallback={ closeOriginAddressForm }
							onCancelCallback={ closeOriginAddressForm }
							isAdd={ ! Boolean( selectedOriginAddress?.id ) }
						/>
					</Modal>
				) }
			</Card>

			<ConfirmDialog
				isOpen={ isOriginAddressDestroyConfirmationOpen }
				onConfirm={ () => {
					destroyOriginAddress();
				} }
				onCancel={ closeOriginAddressDestroyConfirmation }
				__experimentalHideHeader={ false }
				title={ __( 'Delete address?', 'woocommerce-shipping' ) }
				confirmButtonText={ __( 'Delete', 'woocommerce-shipping' ) }
				className="origin-address__delete_confirmation"
			>
				<Flex
					direction="column"
					align="flex-start"
					justify="space-around"
					gap="2rem"
				>
					<AddressColumn address={ selectedOriginAddress } />
					{ deletionError && (
						<Notice status="error" isDismissible={ false }>
							{ deletionError }
						</Notice>
					) }
				</Flex>
			</ConfirmDialog>
		</Flex>
	);
};
