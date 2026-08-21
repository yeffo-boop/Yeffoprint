import { Notice } from '@wordpress/components';
import {
	RawHTML,
	useEffect,
	useLayoutEffect,
	useState,
} from '@wordpress/element';
import { dismissPromo, getPromotion, recordEvent } from 'utils';
import { CarrierIcon } from 'components/carrier-icon';
import './style.scss';

export const PromoBanner = () => {
	const [ dismissed, setDismissed ] = useState( false );
	const promo = getPromotion();

	useLayoutEffect( () => {
		const banner = document.querySelector( '.promo-banner' );
		if ( ! banner ) {
			return;
		}
		const observer = new ResizeObserver( () => {
			document.documentElement.style.setProperty(
				'--wcs-promo-banner-height',
				`${ banner.clientHeight }px`
			);
		} );
		observer.observe( banner );
		return () => {
			observer.disconnect();
			document.documentElement.style.setProperty(
				'--wcs-promo-banner-height',
				'0px'
			);
		};
	} );

	// Prevent recording the event more than once whether the component rerenders.
	useEffect( () => {
		if ( promo?.id ) {
			recordEvent( 'promo_banner_viewed', {
				promo_id: promo.id,
			} );
		}
	}, [ promo ] );

	if ( ! promo?.banner || dismissed ) {
		return null;
	}

	const handleDismiss = () => {
		dismissPromo( 'banner', promo.id );
		setDismissed( true );
		document.documentElement.style.setProperty(
			'--wcs-promo-banner-height',
			'0px'
		);
	};

	return (
		<Notice className="promo-banner" onRemove={ handleDismiss }>
			<CarrierIcon carrier={ promo.carrier } />
			<RawHTML>{ promo.banner }</RawHTML>
		</Notice>
	);
};
