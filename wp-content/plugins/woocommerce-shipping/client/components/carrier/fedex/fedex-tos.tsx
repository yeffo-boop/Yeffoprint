import { createInterpolateElement, useState } from '@wordpress/element';
import {
	__experimentalSpacer as Spacer,
	Button,
	CheckboxControl,
	Flex,
	Modal,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { recordEvent } from 'utils';
import { LabelPurchaseError } from 'types';
import { uniq } from 'lodash';

interface FedExTosProps {
	close: () => void;
	confirm: () => void;
	error?: LabelPurchaseError | null;
	isConfirming?: boolean;
	setIsConfirming?: ( isConfirming: boolean ) => void;
}

export const FedExTos = ( {
	close,
	confirm,
	error,
	isConfirming = false,
	setIsConfirming = () => undefined,
}: FedExTosProps ) => {
	const [ accepted, setAccepted ] = useState( false );

	const onConfirm = async () => {
		setIsConfirming( true );
		recordEvent( 'label_purchase_fedex_tos_confirmed' );
		confirm();
	};

	const onClose = () => {
		recordEvent( 'label_purchase_fedex_tos_closed' );
		close();
	};

	return (
		<Modal
			overlayClassName="wcshipping-fedex-tos-overlay"
			className="wcshipping-fedex-tos-modal"
			onRequestClose={ onClose }
			focusOnMount
			shouldCloseOnClickOutside={ false }
			shouldCloseOnEsc={ false }
			size="medium"
			contentLabel={ __(
				'FedEx Terms of Service',
				'woocommerce-shipping'
			) }
			title={ __( 'FedEx Terms of Service', 'woocommerce-shipping' ) }
		>
			<Flex direction="column" gap={ 4 } as="section">
				<p>
					{ __(
						'To purchase FedEx shipping labels, you need to agree to the following terms:',
						'woocommerce-shipping'
					) }
				</p>
				<CheckboxControl
					// @ts-ignore
					label={ createInterpolateElement(
						__(
							'I agree to the <tos>Terms of Service</tos> (including the <shipping>terms on shipping services</shipping>).',
							'woocommerce-shipping'
						),
						{
							tos: (
								<a
									href="https://wordpress.com/tos/"
									target="_blank"
									rel="noreferrer"
								>
									{ __(
										'Terms of Service',
										'woocommerce-shipping'
									) }
								</a>
							),
							shipping: (
								<a
									href="https://wordpress.com/tos/#:~:text=More%20on%20Shipping%20Services%20Specifically"
									target="_blank"
									rel="noreferrer"
								>
									{ __(
										'terms on shipping services',
										'woocommerce-shipping'
									) }
								</a>
							),
						}
					) }
					checked={ accepted }
					onChange={ setAccepted }
					__nextHasNoMarginBottom={ true }
				/>
			</Flex>
			<Spacer marginTop={ 6 } marginBottom={ 0 } />
			<Flex justify="flex-end">
				<Button
					variant="primary"
					disabled={ ! accepted || isConfirming }
					isBusy={ isConfirming }
					onClick={ onConfirm }
					className="fedex-confirm-button"
				>
					{ __( 'Confirm and continue', 'woocommerce-shipping' ) }
				</Button>
			</Flex>
			{ error && (
				<>
					<Spacer marginTop={ 4 } marginBottom={ 0 } />
					<Notice status="error" isDismissible={ false }>
						{ uniq( error.message ).map( ( m, index ) => (
							<p key={ index }>{ m }</p>
						) ) }
					</Notice>
				</>
			) }
		</Modal>
	);
};
