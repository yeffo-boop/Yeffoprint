import { BaseControl, __experimentalText as Text } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	DimensionUnit,
	Label,
	PackageDimensions,
	ReturnShipmentInfo,
	WeightUnit,
} from 'types';
import {
	getStoredPackageDimensions,
	getWeightUnit,
	getDimensionsUnit,
} from 'utils/config';
import { convertDimensionToUnit, convertWeightToUnit } from 'utils';
import { useLabelPurchaseContext } from 'context/label-purchase';

interface LabelWithPackageData extends Label {
	packageWeight?: number;
	packageLength?: number;
	packageWidth?: number;
	packageHeight?: number;
}

interface PackageDetailsProps {
	label?: Label | null;
	returnShipmentInfo?: ReturnShipmentInfo | null;
	currentShipmentId?: string;
}

/**
 * Component that displays package dimensions and weight from a purchased label or preserved return data
 *
 * This component handles both regular shipment labels and return shipment data,
 * with proper fallback and validation logic.
 *
 * @param {PackageDetailsProps}     props                    - Component props
 * @param {Label|null}              props.label              - The purchased label data containing package information
 * @param {ReturnShipmentInfo|null} props.returnShipmentInfo - Return shipment data with preserved package info
 *
 * @return {JSX.Element|null} Package details UI or null if no data available
 *
 * @example
 * // Display package details for a regular shipment
 * <PackageDetails label={purchasedLabel} />
 *
 * @example
 * // Display package details for a return shipment
 * <PackageDetails returnShipmentInfo={returnData} />
 */
