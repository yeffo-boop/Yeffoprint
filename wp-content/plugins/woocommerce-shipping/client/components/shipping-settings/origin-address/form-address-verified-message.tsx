import React from 'react';
import {
	__experimentalHStack as HStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { check, Icon } from '@wordpress/icons';

export const AddressVerifiedMessage = () => (
	<HStack justify="flex-start">
		<Icon
			icon={ check }
			fill="#008A20"
			size={ 15 }
			className="origin-address-form__address-verified-icon"
		/>
		<Text className="origin-address-form__address-verified-text">
			{ __( 'Address verified', 'woocommerce-shipping' ) }
		</Text>
	</HStack>
);
