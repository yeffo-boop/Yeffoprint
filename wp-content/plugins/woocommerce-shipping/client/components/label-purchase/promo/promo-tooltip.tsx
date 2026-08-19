import { RawHTML } from '@wordpress/element';
import { __experimentalTooltip as Tooltip } from '@woocommerce/components';
import { getPromotion } from 'utils';
import './style.scss';

interface Props {
	rate: number;
}

export const PromoTooltip = ( { rate }: Props ) => {
	const promo = getPromotion();

	if (
		! promo?.tooltip ||
		promo.discountType !== 'fixed' ||
		rate >= promo.discountAmount
	) {
		return null;
	}

	return (
		<Tooltip
			className="promo-tooltip"
			text={ <RawHTML>{ promo.tooltip }</RawHTML> }
		/>
	);
};
