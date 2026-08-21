import { Button, Flex, Modal } from '@wordpress/components';
import { noop } from 'lodash';
import React, { ReactNode } from 'react';
import { ModalProps } from '@wordpress/components/build-types/modal/types';
import './styles.scss';
import { ButtonProps } from '@wordpress/components/build-types/button/types';

interface ConfirmModalProps {
	title: string;
	cancelButton: ButtonProps;
	acceptButton: ButtonProps;
	onClose: ModalProps[ 'onRequestClose' ];
	children?: ReactNode | ReactNode[] | string;
	size?: ModalProps[ 'size' ];
	className?: string;
	hideFooter?: boolean;
	modalProps?: Partial< ModalProps >;
}

export const ConfirmModal = ( {
	title,
	cancelButton,
	acceptButton,
	onClose = noop,
	children = '',
	size = 'medium',
	hideFooter = false,
	modalProps,
}: ConfirmModalProps ) => {
	const props = {
		...modalProps,
		onRequestClose: onClose,
		size,
		title,
		focusOnMount: true,
		overlayClassName: 'wcs-confirm-modal-overlay',
	};

	return (
		<Modal { ...props }>
			<section>{ children }</section>
			{ ! hideFooter && (
				<>
					<Flex justify="flex-end" gap={ 3 } as="footer">
						<Button { ...cancelButton } variant="tertiary" />
						<Button { ...acceptButton } variant="primary" />
					</Flex>
				</>
			) }
		</Modal>
	);
};
