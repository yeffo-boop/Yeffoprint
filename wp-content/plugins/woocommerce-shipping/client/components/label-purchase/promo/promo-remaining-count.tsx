import { getPromotion } from 'utils';
import { Badge } from 'components/wp';
import { __, sprintf } from '@wordpress/i18n';
import { CARRIER_ID_TO_NAME } from '../packages';
import './style.scss';

const sevenDaysFromNow = new Date().setDate( new Date().getDate() + 7 );

export const PromoRemainingCount = () => {
	const promo = getPromotion();

	if ( ! promo ) {
		return null;
	}

	if ( new Date( promo.endDate ).getTime() < sevenDaysFromNow ) {
		return (
			<Badge intent="warning" className="promo-remaining-count">
				{ sprintf(
					// translators: %d: remaining count
					__( 'Promo ending soon · %d left', 'woocommerce-shipping' ),
					promo.remaining
				) }
			</Badge>
		);
	}

	return (
		<Badge className="promo-remaining-count">
			{ sprintf(
				// translators: %1$s: carrier name, %2$d: remaining count
				__( '%1$s Promo: %2$d remaining', 'woocommerce-shipping' ),
				CARRIER_ID_TO_NAME[
					promo.carrier as keyof typeof CARRIER_ID_TO_NAME
				],
				promo.remaining
			) }
		</Badge>
	);
};
