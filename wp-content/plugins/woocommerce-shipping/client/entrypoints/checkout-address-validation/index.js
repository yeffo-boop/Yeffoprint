// WordPress dependencies.
import { registerPlugin } from '@wordpress/plugins';

// WooCommerce dependencies.
import { ExperimentalOrderShippingPackages } from '@woocommerce/blocks-checkout';

// Internal dependencies.
import { AddressValidationNotices } from 'components/checkout';
import { initSentry } from 'utils/sentry';

initSentry();

/*
 * `ExperimentalOrderShippingPackages` is a `Slot` component in WooCommerce.
 *
 * @see https://github.com/woocommerce/woocommerce/blob/a7231863c014a95602f5932f702171465fa7bcf2/docs/cart-and-checkout-blocks/available-slot-fills.md?plain=1#L53
 */
const render = () => {
	return (
		<ExperimentalOrderShippingPackages>
			<AddressValidationNotices />
		</ExperimentalOrderShippingPackages>
	);
};

registerPlugin( 'woocommerce-shipping-checkout-address-validation', {
	render,
	scope: 'woocommerce-checkout',
} );
