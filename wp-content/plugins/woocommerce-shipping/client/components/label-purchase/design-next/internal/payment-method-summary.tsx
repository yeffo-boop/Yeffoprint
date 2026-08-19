import {
	__experimentalText as Text,
	Flex,
	ExternalLink,
	Icon,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { payment } from '@wordpress/icons';
import { getChangePaymentMethodUrl } from 'components/shipping-settings/constants';

/**
 * Displays the selected payment method with a link to manage payment methods.
 * Shows: "Label will be charged to {CardType} •••• {LastDigits} · Manage payment method"
 */
export const PaymentMethodSummary = () => {
	const {
		account: { getNextPaymentMethod, getSubscriptionId },
	} = useLabelPurchaseContext();

	const nextPaymentMethod = getNextPaymentMethod();

	if ( ! nextPaymentMethod ) {
		return null;
	}

	const changePaymentMethodUrl = getChangePaymentMethodUrl(
		getSubscriptionId()
	);

	const cardType =
		nextPaymentMethod.card_type.charAt( 0 ).toUpperCase() +
		nextPaymentMethod.card_type.slice( 1 );
	const maskedDigits = `•••• ${ nextPaymentMethod.card_digits }`;

	return (
		<Flex direction="row" align="center" justify="center" gap={ 2 }>
			<Icon icon={ payment } size={ 24 } />
			<Text variant="muted">
				{ sprintf(
					/* translators: 1: Card type (e.g., Visa), 2: Masked card digits (e.g., •••• 4242) */
					__(
						'Label will be charged to %1$s %2$s',
						'woocommerce-shipping'
					),
					cardType,
					maskedDigits
				) }
				{ ' · ' }
				<ExternalLink
					href={ changePaymentMethodUrl }
					style={ { fontSize: 'inherit' } }
				>
					{ __( 'Manage payment method', 'woocommerce-shipping' ) }
				</ExternalLink>
			</Text>
		</Flex>
	);
};
