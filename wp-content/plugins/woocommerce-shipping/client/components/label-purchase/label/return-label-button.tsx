import { useMemo } from 'react';
import type { ReactNode } from 'react';
import { Button, Tooltip } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { info } from '@wordpress/icons';
import { hasSubItems, getSubItems } from 'utils';
import {
	getDimensionsUnit,
	getStoredPackageDimensions,
	getWeightUnit,
} from 'utils/config';
import {
	ShipmentItem,
	ReturnShipmentInfo,
	Package,
	CustomPackage,
	Label,
	DimensionUnit,
	WeightUnit,
} from 'types';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { getReturnableShipmentItems } from './label-panel-actions';

interface LabelWithPackageData extends Label {
	packageWeight?: number;
	packageWeightUnit?: WeightUnit;
	packageLength?: number;
	packageWidth?: number;
	packageHeight?: number;
	packageDimensionsUnit?: DimensionUnit;
	packageDimensionsUnitSnapshot?: DimensionUnit;
	packageWeightUnitSnapshot?: WeightUnit;
}

interface ReturnLabelButtonProps {
	isDomesticShipment: boolean;
	shipments: Record< string, ShipmentItem[] >;
	currentShipmentId: string;
	setShipments: ( shipments: Record< string, ShipmentItem[] > ) => void;
	setCurrentShipmentId: ( id: string ) => void;
	setReturnShipments: (
		callback: (
			prev: Record< string, ReturnShipmentInfo >
		) => Record< string, ReturnShipmentInfo >
	) => void;
	returnShipments?: Record< string, ReturnShipmentInfo >;
	packages: {
		getSelectedPackage: () => Package | CustomPackage | null;
		setSelectedPackage: ( pkg: Package | CustomPackage ) => void;
	};
	selections: Record< string, ShipmentItem[] >;
	setSelection: ( selections: Record< string, ShipmentItem[] > ) => void;
	className?: string;
	onClick?: () => void;
	children?: ReactNode;
}

