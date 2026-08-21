import {
	Animate,
	Card,
	CardBody,
	Flex,
	__experimentalText as Text,
} from '@wordpress/components';
import { Badge } from '@wordpress/ui';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import { ShippingRates } from 'components/label-purchase/shipping-service';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { labelPurchaseStore } from 'data/label-purchase';
import { isEmpty } from 'lodash';
import { useCollapsibleCard } from '../internal/useCollapsibleCard';
import { NoRatesAvailableV2 } from '../internal/no-rates-available-v2';
import { withBoundaryNext } from 'components/HOC/error-boundary/with-boundary-next';
import { useMemo, useState } from 'react';
import { EnterPackageDetailsV2 } from '../internal/enter-package-details-v2';
import { FetchingRatesV2 } from '../internal/fetching-rates-v2';
import { Carrier, Rate } from 'types';

const ShippingRatesCardBodyContents = ( {
	availableRates,
	isFetching,
	packageNeedsDimensions,
	packageNeedsWeight,
}: {
	availableRates: Record< Carrier, Rate[] > | undefined;
	isFetching: boolean;
	packageNeedsDimensions: () => boolean;
	packageNeedsWeight: () => boolean;
} ) => {
	/**
	 * State flag to prevent showing stale rates from a previous package configuration.
	 * - Starts as `true` to show loading state on initial render
	 * - Set to `true` when package details change (dimensions/weight cleared)
	 * - Set to `false` once fetching begins, allowing rates to display after fetch completes
	 *
	 * This prevents a flash of old rates when the user changes package details,
	 * ensuring we wait for fresh rates to be fetched.
	 */
	const [ clearUntilFetching, setClearUntilFetching ] = useState( true );

	return useMemo( () => {
		// Priority 1: If package details are incomplete, prompt user to enter them
		if ( packageNeedsDimensions() || packageNeedsWeight() ) {
			// Reset the clear flag so old rates won't show after user enters new details
			setClearUntilFetching( true );
			return <EnterPackageDetailsV2 className="" />;
		}

		// Priority 2: Show loading animation while fetching rates
		if ( isFetching ) {
			// Clear the flag to allow rates to display once fetch completes
			setClearUntilFetching( false );
			return (
				<Animate type={ 'loading' }>
					{ ( { className } ) => (
						<FetchingRatesV2 className={ className } />
					) }
				</Animate>
			);
		}

		// Priority 3: Show rates if we've completed a fetch cycle (clearUntilFetching is false)
		if ( ! clearUntilFetching && Boolean( availableRates ) ) {
			// If rates object exists but is empty, no carriers returned rates
			if ( isEmpty( availableRates ) ) {
				return <NoRatesAvailableV2 className="" />;
			}
			// Display the available shipping rates
			return (
				<ShippingRates
					availableRates={ availableRates }
					isFetching={ false }
				/>
			);
		}

		// Fallback: Default to loading state while waiting for fetch to start
		return (
			<Animate type={ 'loading' }>
				{ ( { className } ) => (
					<FetchingRatesV2 className={ className } />
				) }
			</Animate>
		);
	}, [
		availableRates,
		isFetching,
		packageNeedsDimensions,
		packageNeedsWeight,
		clearUntilFetching,
	] );
};

const ShippingRatesCardComponent = () => {
	const {
		shipment: { currentShipmentId },
		rates: { isFetching },
		packages: { isCustomPackageTab, getCustomPackage, isPackageSpecified },
		weight: { getShipmentTotalWeight },
	} = useLabelPurchaseContext();
	const rawPackageData = getCustomPackage();
	const availableRates = useSelect(
		( select ) =>
			select( labelPurchaseStore ).getRatesForShipment(
				currentShipmentId
			),
		[ currentShipmentId ]
	);
	const { CardHeader, isOpen } = useCollapsibleCard( true );
	const packageNeedsDimensions = () => {
		if ( isCustomPackageTab() ) {
			if (
				! parseInt( rawPackageData?.length ?? '0', 10 ) ||
				! parseInt( rawPackageData?.width ?? '0', 10 ) ||
				! parseInt( rawPackageData?.height ?? '0', 10 )
			) {
				return true;
			}
		}
		return false;
	};
	const packageNeedsWeight = () => {
		return getShipmentTotalWeight() === 0;
	};

	return (
		<Card>
			<CardHeader iconSize={ 'small' } isBorderless>
				<Flex direction={ 'row' } align="space-between">
					<Text as="span" weight={ 500 } size={ 15 }>
						{ __( 'Shipping rates', 'woocommerce-shipping' ) }
					</Text>
					{ ! isOpen && ! isPackageSpecified() && (
						<Badge intent="low">
							{ __(
								'Missing package info',
								'woocommerce-shipping'
							) }
						</Badge>
					) }
				</Flex>
			</CardHeader>
			{ isOpen && (
				<CardBody style={ { paddingTop: 0 } }>
					<ShippingRatesCardBodyContents
						availableRates={ availableRates }
						isFetching={ isFetching }
						packageNeedsDimensions={ packageNeedsDimensions }
						packageNeedsWeight={ packageNeedsWeight }
					/>
				</CardBody>
			) }
		</Card>
	);
};

export const ShippingRatesCard = withBoundaryNext(
	ShippingRatesCardComponent
)();
