import React from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ConfirmModal } from 'components/confirm-modal';
import { CustomPackage, Package } from 'types';

interface ConfirmPackageDeletionProps {
	pkg: Package | CustomPackage;
	close: () => void;
	onDelete: ( deletable: Package | CustomPackage ) => void;
	isBusy: boolean;
}

export const ConfirmPackageDeletion = ( {
	pkg,
	close,
	onDelete,
	isBusy,
}: ConfirmPackageDeletionProps ) => {
	const deletePackage = () => {
		onDelete( pkg );
	};

	return (
		<ConfirmModal
			title={
				pkg.isUserDefined
					? sprintf(
							// translators: %s: package name
							__( 'Delete %s', 'woocommerce-shipping' ),
							pkg.name
					  )
					: sprintf(
							// translators: %s: package name
							__( 'Remove %s', 'woocommerce-shipping' ),
							pkg.name
					  )
			}
			onClose={ close }
			acceptButton={ {
				text: __( 'Delete', 'woocommerce-shipping' ),
				onClick: deletePackage,
				isBusy,
				disabled: isBusy,
				'aria-disabled': isBusy,
			} }
			cancelButton={ {
				text: __( 'Cancel', 'woocommerce-shipping' ),
				onClick: close,
				disabled: isBusy,
			} }
			modalProps={ { className: 'wcshipping-delete-confirmation' } }
		>
			{ pkg.isUserDefined
				? __(
						'Are you sure you want to remove this custom package?. You can create a new one at any time, if necessary.',
						'woocommerce-shipping'
				  )
				: __(
						'This will remove the carrier package from your saved templates. You can star packages from the Carrier Package tab to add them to this list again.',
						'woocommerce-shipping'
				  ) }
		</ConfirmModal>
	);
};
