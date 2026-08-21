import {
	labelPurchaseStore,
	registerLabelPurchaseStore,
} from '../../data/label-purchase';
import { addressStore, registerAddressStore } from '../../data/address';
import { getConfig } from './../../utils';
import { memo, useCallback, useMemo, useRef } from 'react';
import { LabelPurchaseContextProvider } from '../../context/label-purchase';
import { LabelPurchaseEffects } from '../../effects/label-purchase';
import { LabelPurchaseTabs } from '../../components/label-purchase/label-purchase-tabs';
import { ShipmentContentV2 } from '../../components/label-purchase/design-next/shipment-content-v2';
import { dispatch } from '@wordpress/data';
import { AddressStep } from '../../components/address-step/address_step';
import { ADDRESS_TYPES } from '../../data/constants';
import type { Destination } from '../../types';
import { withBoundaryNext } from 'components/HOC';

interface AddressEditorPluginExportProps {
	address: Destination & { id: string };
	orderId?: string;
	originCountry: string;
	onAddressComplete: ( updatedAddress: unknown ) => void;
	onAddressCancel: () => void;
}

const AddressEditorPluginExport = memo(
	( {
		address,
		orderId,
		originCountry,
		onAddressComplete,
		onAddressCancel,
	}: AddressEditorPluginExportProps ) => {
		return (
			<AddressStep
				type={ ADDRESS_TYPES.DESTINATION }
				address={ address }
				onCompleteCallback={ onAddressComplete }
				onCancelCallback={ onAddressCancel }
				orderId={ orderId }
				isAdd={ false }
				originCountry={ originCountry }
				nextDesign={ true }
			/>
		);
	}
);

const PurchaseShippingLabelPluginExport = memo(
	( { orderId }: { orderId: number } ) => {
		const noop = () => {
			/* no operation */
		};
		const ref = useRef( null );

		// We need to reset the state when the order ID changes to avoid
		// showing data from a previous order.
		dispatch( addressStore ).stateReset();
		dispatch( labelPurchaseStore ).stateReset();

		return (
			<LabelPurchaseContextProvider orderId={ orderId } nextDesign>
				<LabelPurchaseEffects />
				<LabelPurchaseTabs
					ref={ ref }
					setStartSplitShipment={ noop }
					ContentComponent={ ShipmentContentV2 }
				/>
			</LabelPurchaseContextProvider>
		);
	},
	( prevProps, nextProps ) => prevProps.orderId === nextProps.orderId
);

const WCShippingPlugin = memo( () => {
	if ( ! addressStore ) {
		try {
			registerAddressStore( true );
		} catch {
			// Store is already registered
		}
	}
	if ( ! labelPurchaseStore ) {
		try {
			registerLabelPurchaseStore();
		} catch {
			// Store is already registered
		}
	}
	const config = getConfig();
	const mode = config.mode ?? 'label-purchase';

	// Memoize address transformation
	const addressEditorProps = useMemo( () => {
		if ( mode !== 'address-editor' ) {
			return null;
		}

		const shippingAddress = config.order?.shipping_address;
		const address = shippingAddress
			? {
					id: '',
					firstName: shippingAddress.first_name,
					lastName: shippingAddress.last_name,
					company: shippingAddress.company,
					address: shippingAddress.address_1,
					address1: shippingAddress.address_1,
					address2: shippingAddress.address_2,
					city: shippingAddress.city,
					state: shippingAddress.state,
					postcode: shippingAddress.postcode,
					country: shippingAddress.country,
					email: shippingAddress.email,
					phone: shippingAddress.phone,
			  }
			: ( {
					id: '',
					...( config.address as Destination ),
			  } as Destination & {
					id: string;
			  } );

		return {
			address,
			orderId: config.order?.id ? String( config.order.id ) : undefined,
			originCountry:
				config.accountSettings?.storeOptions?.origin_country ?? 'US',
		};
	}, [
		mode,
		config.order?.shipping_address,
		config.order?.id,
		config.address,
		config.accountSettings?.storeOptions?.origin_country,
	] );

	// Stable callbacks
	const onAddressComplete = useCallback( ( updatedAddress: unknown ) => {
		const currentConfig = getConfig();
		if ( currentConfig.onAddressComplete ) {
			currentConfig.onAddressComplete( updatedAddress );
		}
	}, [] );

	const onAddressCancel = useCallback( () => {
		const currentConfig = getConfig();
		if ( currentConfig.onAddressCancel ) {
			currentConfig.onAddressCancel();
		}
	}, [] );

	if ( mode === 'address-editor' && addressEditorProps ) {
		return (
			<AddressEditorPluginExport
				{ ...addressEditorProps }
				onAddressComplete={ onAddressComplete }
				onAddressCancel={ onAddressCancel }
			/>
		);
	}

	// Default to label-purchase mode for backward compatibility
	const orderId = config.order.id;
	return <PurchaseShippingLabelPluginExport orderId={ orderId } />;
} );

window.WCShipping_Plugin = withBoundaryNext( WCShippingPlugin )();

export default WCShippingPlugin;