export const PackageDetails = ( {
	label,
	returnShipmentInfo,
	currentShipmentId,
}: PackageDetailsProps ) => {
	const { packages, weight } = useLabelPurchaseContext();

	// Get package dimensions from stored metadata
	const storedPackageDimensions = getStoredPackageDimensions();

	let packageData: PackageDimensions | null = null;

	// Try to get dimensions from stored metadata
	if ( currentShipmentId && storedPackageDimensions ) {
		const shipmentKey =
			`shipment_${ currentShipmentId }` as keyof typeof storedPackageDimensions;
		const shipmentDimensions = storedPackageDimensions[ shipmentKey ];
		if ( shipmentDimensions?.[ 0 ] ) {
			packageData = shipmentDimensions[ 0 ];
		}
	}

	// Try to get from label object (for backward compatibility)
	if ( ! packageData && label ) {
		const typedLabel = label as LabelWithPackageData;
		if (
			typedLabel.packageWeight !== undefined ||
			typedLabel.packageLength !== undefined ||
			typedLabel.packageWidth !== undefined ||
			typedLabel.packageHeight !== undefined
		) {
			packageData = {
				package_weight: typedLabel.packageWeight,
				package_length: typedLabel.packageLength,
				package_width: typedLabel.packageWidth,
				package_height: typedLabel.packageHeight,
			};
		}
	}

	// Fall back to preserved package data from return shipments
	if ( ! packageData && returnShipmentInfo?.originalPackageDetails ) {
		packageData = returnShipmentInfo.originalPackageDetails;
	}

	// Get current package data from context if not purchased yet
	if ( ! packageData && ! label && packages ) {
		const currentPackage = packages.getPackageForRequest();
		if ( currentPackage ) {
			// Transform package data to match expected format
			// Note: weight from context is in store's configured unit
			packageData = {
				package_weight: weight.getShipmentWeight(),
				package_length: Number( currentPackage.length ),
				package_width: Number( currentPackage.width ),
				package_height: Number( currentPackage.height ),
			};
		}
	}

	if ( ! packageData ) {
		return null;
	}

	/**
	 * Extract package dimensions (stored as snake_case in metadata).
	 *
	 * Unit detection logic:
	 * - `_snapshot` fields (new data): Trust them, value is in that unit
	 * - No `_snapshot` fields: Value is assumed to be in current store unit
	 *
	 * Note: Legacy data may have `package_weight_unit` but it was hardcoded
	 * to 'oz' regardless of actual value unit, so we ignore it.
	 */
	const packageWeight = packageData.package_weight;
	const packageLength = packageData.package_length;
	const packageWidth = packageData.package_width;
	const packageHeight = packageData.package_height;

	// Only render if we have at least weight or dimensions
	const hasWeight = packageWeight !== undefined && packageWeight !== null;
	const hasDimensions = Boolean(
		packageLength ?? packageWidth ?? packageHeight
	);

	if ( ! hasWeight && ! hasDimensions ) {
		return null;
	}

	/**
	 * Determine the actual unit the weight value is stored in
	 * The old unit can only be set for legacy data and may actually be the wrong unit captured at the time of purchase.
	 * - New data has _snapshot field, legacy/context data uses current store unit
	 * - If no snapshot field exists, use the current store unit
	 */
	const storedWeightUnit =
		packageData.package_weight_unit ??
		packageData.package_weight_unit_snapshot ??
		getWeightUnit();

	/**
	 * Determine the actual unit the dimensions are stored in
	 * The old unit can only be set for legacy data and may actually be the wrong unit captured at the time of purchase.
	 * - New data has _snapshot field, legacy/context data uses current store unit
	 * - If no snapshot field exists, use the current store unit
	 */
	const storedDimensionsUnit =
		packageData.package_dimensions_unit ??
		packageData.package_dimensions_unit_snapshot ??
		getDimensionsUnit();

	// Display units are always the current store units
	const displayWeightUnit: WeightUnit = getWeightUnit();
	const displayDimensionsUnit: DimensionUnit = getDimensionsUnit();

	/**
	 * Convert dimensions to display unit if they differ from stored unit.
	 * Similar to weight conversion - ensures consistent display regardless
	 * of what unit the data was originally stored in.
	 */
	const convertDimension = ( value: number | string | undefined ) => {
		const numericValue = Number( value );
		if ( isNaN( numericValue ) ) {
			return undefined;
		}
		if ( storedDimensionsUnit === displayDimensionsUnit ) {
			return numericValue;
		}
		return convertDimensionToUnit(
			numericValue,
			storedDimensionsUnit,
			displayDimensionsUnit
		);
	};

	const formatDimensions = () => {
		if ( ! hasDimensions ) {
			return null;
		}

		const convertedDimensions = [
			convertDimension( packageLength ),
			convertDimension( packageWidth ),
			convertDimension( packageHeight ),
		]
			.filter( Boolean )
			.map( ( dim ) => Math.round( dim! * 10 ) / 10 )
			.join( ' × ' );

		return convertedDimensions
			? `${ convertedDimensions } ${ displayDimensionsUnit }`
			: null;
	};

	const formatWeight = () => {
		if ( ! hasWeight ) {
			return null;
		}

		/**
		 * The stored weight unit is not the same as the selected weight unit on label purchase flow at the time of purchase, but rather the default unit of the store.
		 * So we need to convert the weight to the display unit.
		 */
		let displayWeight = packageWeight;
		if ( storedWeightUnit !== displayWeightUnit ) {
			displayWeight = convertWeightToUnit(
				packageWeight,
				storedWeightUnit,
				displayWeightUnit
			);
		}

		const roundedWeight = Math.round( displayWeight * 10 ) / 10;
		return `${ roundedWeight } ${ displayWeightUnit }`;
	};

	return (
		<>
			{ hasDimensions && (
				<BaseControl
					id="package-dimensions"
					label={ __( 'Package dimensions', 'woocommerce-shipping' ) }
					__nextHasNoMarginBottom={ true }
				>
					<Text>{ formatDimensions() }</Text>
				</BaseControl>
			) }

			{ hasWeight && (
				<BaseControl
					id="package-weight"
					label={ __( 'Package weight', 'woocommerce-shipping' ) }
					__nextHasNoMarginBottom={ true }
				>
					<Text>{ formatWeight() }</Text>
				</BaseControl>
			) }
		</>
	);
};
