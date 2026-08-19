import {
	Card,
	CardBody,
	CardDivider,
	Flex,
	Spinner,
	__experimentalText as Text,
	__experimentalSpacer as Spacer,
} from '@wordpress/components';
import { Badge } from '@wordpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { select, dispatch } from '@wordpress/data';
import { isEmpty } from 'lodash';
import { labelPurchaseStore } from 'data/label-purchase';
import { TAB_NAMES } from 'components/label-purchase/packages/constants';
import {
	CarrierPackage,
	CustomPackage,
	SavedTemplates,
} from 'components/label-purchase/packages/tab-views';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { recordEvent } from 'utils/tracks';
import { useCollapsibleCard } from '../internal/useCollapsibleCard';

import { uspsHazmatCategories } from 'components/label-purchase/hazmat/usps-hazmat-categories';
import { Hazmat } from 'components/label-purchase/hazmat';
import PackageTypeSelect from '../internal/package-type-select';
import { withBoundaryNext } from 'components/HOC/error-boundary/with-boundary-next';

const PackagesCardComponent = () => {
	const {
		packages: {
			getCustomPackage,
			setCustomPackage,
			getSelectedPackage,
			setSelectedPackage,
			setCurrentPackageTab,
			currentPackageTab,
		},
		hazmat: { getShipmentHazmat },
		shipment: { currentShipmentId },
		rates: { removeSelectedRate, isFetching },
	} = useLabelPurchaseContext();

	const selectedPackage = getSelectedPackage();
	const rawPackageData = getCustomPackage();

	const tabSelectionClick = ( tabName: string ) => {
		// Prevent the click to be triggered on initial load, but also on each re-render.
		if ( currentPackageTab === tabName ) {
			return;
		}

		const availableRates =
			select( labelPurchaseStore ).getRatesForShipment(
				currentShipmentId
			);

		if ( ! isEmpty( availableRates ) ) {
			dispatch( labelPurchaseStore ).ratesReset();
		}

		removeSelectedRate();

		setCurrentPackageTab( tabName );

		recordEvent( 'label_purchase_package_tab_clicked', {
			tab_name: tabName,
		} );
	};

	const { CardHeader, isOpen } = useCollapsibleCard( true );

	const packageNeedsDimensions = () => {
		if ( currentPackageTab === TAB_NAMES.CUSTOM_PACKAGE ) {
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

	const getPackageSummary = () => {
		if ( currentPackageTab === TAB_NAMES.CUSTOM_PACKAGE ) {
			if ( packageNeedsDimensions() ) {
				return (
					<Badge intent="low">
						{ __( 'Needs dimensions', 'woocommerce-shipping' ) }
					</Badge>
				);
			}

			const hazmat = getShipmentHazmat();

			return sprintf(
				/* translators: %1$s: length, %2$s: width, %3$s: height, %4$s: hazmat info */
				__( '%1$sin x %2$sin x %3$sin %4$s', 'woocommerce-shipping' ),
				String( rawPackageData.length ),
				String( rawPackageData.width ),
				String( rawPackageData.height ),
				hazmat.isHazmat
					? ` · ${
							uspsHazmatCategories[
								hazmat.category as keyof typeof uspsHazmatCategories
							]
					  }`
					: ''
			);
		} else if ( currentPackageTab === TAB_NAMES.CARRIER_PACKAGE ) {
			if ( selectedPackage ) {
				const hazmat = getShipmentHazmat();
				return (
					selectedPackage.name +
					( hazmat.isHazmat
						? ` · ${
								uspsHazmatCategories[
									hazmat.category as keyof typeof uspsHazmatCategories
								]
						  }`
						: '' )
				);
			}
			return (
				<Badge intent="low">
					{ __( 'Needs dimensions', 'woocommerce-shipping' ) }
				</Badge>
			);
		}
	};

	return (
		<Card>
			<CardHeader iconSize={ 'small' } isBorderless>
				<Flex direction={ 'row' } align="space-between">
					<Text as="span" weight={ 500 } size={ 15 }>
						{ __( 'Package', 'woocommerce-shipping' ) }
					</Text>
					{ ! isFetching &&
						( ! isOpen || packageNeedsDimensions() ) && (
							<Text
								as="span"
								weight={ 400 }
								size={ 13 }
								style={ {
									maxWidth: '300px',
									overflow: 'hidden',
									textOverflow: 'ellipsis',
									whiteSpace: 'nowrap',
								} }
							>
								{ getPackageSummary() }
							</Text>
						) }
					{ isOpen && isFetching && (
						<Flex
							direction="row"
							align="center"
							justify="flex-end"
							gap={ 0 }
						>
							<div style={ { marginTop: -2 } }>
								<Spinner />
							</div>
							<Text as="span" weight={ 400 } size={ 12 }>
								{ __(
									'Fetching rates …',
									'woocommerce-shipping'
								) }
							</Text>
						</Flex>
					) }
				</Flex>
			</CardHeader>
			{ isOpen && (
				<CardBody style={ { paddingTop: 0 } }>
					<Spacer marginBottom={ 4 }>
						<PackageTypeSelect
							currentPackageTab={ currentPackageTab }
							setCurrentPackageTab={ tabSelectionClick }
							selectedPackage={ selectedPackage }
							setSelectedPackage={ setSelectedPackage }
						/>
					</Spacer>
					{ currentPackageTab === TAB_NAMES.CUSTOM_PACKAGE && (
						<CustomPackage
							rawPackageData={ rawPackageData }
							setRawPackageData={ setCustomPackage }
							setSelectedPackage={ setSelectedPackage }
						/>
					) }
					{ currentPackageTab === TAB_NAMES.CARRIER_PACKAGE && (
						<CarrierPackage
							{ ...( {
								selectedPackage,
								setSelectedPackage,
							} as Record< string, unknown > ) }
						/>
					) }
					{ currentPackageTab === TAB_NAMES.SAVED_TEMPLATES && (
						<SavedTemplates
							{ ...( {
								selectedPackage,
								setSelectedPackage,
							} as Record< string, unknown > ) }
						/>
					) }
					<CardDivider
						style={ {
							marginTop: '16px',
							marginBottom: '16px',
						} }
					/>
					<Hazmat />
				</CardBody>
			) }
		</Card>
	);
};

export const PackagesCard = withBoundaryNext( PackagesCardComponent )();
