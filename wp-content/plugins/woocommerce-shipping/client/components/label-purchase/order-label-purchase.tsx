import React from 'react';
import { useEffect, useRef, useState } from '@wordpress/element';
import { Flex, Modal } from '@wordpress/components';
import { deleteUrlParam, urlParamHasValue } from 'utils';
import { ModalHeader } from './order-label-purchase-modal';
import { LabelPurchaseContextProvider } from 'context/label-purchase';
import { SplitShipmentModal } from './split-shipment';
import { LabelPurchaseTabs } from './label-purchase-tabs';
import { LabelPurchaseMetaBox } from './label-purchase-meta-box';
import { LabelPurchaseEffects } from 'effects/label-purchase';
import { PromoBanner, PromoNotice } from './promo/';

interface OrderLabelPurchaseProps {
	orderId: number;
	openModal?: boolean;
}

export const OrderLabelPurchase = ( {
	orderId,
	openModal,
}: OrderLabelPurchaseProps ) => {
	const [ isOpen, setIsOpen ] = useState( openModal );
	const [ startSplitShipment, setStartSplitShipment ] = useState( false );
	const ref = useRef( null );

	const labelsModalPersistKey = 'labels-modal';
	const labelsModalPersistValue = 'open';

	const closeLabelsModal = () => {
		setIsOpen( false );

		deleteUrlParam( labelsModalPersistKey );
	};

	useEffect( () => {
		// Maybe persist the modal on page refresh.
		if (
			urlParamHasValue( labelsModalPersistKey, labelsModalPersistValue )
		) {
			setIsOpen( true );
		}
	}, [] );

	return (
		<LabelPurchaseContextProvider orderId={ orderId }>
			<LabelPurchaseEffects />
			<Flex wrap className="wcshipping-shipping-label-meta-box">
				<LabelPurchaseMetaBox setIsOpen={ setIsOpen } />
				{ isOpen && (
					<Modal
						overlayClassName="label-purchase-overlay"
						className="label-purchase-modal"
						onRequestClose={ closeLabelsModal }
						focusOnMount
						shouldCloseOnClickOutside={ false }
						shouldCloseOnEsc={ false }
						__experimentalHideHeader={ true }
						isDismissible={ false }
					>
						<PromoBanner />
						<ModalHeader
							closeModal={ closeLabelsModal }
							orderId={ orderId }
						/>
						<LabelPurchaseTabs
							ref={ ref }
							setStartSplitShipment={ setStartSplitShipment }
						/>
						{ startSplitShipment && (
							<SplitShipmentModal
								ref={ ref }
								setStartSplitShipment={ setStartSplitShipment }
							/>
						) }
					</Modal>
				) }
			</Flex>
			<PromoNotice setIsOpen={ setIsOpen } />
		</LabelPurchaseContextProvider>
	);
};
