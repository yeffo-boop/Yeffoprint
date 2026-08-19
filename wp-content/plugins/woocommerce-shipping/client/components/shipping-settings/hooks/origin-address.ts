import { useCallback, useState } from '@wordpress/element';
import { OriginAddress } from 'types';

export function useOriginAddressState() {
	const [ selectedOriginAddress, setSelectedOriginAddress ] = useState<
		OriginAddress | undefined
	>( undefined );

	const [ isOriginAddressFormOpen, setOriginAddressFormOpen ] =
		useState( false );

	const [
		isOriginAddressDestroyConfirmationOpen,
		setOriginAddressDestroyConfirmationOpen,
	] = useState( false );

	const openOriginAddressForm = useCallback(
		( address: OriginAddress ) => {
			setSelectedOriginAddress( address );
			setOriginAddressFormOpen( true );
		},
		[ setSelectedOriginAddress, setOriginAddressFormOpen ]
	);

	const closeOriginAddressForm = useCallback( () => {
		setOriginAddressFormOpen( false );
	}, [ setOriginAddressFormOpen ] );

	const updateOriginAddress = useCallback(
		( modifiedAddress: OriginAddress ) => {
			setSelectedOriginAddress( ( prev ) => ( {
				...prev,
				...modifiedAddress,
			} ) );
		},
		[ setSelectedOriginAddress ]
	);

	const openOriginAddressDestroyConfirmation = useCallback(
		( address: OriginAddress ) => {
			setSelectedOriginAddress( address );
			setOriginAddressDestroyConfirmationOpen( true );
		},
		[ setSelectedOriginAddress, setOriginAddressDestroyConfirmationOpen ]
	);

	const closeOriginAddressDestroyConfirmation = useCallback( () => {
		setSelectedOriginAddress( undefined );
		setOriginAddressDestroyConfirmationOpen( false );
	}, [ setSelectedOriginAddress, setOriginAddressDestroyConfirmationOpen ] );

	return {
		openOriginAddressForm,
		closeOriginAddressForm,
		selectedOriginAddress,
		updateOriginAddress,
		isOriginAddressFormOpen,
		isOriginAddressDestroyConfirmationOpen,
		openOriginAddressDestroyConfirmation,
		closeOriginAddressDestroyConfirmation,
	};
}
