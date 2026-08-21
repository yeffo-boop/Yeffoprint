import { useEffect, useState } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { getPromotion, dismissPromo, recordEvent } from 'utils';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { CARRIER_ID_TO_NAME, TAB_NAMES } from '../packages';
import './style.scss';

interface Props {
	setIsOpen: ( isOpen: boolean ) => void;
}

export const PromoNotice = ( { setIsOpen }: Props ) => {
	const [ dismissed, setDismissed ] = useState( false );
	const promo = getPromotion();
	const {
		packages: { setCurrentPackageTab, setInitialCarrierTab },
	} = useLabelPurchaseContext();

	// Prevent recording the event more than once whether the component rerenders.
	useEffect( () => {
		if ( promo?.id ) {
			recordEvent( 'promo_notice_viewed', {
				promo_id: promo.id,
			} );
		}
	}, [ promo ] );

	if ( ! promo?.notice || dismissed ) {
		return null;
	}

	const handleShipWithCarrierClick = () => {
		setCurrentPackageTab( TAB_NAMES.CARRIER_PACKAGE );
		setInitialCarrierTab( promo.carrier );
		setIsOpen( true );

		recordEvent( 'promo_notice_ship_with_clicked', {
			promo_id: promo.id,
		} );
	};

	const handleDismiss = () => {
		dismissPromo( 'notice', promo.id );
		setDismissed( true );
	};

	return (
		<Notice
			className="promo-notice"
			onRemove={ handleDismiss }
			actions={ [
				{
					label: sprintf(
						// translators: %s: carrier name
						__( 'Ship with %s', 'woocommerce-shipping' ),
						CARRIER_ID_TO_NAME[
							promo.carrier as keyof typeof CARRIER_ID_TO_NAME
						]
					),
					onClick: handleShipWithCarrierClick,
					variant: 'secondary',
				},
			] }
			__unstableHTML
		>
			{ promo.notice }
		</Notice>
	);
};
