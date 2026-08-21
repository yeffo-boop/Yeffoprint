import { TourKit } from '@woocommerce/components';
import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as preferencesStore } from '@wordpress/preferences';
import { dispatch, useSelect } from '@wordpress/data';
import type { WooConfig } from '@woocommerce/components/build-types/tour-kit/types';

interface ShippingDateSpotlightProps {
	referenceSelector: string; // The element to reference for the spotlight
	focusSelector: string; // The selector to focus on when the spotlight is open
}

const WCS_PREFERENCES_SCOPE = 'woocommerce-shipping';
const SHIPPING_DATE_SPOTLIGHT_DISMISSED_KEY =
	'shipping-date-spotlight-dismissed';

export const ShippingDateSpotlight = ( {
	referenceSelector,
	focusSelector,
}: ShippingDateSpotlightProps ) => {
	const isDismissed = useSelect(
		( select ) =>
			select( preferencesStore ).get(
				WCS_PREFERENCES_SCOPE,
				SHIPPING_DATE_SPOTLIGHT_DISMISSED_KEY
			),
		[]
	);

	const closeHandler = useCallback( () => {
		dispatch( preferencesStore ).set(
			WCS_PREFERENCES_SCOPE,
			SHIPPING_DATE_SPOTLIGHT_DISMISSED_KEY,
			true
		);
	}, [] );

	const spotlightConfig: WooConfig = {
		steps: [
			{
				referenceElements: {
					desktop: referenceSelector,
				},
				focusElement: {
					desktop: focusSelector,
					mobile: focusSelector,
				},
				meta: {
					name: 'shipping-date-spotlight',
					heading: __( 'Add ship date', 'woocommerce-shipping' ),
					descriptions: {
						desktop: __(
							'Set when carriers collect your packages and deliver to customers!',
							'woocommerce-shipping'
						),
						mobile: __(
							'Set when carriers collect your packages and deliver to customers!',
							'woocommerce-shipping'
						),
					},
					primaryButton: {
						isHidden: true,
					},
				},
			},
		],
		closeHandler,
		options: {
			classNames: 'shipping-date-spotlight',
			effects: {
				spotlight: {
					interactivity: {
						// Allow interactions with the reference element in case the overlay is not closable.
						// This is useful when the CSS rule that brings the spotlight in the view is not working.
						enabled: true,
					},
					styles: {
						height: '52px',
						boxSizing: 'border-box',
					},
				},
				overlay: false, // Disable overlay to allow interactions with the reference element.
				autoScroll: true, // Automatically scroll the page to the reference element, it makes the spotlight more accurate in our case.
			},
			popperModifiers: [
				{
					name: 'offset',
					options: {
						offset: [ 0, 16 ], // 16px gap between the control and the spotlight
					},
				},
			],
		},
		placement: 'left',
	};

	// If spotlight is dismissed, don't render anything
	if ( isDismissed ) {
		return null;
	}

	return <TourKit config={ spotlightConfig } />;
};
