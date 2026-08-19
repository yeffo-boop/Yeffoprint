import apiFetch from '@wordpress/api-fetch';
import { CamelCaseType, ResponseLabel } from 'types';
import { getPromotion } from './config';
import { NAMESPACE } from 'data/constants';

export const getPromoDiscount = ( rate: number, promoId?: string ) => {
	const promo = getPromotion();

	if ( ! promoId || promo?.id !== promoId ) {
		return;
	}

	if ( promo.discountType === 'percentage' ) {
		return ( rate * promo.discountAmount ) / 100;
	}

	return Math.min( rate, promo.discountAmount );
};

export const applyPromo = ( rate: number, promoId?: string ) => {
	const discount = getPromoDiscount( rate, promoId );

	if ( ! discount ) {
		return rate;
	}

	return rate - discount;
};

export const maybeDecrementPromoRemaining = (
	label: CamelCaseType< ResponseLabel >
) => {
	if ( ! label.promoId ) {
		return;
	}

	const promo = getPromotion();
	if ( ! promo || promo.remaining <= 0 ) {
		return;
	}

	--promo.remaining;
};

export const dismissPromo = ( type: 'notice' | 'banner', id: string ) =>
	apiFetch( {
		path: `${ NAMESPACE }/promo/${ type }/${ id }`,
		method: 'DELETE',
	} );
