import React from 'react';
import {
	__experimentalHStack as HStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

interface AddressInvalidMessageProps {
	apiGeneralError?: string;
}

export const AddressInvalidMessage = ( {
	apiGeneralError,
}: AddressInvalidMessageProps ) => (
	<HStack justify="flex-start">
		<Text className="origin-address-form__address-invalid-icon">!</Text>
		<Text className="origin-address-form__address-invalid-text">
			{ apiGeneralError ??
				__( 'Unvalidated address', 'woocommerce-shipping' ) }
		</Text>
	</HStack>
);
