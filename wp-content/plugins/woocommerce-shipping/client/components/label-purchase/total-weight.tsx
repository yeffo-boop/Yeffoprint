import {
	Flex,
	FlexBlock,
	__experimentalInputControl as InputControl,
	__experimentalInputControlSuffixWrapper as InputControlSuffixWrapper,
	SelectControl,
} from '@wordpress/components';
import _, { isNumber } from 'lodash';
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	getWeightUnit,
	convertWeightToUnit,
	WEIGHT_UNITS,
	minWeightThresholds,
} from 'utils';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { WeightUnit } from 'types';

const formatNumber = ( val: string | number ) => Number( val ).toFixed( 2 );
export const TotalWeight = ( { packageWeight = 0 } ) => {
	const defaultUnit = getWeightUnit();
	const {
		weight: {
			getShipmentWeight,
			getShipmentTotalWeight,
			setShipmentTotalWeight,
		},
		rates: { isFetching, errors, setErrors },
		nextDesign,
	} = useLabelPurchaseContext();

	const shipmentWeight = getShipmentWeight();

	const fieldName = 'totalWeight';

	const [ weightUnit, setWeightUnit ] = useState< WeightUnit >( () => {
		const weight = getShipmentWeight();
		if ( weight === 0 ) {
			return defaultUnit;
		}

		// Only auto-switch to a smaller display unit when the store is set to a
		// larger unit (lbs or kg). If the store is already set to oz or g, respect
		// that setting without switching, regardless of the weight value.
		if (
			defaultUnit === WEIGHT_UNITS.OZ ||
			defaultUnit === WEIGHT_UNITS.G
		) {
			return defaultUnit;
		}

		const isMetric = defaultUnit === WEIGHT_UNITS.KG;
		const smallerUnit = isMetric ? WEIGHT_UNITS.G : WEIGHT_UNITS.OZ;
		const threshold = isMetric ? 1000 : 16;

		const smallerValue = convertWeightToUnit(
			weight,
			defaultUnit,
			smallerUnit
		);
		return smallerValue < threshold ? smallerUnit : defaultUnit;
	} );

	const minValue = Math.max(
		minWeightThresholds[ weightUnit ] || 0,
		convertWeightToUnit( getShipmentWeight(), defaultUnit, weightUnit )
	);

	useEffect( () => {
		// Both are in same unit (defaultUnit) so we can add them directly
		const totalWeight = shipmentWeight + Number( packageWeight );
		// Convert totalWeight to the display unit (weightUnit) for proper comparison with minValue
		// minValue is computed in weightUnit, so both must be in the same unit for accurate comparison
		const totalWeightInDisplayUnit = convertWeightToUnit(
			totalWeight,
			defaultUnit,
			weightUnit
		);
		// For nextDesign, always update to reflect actual items regardless of minValue as we have LB and OZ inputs.
		// For legacy design, respect minValue check
		if ( nextDesign || totalWeightInDisplayUnit >= minValue ) {
			setShipmentTotalWeight( totalWeight );
		}
		// reset errors on initial render to avoid false positives on context switch
		if ( errors.totalWeight !== false ) {
			setErrors( () => ( {
				...errors,
				totalWeight: false,
			} ) );
		}

		// This effect should not run on `errors` change, so it's removed from the dependency array
		// This should not run on minValue change, so it's removed from the dependency array
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		packageWeight,
		shipmentWeight,
		setShipmentTotalWeight,
		setErrors,
		nextDesign,
	] );

	const props = {
		onChange: ( val: string | undefined ) => {
			const value = Number( val );
			if ( value < minWeightThresholds[ weightUnit ] ) {
				return;
			}

			const { ...newErrors } = errors;
			delete newErrors[ fieldName ];
			setErrors( newErrors );

			// Total weight should be set in default unit
			setShipmentTotalWeight(
				convertWeightToUnit( value, weightUnit, defaultUnit )
			);
		},
		value: nextDesign
			? undefined
			: formatNumber(
					convertWeightToUnit(
						getShipmentTotalWeight(),
						defaultUnit,
						weightUnit
					)
			  ),
		className: errors[ fieldName ]
			? 'package-total-weight has-error'
			: 'package-total-weight',
		onValidate: ( value: string ) => {
			const float = parseFloat( value );
			const threshold = minWeightThresholds[ weightUnit ];
			if ( float < threshold ) {
				setErrors( {
					...errors,
					[ fieldName ]: {
						message: sprintf(
							// translators: %1$s: minimum weight, %2$s: weight unit
							__(
								'Weight must be greater than or equal to %1$s %2$s',
								'woocommerce-shipping'
							),
							String( threshold ),
							weightUnit
						),
					},
				} );
				return;
			}

			setErrors( {
				...errors,
				[ fieldName ]: ! isNumber( float ) || float <= 0,
			} );
		},
		help:
			errors[ fieldName ] &&
			typeof errors[ fieldName ] === 'object' &&
			'message' in errors[ fieldName ]
				? errors[ fieldName ].message
				: '',
	};

	const onUnitChange = ( newUnit: WeightUnit ) => {
		setWeightUnit( newUnit );
	};

	const weightUnitOptions = Object.values( WEIGHT_UNITS ).map( ( unit ) => ( {
		label: unit,
		value: unit,
	} ) );

	return (
		<FlexBlock>
			{ nextDesign ? (
				<Flex
					direction="row"
					justify="flex-start"
					align="flex-end"
					gap={ nextDesign ? 4 : 0 }
					style={ { maxWidth: 326 } }
				>
					<FlexBlock>
						<InputControl
							label={ __(
								'Total Shipment Weight',
								'woocommerce-shipping'
							) }
							suffix={
								<InputControlSuffixWrapper>
									{ WEIGHT_UNITS.LBS }
								</InputControlSuffixWrapper>
							}
							type="number"
							min={ 0 }
							step={ 1 }
							{ ..._.omit(
								props,
								'value',
								'onChange',
								'onValidate'
							) }
							value={ Math.floor(
								getShipmentTotalWeight()
							).toString() }
							onChange={ ( val ) => {
								// Prevent empty values - default to 0 if empty
								let lbsValue =
									val === '' || val === undefined
										? 0
										: Number( val );
								// Validate and clamp the value (no negative values)
								if ( isNaN( lbsValue ) || lbsValue < 0 ) {
									lbsValue = 0;
								}
								setShipmentTotalWeight(
									lbsValue + ( getShipmentTotalWeight() % 1 )
								); // preserve decimal part
							} }
							__next40pxDefaultSize
						/>
					</FlexBlock>
					<FlexBlock>
						<InputControl
							label={ null }
							type="number"
							suffix={
								<InputControlSuffixWrapper>
									{ WEIGHT_UNITS.OZ }
								</InputControlSuffixWrapper>
							}
							min={ 0 }
							step={ 1 }
							max={ 15 }
							{ ..._.omit(
								props,
								'value',
								'onChange',
								'onValidate'
							) }
							value={
								getShipmentTotalWeight() % 1 === 0
									? '0'
									: Math.round(
											( getShipmentTotalWeight() % 1 ) *
												16
									  ).toString()
							}
							onChange={ ( val ) => {
								// Prevent empty values - default to 0 if empty
								let ozValue =
									val === '' || val === undefined
										? 0
										: parseInt( val, 10 );

								// Validate and clamp the value to valid range (0-15)
								if ( isNaN( ozValue ) || ozValue < 0 ) {
									ozValue = 0;
								} else if ( ozValue > 15 ) {
									ozValue = 0; // Reset to 0 if out of range
								}
								setShipmentTotalWeight(
									Math.floor( getShipmentTotalWeight() ) +
										ozValue / 16
								);
							} }
							__next40pxDefaultSize
						/>
					</FlexBlock>
				</Flex>
			) : (
				<Flex gap={ 3 } align="flex-start">
					<InputControl
						label={ __(
							'Total shipment weight (with package)',
							'woocommerce-shipping'
						) }
						type="number"
						disabled={ isFetching }
						step={ [ 'g', 'oz' ].includes( weightUnit ) ? 1 : 0.1 }
						min={ minValue }
						{ ...props }
						__next40pxDefaultSize
					/>
					<SelectControl
						label={
							// should get hidden by css
							__( 'Unit', 'woocommerce-shipping' )
						}
						className="package-total-weight-unit"
						value={ weightUnit }
						options={ weightUnitOptions }
						disabled={ isFetching }
						onChange={ onUnitChange }
						// Opting into the new styles for margin bottom
						__nextHasNoMarginBottom
						// Opting into the new styles for height
						__next40pxDefaultSize
					/>
				</Flex>
			) }
		</FlexBlock>
	);
};
