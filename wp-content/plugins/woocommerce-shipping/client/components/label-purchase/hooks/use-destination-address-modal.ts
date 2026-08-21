import { useEffect, useRef } from '@wordpress/element';
import {
	DESTINATION_ADDRESS_MODAL_FOCUS_FIELD,
	type DestinationAddressModalFocusField,
	focusDestinationPhoneField,
	OPEN_DESTINATION_ADDRESS_MODAL_EVENT,
} from '../constants';

type OpenDestinationAddressModalEvent = CustomEvent< {
	focusField?: DestinationAddressModalFocusField;
} >;

export const useDestinationAddressModal = (
	isDestinationModalOpen: boolean,
	setIsDestinationModalOpen: ( isOpen: boolean ) => void
) => {
	const shouldFocusDestinationPhoneRef = useRef( false );

	useEffect( () => {
		const handleOpenDestinationAddressModal = ( event: Event ) => {
			const modalEvent = event as OpenDestinationAddressModalEvent;

			shouldFocusDestinationPhoneRef.current =
				modalEvent.detail?.focusField ===
				DESTINATION_ADDRESS_MODAL_FOCUS_FIELD.PHONE;
			setIsDestinationModalOpen( true );
		};

		window.addEventListener(
			OPEN_DESTINATION_ADDRESS_MODAL_EVENT,
			handleOpenDestinationAddressModal
		);

		return () => {
			window.removeEventListener(
				OPEN_DESTINATION_ADDRESS_MODAL_EVENT,
				handleOpenDestinationAddressModal
			);
		};
	}, [ setIsDestinationModalOpen ] );

	useEffect( () => {
		if (
			! isDestinationModalOpen ||
			! shouldFocusDestinationPhoneRef.current
		) {
			return;
		}

		shouldFocusDestinationPhoneRef.current = false;
		window.requestAnimationFrame( focusDestinationPhoneField );
	}, [ isDestinationModalOpen ] );
};
