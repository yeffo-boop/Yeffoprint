import { OriginAddress } from 'types';
import React from 'react';
import {
	__experimentalHStack as HStack,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export const AddressColumn = ( {
	address,
}: {
	address: OriginAddress | undefined;
} ): React.JSX.Element | null => {
	if ( ! address ) {
		return null;
	}

	return (
		<VStack className="origin-address-list-item__container" spacing="1">
			<HStack justify="flex-start">
				<Text weight="bold">{ address.name }</Text>
				{ address.defaultAddress && (
					<Text variant="muted">
						{ __( 'Default sender', 'woocommerce-shipping' ) }
					</Text>
				) }
				{ address.defaultReturnAddress && (
					<Text variant="muted">
						{ __( 'Default return', 'woocommerce-shipping' ) }
					</Text>
				) }
			</HStack>

			{ address.company && <Text>{ address.company }</Text> }
			<Text>{ address.address }</Text>
			<Text>
				{ address.city } { address.state }{ ' ' }
				{ address.postcode && address.postcode }
			</Text>
			<Text>{ address.country }</Text>
		</VStack>
	);
};
