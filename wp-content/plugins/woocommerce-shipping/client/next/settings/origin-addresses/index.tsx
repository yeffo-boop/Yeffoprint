/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	store as coreStore,
	privateApis as coreDataPrivateApis,
} from '@wordpress/core-data';
import { store as noticesStore } from '@wordpress/notices';
import { pencil, plus } from '@wordpress/icons';
import {
	__experimentalHeading as Heading,
	Card,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	CardBody,
	CardHeader,
	__experimentalText as Text,
	CardFooter,
	Button,
} from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews/wp';

/**
 * Internal dependencies
 */
import { getItemId, getAddressDetail, getAddressTitle } from './utils';
import { useConfigLoader, unlock } from 'next/utils';
import { ADDRESS_ENTITY } from 'next/data';
import { OriginAddressModal } from './origin-address-modal';
import { StoreAddressSyncNotice } from 'components/store-address-sync-notice';
import { Badge } from 'components/wp';
import { addressStore } from 'data/address';
import { recordEvent } from 'utils/tracks';
import type { OriginAddress } from 'types';

import './style.scss';

const { useEntityRecordsWithPermissions } = unlock( coreDataPrivateApis );

export const OriginAddresses = () => {
	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ selectedAddressId, setSelectedAddressId ] = useState< string >();
	const [ draftAddress, setDraftAddress ] = useState< OriginAddress >();
	const [ clearStoreAddressDriftOnSave, setClearStoreAddressDriftOnSave ] =
		useState( false );

	// Ensure config and address store are loaded when this screen mounts
	// (e.g. when navigating from "Buy a shipping label" without the filter having run).
	useConfigLoader();

	// @ts-ignore invalidateResolution
	const { deleteEntityRecord, invalidateResolution } =
		useDispatch( coreStore );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );
	const { refreshStoreAddressSyncFromApi } = useDispatch( addressStore );

	useEffect( () => {
		void refreshStoreAddressSyncFromApi();
		// Mount only: bound action is stable; avoids duplicate dispatches when the
		// reference identity changes. Overlapping sync-meta HTTP is still deduped in the store.
		// eslint-disable-next-line react-hooks/exhaustive-deps -- see comment
	}, [] );
	const { records: addresses, isResolving } = useEntityRecordsWithPermissions(
		ADDRESS_ENTITY.kind,
		ADDRESS_ENTITY.name
	);

	const { canUser } = useSelect( coreStore, [] );

	const canCreateRecord = canUser( 'create', {
		kind: ADDRESS_ENTITY.kind,
		name: ADDRESS_ENTITY.name,
	} );

	const handleEdit = ( id: string ) => {
		recordEvent( 'settings_shipping_button_click', {
			button_label: 'edit',
			card_section: 'sender_addresses',
		} );
		setDraftAddress( undefined );
		setClearStoreAddressDriftOnSave( false );
		setSelectedAddressId( id );
		setIsModalOpen( true );
	};

	const handleAdd = () => {
		recordEvent( 'settings_shipping_button_click', {
			button_label: 'add_sender_address',
			card_section: 'sender_addresses',
		} );
		setDraftAddress( undefined );
		setClearStoreAddressDriftOnSave( false );
		setIsModalOpen( true );
	};

	const handleModalClose = async () => {
		setIsModalOpen( false );
		setSelectedAddressId( undefined );
		setDraftAddress( undefined );
		setClearStoreAddressDriftOnSave( false );
	};

	const handleModalComplete = async () => {
		await handleModalClose();

		if ( selectedAddressId ) {
			createSuccessNotice(
				__( 'Address updated', 'woocommerce-shipping' ),
				{ type: 'snackbar' }
			);
		} else {
			createSuccessNotice(
				__( 'Address added', 'woocommerce-shipping' ),
				{ type: 'snackbar' }
			);
		}

		invalidateResolution( 'getEntityRecords', [
			ADDRESS_ENTITY.kind,
			ADDRESS_ENTITY.name,
			{},
		] );
		void refreshStoreAddressSyncFromApi();
	};

	const handleDelete = async ( id: string ) => {
		recordEvent( 'settings_shipping_button_click', {
			button_label: 'delete',
			card_section: 'sender_addresses',
		} );
		try {
			await deleteEntityRecord(
				ADDRESS_ENTITY.kind,
				ADDRESS_ENTITY.name,
				id,
				{
					throwOnError: true,
				}
			);
			createSuccessNotice(
				__( 'Address deleted', 'woocommerce-shipping' ),
				{ type: 'snackbar' }
			);
		} catch ( error ) {
			const errorCode =
				error instanceof Error
					? error.message
					: ( error as Record< string, unknown > )?.code ??
					  'unknown_error';
			recordEvent( 'settings_shipping_button_error', {
				button_label: 'delete',
				error_code: String( errorCode ),
				card_section: 'sender_addresses',
			} );
			createErrorNotice(
				__( 'Error deleting address', 'woocommerce-shipping' ),
				{ type: 'snackbar' }
			);
		}
	};

	return (
		<Card>
			{ isModalOpen && (
				<OriginAddressModal
					onClose={ handleModalClose }
					onComplete={ handleModalComplete }
					addressId={ selectedAddressId }
					initialAddress={ draftAddress }
					clearStoreAddressDriftOnSave={
						clearStoreAddressDriftOnSave
					}
				/>
			) }
			<CardHeader isBorderless>
				<VStack>
					<Heading level={ 4 } weight={ 'normal' }>
						{ __( 'Sender addresses', 'woocommerce-shipping' ) }
					</Heading>
					<StoreAddressSyncNotice
						onEditSenderAddress={ ( address ) => {
							setSelectedAddressId( address.id );
							setDraftAddress( address );
							setClearStoreAddressDriftOnSave( true );
							setIsModalOpen( true );
						} }
					/>
					<Text variant="muted">
						{ __(
							'Add the addresses of any locations you ship from. The sender address you select will be used to calculate shipping rates and delivery times.',
							'woocommerce-shipping'
						) }
					</Text>
				</VStack>
			</CardHeader>
			<CardBody>
				<DataViews
					isLoading={ isResolving }
					data={ addresses }
					getItemId={ getItemId }
					defaultLayouts={ { list: {} } }
					fields={ [
						{
							id: 'title',
							getValue: ( args ) => (
								<HStack spacing={ 2 } alignment="left">
									<Text weight={ 500 }>
										{ getAddressTitle( args ) }
									</Text>
									{ args.item.default_address && (
										<Badge>
											{ __(
												'Default sender',
												'woocommerce-shipping'
											) }
										</Badge>
									) }
									{ args.item.default_return_address && (
										<Badge>
											{ __(
												'Default return',
												'woocommerce-shipping'
											) }
										</Badge>
									) }
								</HStack>
							),
						},
						{
							id: 'detail',
							getValue: getAddressDetail,
						},
					] }
					onChangeView={ () => null }
					paginationInfo={ {
						totalItems: addresses.length,
						totalPages: 1,
					} }
					actions={ [
						{
							id: 'edit',
							isPrimary: true,
							icon: pencil,
							label: __( 'Edit', 'woocommerce-shipping' ),
							isEligible: () => !! canCreateRecord,
							callback: ( [ item ] ) => handleEdit( item.id ),
						},
						{
							id: 'delete',
							label: __( 'Delete', 'woocommerce-shipping' ),
							isEligible: ( item ) =>
								! item.default_address && !! canCreateRecord,
							callback: ( [ item ] ) => handleDelete( item.id ),
						},
					] }
					onChangeSelection={ ( items ) => {
						handleEdit( items[ 0 ] );
					} }
					view={ {
						fields: [ 'detail' ],
						type: 'list',
						titleField: 'title',
					} }
				>
					<DataViews.Layout />
				</DataViews>
				<CardFooter isBorderless size="small">
					<Button
						variant="tertiary"
						icon={ plus }
						disabled={ ! canCreateRecord }
						onClick={ handleAdd }
					>
						{ __( 'Add sender address', 'woocommerce-shipping' ) }
					</Button>
				</CardFooter>
			</CardBody>
		</Card>
	);
};
