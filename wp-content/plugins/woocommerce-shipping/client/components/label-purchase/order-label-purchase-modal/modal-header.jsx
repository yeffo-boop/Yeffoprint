import { Button, Flex } from '@wordpress/components';
import { arrowLeft } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';

export const ModalHeader = ( { closeModal, orderId } ) => (
	<Flex className="label-purchase-modal__header">
		<Button
			icon={ arrowLeft }
			onClick={ closeModal }
			aria-label={ __(
				'Close purchase label modal',
				'woocommerce-shipping'
			) }
			title={ __( 'Close', 'woocommerce-shipping' ) }
		/>
		<h3>{ __( 'Create shipping label', 'woocommerce-shipping' ) }</h3>
		<span>
			{ sprintf(
				/* translators: %d: order ID */
				__( 'Order %d', 'woocommerce-shipping' ),
				orderId
			) }
		</span>
	</Flex>
);
