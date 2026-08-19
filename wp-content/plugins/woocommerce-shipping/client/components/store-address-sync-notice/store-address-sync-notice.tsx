/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	useState,
	useEffect,
	createInterpolateElement,
} from '@wordpress/element';
import { Notice } from '@wordpress/ui';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { addressStore } from 'data/address';
import { STORAGE_KEY } from './constants';
import { OriginAddress } from 'types';

interface StoreAddressSyncNoticeProps {
	onEditSenderAddress?: ( address: OriginAddress ) => void;
}

export const StoreAddressSyncNotice = ( {
	onEditSenderAddress,
}: StoreAddressSyncNoticeProps ) => {
	const [ isSessionDismissed, setIsSessionDismissed ] = useState( false );
	// Read once on mount; updated explicitly on "Keep current address" and
	// when the notice becomes in-sync. Reading during render would not be
	// reactive to writes from the same component.
	const [ persistentDismissedAddress, setPersistentDismissedAddress ] =
		useState< string | null >( () => localStorage.getItem( STORAGE_KEY ) );
	const { createErrorNotice } = useDispatch( noticesStore );

	const { inSync, storeAddress, storeAddressDraft } = useSelect(
		( select ) => ( {
			inSync: select( addressStore ).getIsMainOriginInSyncWithStore(),
			storeAddress: select( addressStore ).getFormattedStoreAddress(),
			storeAddressDraft: select( addressStore ).getStoreAddressDraft(),
		} ),
		[]
	);

	/**
	 * Clear the persistent dismissal when addresses come back into sync,
	 * so the banner can reappear if the store address changes again later.
	 */
	useEffect( () => {
		if ( inSync ) {
			localStorage.removeItem( STORAGE_KEY );
			setPersistentDismissedAddress( null );
		}
	}, [ inSync ] );

	if (
		inSync ||
		isSessionDismissed ||
		persistentDismissedAddress === storeAddress
	) {
		return null;
	}

	const handleSync = () => {
		if ( ! storeAddressDraft ) {
			createErrorNotice(
				__(
					'Failed to open sender address editor',
					'woocommerce-shipping'
				),
				{ type: 'snackbar' }
			);
			return;
		}

		onEditSenderAddress?.( storeAddressDraft );
	};

	const handleKeepCurrent = () => {
		localStorage.setItem( STORAGE_KEY, storeAddress );
		setPersistentDismissedAddress( storeAddress );
		setIsSessionDismissed( true );
	};

	return (
		<div style={ { marginTop: 8 } }>
			<Notice.Root intent="warning">
				<Notice.Description>
					{ createInterpolateElement(
						__(
							'Your store address has been updated to <strong />. Your sender address is different. You can update it now.',
							'woocommerce-shipping'
						),
						{
							strong: <strong>{ storeAddress }</strong>,
						}
					) }
				</Notice.Description>
				<Notice.Actions>
					<Notice.ActionButton
						onClick={ handleSync }
						disabled={ ! storeAddressDraft }
					>
						{ __(
							'Update sender address',
							'woocommerce-shipping'
						) }
					</Notice.ActionButton>
					<Notice.ActionButton
						onClick={ handleKeepCurrent }
						variant="outline"
					>
						{ __( 'Keep current address', 'woocommerce-shipping' ) }
					</Notice.ActionButton>
				</Notice.Actions>
				<Notice.CloseIcon
					onClick={ () => setIsSessionDismissed( true ) }
				/>
			</Notice.Root>
		</div>
	);
};
