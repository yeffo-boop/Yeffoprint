import { getPromotion } from 'utils';
import { Badge } from 'components/wp';
import './style.scss';

interface Props {
	carrier?: string;
}

export const PromoBadge = ( { carrier }: Props ) => {
	const promo = getPromotion();

	if ( ! carrier || ! promo?.badge || promo.carrier !== carrier ) {
		return null;
	}

	return (
		<Badge className="promo-badge" intent="info">
			{ promo.badge }
		</Badge>
	);
};