export const ReturnLabelButton = ( {
	isDomesticShipment,
	shipments,
	currentShipmentId,
	setShipments,
	setCurrentShipmentId,
	setReturnShipments,
	returnShipments,
	packages,
	selections,
	setSelection,
	className,
	onClick,
	children,
}: ReturnLabelButtonProps ) => {
	const { labels } = useLabelPurchaseContext();

	// Check if all items have already been returned
	const { availableItems, allItemsReturned, currentShipmentItems } =
		useMemo( () => {
			const shipmentItems = shipments[ currentShipmentId ] || [];
			const itemsAvailableForReturn = getReturnableShipmentItems( {
				shipments,
				currentShipmentId,
				returnShipments,
				selections,
				getShipmentLabel: labels.getCurrentShipmentLabel,
			} );

			return {
				availableItems: itemsAvailableForReturn,
				allItemsReturned: itemsAvailableForReturn.length === 0,
				currentShipmentItems: shipmentItems,
			};
		}, [
			shipments,
			returnShipments,
			selections,
			currentShipmentId,
			labels,
		] );

	const createReturnLabel = () => {
		try {
			const newShipmentId = Object.keys( shipments ).length;
			const newShipmentIdString = `${ newShipmentId }`;

			// This check should never be reached since button is disabled, but keep as safety
			if ( allItemsReturned ) {
				return;
			}

			onClick?.();

			// Capture parent shipment ID BEFORE changing currentShipmentId
			// If current shipment is already a return, use its parent; otherwise use current
			const parentShipmentId =
				returnShipments?.[ currentShipmentId ]?.parentShipmentId ??
				currentShipmentId;

			const newShipment = availableItems.map( ( orderItem ) => ( {
				...orderItem,
				subItems: orderItem.subItems || [],
			} ) );

			const allItems = newShipment
				.map( ( item ) =>
					hasSubItems( item ) ? getSubItems( item ) : item
				)
				.flat();

			setShipments( {
				...shipments,
				[ newShipmentIdString ]: newShipment,
			} );

			setCurrentShipmentId( newShipmentIdString );

			try {
				// Get package details from the purchased label for the current shipment
				let originalPackageDetails = null;
				const currentLabel =
					labels.getCurrentShipmentLabel( currentShipmentId );

				// Try to get package dimensions from stored metadata first
				// This is more reliable for custom packages
				const storedPackageDimensions = getStoredPackageDimensions();
				if (
					storedPackageDimensions &&
					typeof storedPackageDimensions === 'object'
				) {
					const shipmentKey =
						`shipment_${ currentShipmentId }` as keyof typeof storedPackageDimensions;
					const shipmentDimensions =
						storedPackageDimensions[ shipmentKey ];
					if ( shipmentDimensions?.[ 0 ] ) {
						originalPackageDetails = shipmentDimensions[ 0 ];
					}
				}

				// Fallback: try to get from label object (for backward compatibility)
				if ( ! originalPackageDetails && currentLabel ) {
					const labelWithPackageData =
						currentLabel as LabelWithPackageData;
					if (
						labelWithPackageData.packageWeight !== undefined ||
						labelWithPackageData.packageLength !== undefined ||
						labelWithPackageData.packageWidth !== undefined ||
						labelWithPackageData.packageHeight !== undefined
					) {
						originalPackageDetails = {
							package_weight: labelWithPackageData.packageWeight,
							package_weight_unit:
								labelWithPackageData.packageWeightUnit ??
								labelWithPackageData.packageWeightUnitSnapshot ??
								getWeightUnit(),
							package_length: labelWithPackageData.packageLength,
							package_width: labelWithPackageData.packageWidth,
							package_height: labelWithPackageData.packageHeight,
							package_dimensions_unit:
								labelWithPackageData.packageDimensionsUnit ??
								labelWithPackageData.packageDimensionsUnitSnapshot ??
								getDimensionsUnit(),
						};
					}
				}

				// Determine preservedPackage based on available data
				let preservedPackage;
				if ( currentLabel && originalPackageDetails ) {
					// Preserve exact package data from stored dimensions or label
					preservedPackage = {
						packageWeight: originalPackageDetails.package_weight,
						packageWeightUnit:
							originalPackageDetails.package_weight_unit ??
							originalPackageDetails.package_weight_unit_snapshot ??
							getWeightUnit(),
						packageLength: originalPackageDetails.package_length,
						packageWidth: originalPackageDetails.package_width,
						packageHeight: originalPackageDetails.package_height,
						packageDimensionsUnit:
							originalPackageDetails.package_dimensions_unit ??
							originalPackageDetails.package_dimensions_unit_snapshot ??
							getDimensionsUnit(),
						packageName: currentLabel.packageName,
						packageId: currentLabel.id,
					};
				} else if ( currentLabel ) {
					// Label exists but no dimensions - use label data only
					preservedPackage = {
						packageName: currentLabel.packageName,
						packageId: currentLabel.id,
					};
				} else {
					// Fallback: calculate weight from items if no parent label
					preservedPackage = {
						totalWeight: currentShipmentItems.reduce(
							( total, item ) => {
								const weight = parseFloat( item.weight || '0' );
								const quantity = item.quantity || 1;
								return total + weight * quantity;
							},
							0
						),
					};
				}

				setReturnShipments( ( prev ) => ( {
					...prev,
					[ newShipmentIdString ]: {
						isReturn: true,
						parentShipmentId,
						preservedPackage,
						originalPackageDetails:
							originalPackageDetails ?? undefined,
					},
				} ) );
			} catch {
				// Handle setReturnShipments errors gracefully
			}

			setSelection( {
				...selections,
				[ newShipmentIdString ]: allItems,
			} );

			try {
				const selectedPackage = packages.getSelectedPackage();
				if ( selectedPackage ) {
					packages.setSelectedPackage( selectedPackage );
				}
			} catch {
				// Handle package errors gracefully - continue without package selection
			}
		} catch {
			// Handle any other errors gracefully
		}
	};

	// Determine tooltip text and disabled state
	let tooltipText = '';
	let isDisabled = false;

	if ( ! isDomesticShipment ) {
		tooltipText = __(
			'Return labels are only available for domestic shipments (US to US)',
			'woocommerce-shipping'
		);
		isDisabled = true;
	} else if ( allItemsReturned ) {
		tooltipText = __(
			'All items from this shipment have already been returned',
			'woocommerce-shipping'
		);
		isDisabled = true;
	}

	if ( isDisabled ) {
		return (
			<Tooltip text={ tooltipText }>
				<span>
					<Button
						variant="tertiary"
						disabled
						icon={ info }
						iconPosition="right"
						key="return-label"
						className={ className }
					>
						{ children ??
							__( 'Return label', 'woocommerce-shipping' ) }
					</Button>
				</span>
			</Tooltip>
		);
	}

	return (
		<Button
			variant="tertiary"
			onClick={ createReturnLabel }
			key="return-label"
			className={ className }
		>
			{ children ?? __( 'Return label', 'woocommerce-shipping' ) }
		</Button>
	);
};
