/**
 * WordPress dependencies
 */
import { __experimentalGrid as Grid } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { OriginAddresses } from './origin-addresses';

export const WooCommerceShippingSettings = () => {
	return (
		<Grid columns={ 1 } gap={ 8 } justify="center" templateColumns="660px">
			<OriginAddresses />
		</Grid>
	);
};
