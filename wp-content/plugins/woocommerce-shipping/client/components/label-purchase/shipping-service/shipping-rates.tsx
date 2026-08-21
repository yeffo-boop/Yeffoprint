import React from 'react';
import {
	__experimentalHeading as Heading,
	__experimentalSpacer as Spacer,
	__experimentalText as Text,
	Flex,
	Notice,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import {
	useCallback,
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { usePrevious } from '@wordpress/compose';
import { intersection } from 'lodash';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { getCurrentOrder } from 'utils';
import { CarrierRates } from './carrier-rates';
import { RatesSorter } from './rates-sorter';
import { DELIVERY_PROPERTIES, SORT_BY } from './constants';
import {
	customerPaidBannerStyles,
	mainModalContentSelector,
} from '../constants';
import { SHIPPING_SERVICE_SECTION } from '../essential-details/constants';
import { Carrier, Rate } from 'types';
import clsx from 'clsx';
import { CarrierFilter } from './carrier-filter';
import { CARRIER_ID_TO_NAME } from '../packages';

interface ShippingRatesProps {
	isFetching: boolean;
	availableRates: Record< Carrier, Rate[] >;
	className?: string;
}

export const ShippingRates = ( {
	availableRates,
	isFetching,
	className,
}: ShippingRatesProps ) => {
	const previousFetchingState = usePrevious( isFetching );

	const wrapperRef = useRef< HTMLDivElement >();
	const [ sortingBy, setSortBy ] = useState( SORT_BY.CHEAPEST );

	const {
		shipment: { shipments },
		essentialDetails: { focusArea: essentialDetailsFocusArea },
		rates: { sortRates },
		storeCurrency,
		nextDesign,
	} = useLabelPurchaseContext();

	const currentOrder = getCurrentOrder();
	const customerPaidShipping = parseFloat(
		currentOrder?.total_shipping ?? '0'
	);

	const [ selectedCarriers, setSelectedCarriers ] = useState< Carrier[] >(
		Object.keys( availableRates ) as Carrier[]
	);

	const canSortByDelivery = useCallback( () => {
		let rates;
		if ( selectedCarriers.length === 0 ) {
			rates = Object.values( availableRates ).flat();
		} else {
			rates = selectedCarriers
				.map( ( carrier ) => availableRates[ carrier ] )
				.flat();
		}

		return (
			rates?.some(
				( rate ) =>
					rate &&
					intersection( Object.keys( rate ), DELIVERY_PROPERTIES )
						.length > 0
			) ?? false
		);
	}, [ selectedCarriers, availableRates ] );

	const carriers = ( Object.keys( availableRates ) as Carrier[] ).map(
		( carrierId ) => ( {
			id: carrierId,
			label: CARRIER_ID_TO_NAME[ carrierId ],
		} )
	);

	const onFilterToCarriers = ( carrierIds: Carrier[] ) => {
		setSelectedCarriers( carrierIds );
	};

	const getRates = () => {
		if ( selectedCarriers.length === 0 ) {
			return Object.values( availableRates ).flat();
		}
		return selectedCarriers
			.flatMap( ( carrier ) => availableRates[ carrier ] )
			.filter( Boolean );
	};
	/**
	 * Scroll to the top of the shipping rates section when the rates are fetched.
	 */
	useLayoutEffect( () => {
		if (
			isFetching ||
			! previousFetchingState ||
			! wrapperRef?.current?.offsetTop
		) {
			return;
		}

		document.querySelector( mainModalContentSelector )?.scrollTo( {
			left: 0,
			top: wrapperRef.current.offsetTop,
			behavior: 'smooth',
		} );
	}, [ wrapperRef, isFetching, previousFetchingState ] );

	useEffect( () => {
		if (
			essentialDetailsFocusArea === SHIPPING_SERVICE_SECTION &&
			document.querySelector( mainModalContentSelector )
		) {
			if ( ! wrapperRef.current ) {
				return;
			}
			document.querySelector( mainModalContentSelector )?.scrollTo( {
				left: 0, // We have to offset the height of the header, so it doesn't overlap our message.
				// If there's more than one shipment being created, then we also have to take the
				// "Shipment tabs" component into account.
				// @todo We could make this smarter by finding the height with JS, but the heights
				//       are a fixed size, so we're keeping it dumb for now for simplicity.
				top:
					wrapperRef.current.offsetTop -
					( Object.keys( shipments ).length > 1 ? 140 : 72 ),
				behavior: 'smooth',
			} );
		}
	}, [ essentialDetailsFocusArea, shipments ] );

	return (
		<Flex
			className={ clsx( 'shipping-rates', className ) }
			as="section"
			direction="column"
			ref={ wrapperRef }
		>
			{ ! nextDesign && (
				<>
					<Heading level={ 3 }>
						{ __( 'Shipping service', 'woocommerce-shipping' ) }
					</Heading>
					<Spacer marginBottom="6" />
				</>
			) }

			{ essentialDetailsFocusArea === SHIPPING_SERVICE_SECTION && (
				<Notice
					status="error"
					className="shipping-rates-notice"
					isDismissible={ false }
				>
					{ __(
						'Please select a shipping service before purchasing a shipping label.',
						'woocommerce-shipping'
					) }
				</Notice>
			) }
			<Flex
				align={ nextDesign ? 'stretch' : 'flex-start' }
				direction="column"
				gap={ 4 }
			>
				<Flex
					justify={ nextDesign ? 'flex-start' : 'space-between' }
					gap={ nextDesign ? 4 : 2 }
				>
					{ carriers.length > 1 ? (
						<CarrierFilter
							carriers={ carriers }
							selectedCarriers={ selectedCarriers }
							filterToCarriers={ onFilterToCarriers }
							nextDesign={ nextDesign }
						/>
					) : null }
					<RatesSorter
						canSortByDelivery={ canSortByDelivery() }
						setSortBy={ setSortBy }
						sortingBy={ sortingBy }
						nextDesign={ nextDesign }
					/>
				</Flex>
				{ ! isNaN( customerPaidShipping ) &&
					customerPaidShipping > 0 && (
						<div
							className="customer-paid-shipping-banner"
							style={ customerPaidBannerStyles.container }
						>
							<Text
								size={ 13 }
								style={ customerPaidBannerStyles.text }
							>
								{ currentOrder?.shipping_methods
									? sprintf(
											// translators: %1$s: the amount the customer paid for shipping, %2$s: the shipping method name
											__(
												'Customer paid: %1$s for shipping (%2$s)',
												'woocommerce-shipping'
											),
											storeCurrency.formatAmount(
												customerPaidShipping
											),
											currentOrder.shipping_methods
									  )
									: sprintf(
											// translators: %s: the amount the customer paid for shipping
											__(
												'Customer paid: %s for shipping',
												'woocommerce-shipping'
											),
											storeCurrency.formatAmount(
												customerPaidShipping
											)
									  ) }
							</Text>
						</div>
					) }
				<CarrierRates rates={ sortRates( getRates(), sortingBy ) } />
				{ nextDesign && carriers.some( ( c ) => c.id === 'upsdap' ) ? (
					<p className="upsdap-trademark-notice upsdap-trademark-notice--desktop">
						{ __(
							'UPS, the UPS brandmark, UPS Ready®, and the color brown are trademarks of United Parcel Service of America, Inc. All Rights Reserved.',
							'woocommerce-shipping'
						) }
					</p>
				) : (
					<div></div>
				) }
			</Flex>
		</Flex>
	);
};
