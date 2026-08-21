import { mapValues, sortBy, snakeCase, omit } from 'lodash';
import { useCallback, useState, useMemo } from '@wordpress/element';
import { dispatch, select, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import {
	Carrier,
	CustomPackage,
	Package,
	Rate,
	RateWithParent,
	RecordValues,
	RequestPackage,
	WPErrorRESTResponse,
	RateExtraOptionNames,
	RateExtraOptionValue,
	RateExtraOptions,
} from 'types';
import {
	applyPromo,
	areAllOriginsUnverified,
	getAccountSettings,
	getCurrentOrder,
	setAccountSettings,
	toUTCMidnightISOString,
} from 'utils';
import { addressStore } from 'data/address';
import { labelPurchaseStore } from 'data/label-purchase';
import {
	CUSTOM_BOX_ID_PREFIX,
	CUSTOM_PACKAGE_TYPES,
} from '../packages/constants';
import type { usePackageState } from './packages';
import { useHazmatState } from './hazmat';
import { useCustomsState } from './customs';
import { useShipmentState } from './shipment';
import {
	RATES_FETCH_ABORTED,
	RATES_FETCH_FAILED,
} from 'data/label-purchase/action-types';
import { LABEL_RATE_TYPE } from 'data/constants';
import { DELIVERY_PROPERTIES, SORT_BY } from '../shipping-service/constants';

/**
 * Maximum allowed price change (in dollars) when matching rates after refetch.
 * If the price difference exceeds this threshold, the rate won't be auto-matched
 * to protect merchants from unexpected significant price increases.
 */
const RATE_MATCH_PRICE_THRESHOLD = 1.0;

type RatesByCarrier = Record< Carrier, Rate[] >;

interface UseRatesStateProps {
	currentShipmentId: string;
	currentPackageTab: string;
	getPackageForRequest: ReturnType<
		typeof usePackageState
	>[ 'getPackageForRequest' ];
	applyHazmatToPackage: ReturnType<
		typeof useHazmatState
	>[ 'applyHazmatToPackage' ];
	totalWeight: number;
	customs: ReturnType< typeof useCustomsState >;
	getShipmentOrigin: ReturnType<
		typeof useShipmentState
	>[ 'getShipmentOrigin' ];
	getCurrentShipmentIsReturn: ReturnType<
		typeof useShipmentState
	>[ 'getCurrentShipmentIsReturn' ];
	getCurrentShipmentDate: ReturnType<
		typeof useShipmentState
	>[ 'getCurrentShipmentDate' ];
}

/**
 * This regexp is intended to catch field errors in the format of "%1$s must be greater than %2$d."
 *
 * @see maybeReformatInvalidParamError
 * @see rest_validate_value_from_schema() in wp-includes/rest-api.php
 */
const restInvalidParamErrorMessageRegexp = /^([^\s]+) (.+)$/;

/**
 * Mapping of section name as extracted using `restInvalidParamErrorMessageRegexp` to a human-readable name.
 */
const ratesEndpointArgToSectionNameMap: Record< string, string > = {
	origin: __( 'Origin address', 'woocommerce-shipping' ),
	destination: __( 'Destination address', 'woocommerce-shipping' ),
};

/**
 * Mapping of field name as extracted using `restInvalidParamErrorMessageRegexp` to a human-readable name.
 */
const ratesEndpointArgToFieldDescriptionMap: Record< string, string > = {
	'packages[0][length]': __( 'Package length', 'woocommerce-shipping' ),
	'packages[0][width]': __( 'Package width', 'woocommerce-shipping' ),
	'packages[0][height]': __( 'Package height', 'woocommerce-shipping' ),
	'packages[0][weight]': __( 'Package weight', 'woocommerce-shipping' ),
};

const maybePrependSectionName = (
	errorMessage: string,
	sectionName?: string
) => {
	if ( sectionName ) {
		return sprintf(
			// translators: %1$s The name of the form section containing the erroneous form field, %2$s is the error message.
			__( '%1$s: %2$s', 'woocommerce-shipping' ),
			sectionName,
			errorMessage
		);
	}

	return errorMessage;
};

/**
 * Parses REST endpoint errors with the code `rest_invalid_param` matching `restInvalidParamErrorMessageRegexp`.
 *
 * When detected, these will be reformatted to use human-readable field names, as defined in
 * `ratesEndpointArgToFieldDescriptionMap`.
 *
 * @param payload
 */
const maybeReformatInvalidParamError = ( payload: WPErrorRESTResponse ) => {
	if ( payload.code !== 'rest_invalid_param' ) {
		return null;
	}

	return Object.entries( payload.data.params )
		.map( ( [ erroneousSection, paramErrorMessage ] ) => {
			const sectionName =
				ratesEndpointArgToSectionNameMap[ erroneousSection ] ?? '';

			const regexpMatch =
				restInvalidParamErrorMessageRegexp.exec( paramErrorMessage );

			if ( regexpMatch === null ) {
				return maybePrependSectionName(
					paramErrorMessage,
					sectionName
				);
			}

			const [ , fieldName, fieldError ] = regexpMatch;
			const mappedFieldName =
				ratesEndpointArgToFieldDescriptionMap[ fieldName ] ?? fieldName;

			return maybePrependSectionName(
				sprintf(
					// translators: %1$s The name of the form field that has an error (origin address or destination), %2$s is the error message, e.g. "must be greater than 0".
					__( '%1$s %2$s.', 'woocommerce-shipping' ),
					mappedFieldName,
					fieldError
				),
				sectionName
			);
		} )
		.join( '\n' );
};

export function useRatesState( {
	currentShipmentId,
	getPackageForRequest,
	applyHazmatToPackage,
	totalWeight,
	customs: { maybeApplyCustomsToPackage },
	getShipmentOrigin,
	getCurrentShipmentIsReturn,
	getCurrentShipmentDate,
}: UseRatesStateProps ) {
	const accountSettings = useMemo( getAccountSettings, [] );
	const allShipmentRates = select( labelPurchaseStore ).getSelectedRates();
	const [ selectedRates, selectRates ] = useState<
		Record< string, RateWithParent | null | undefined >
	>( allShipmentRates ?? { 0: null } );

	const [ isFetching, setIsFetching ] = useState( false );
	/**
	 * Counter that increments only when the merchant explicitly triggers a rate
	 * fetch (clicking "Get rates" or changing shipment inputs). It deliberately
	 * does NOT change for system-driven refetches (e.g. the auto-fetch that
	 * runs after a failed purchase resets rates), so purchase/status error
	 * notices stay visible until the merchant actually acts on the failure.
	 */
	const [ userRateFetchNonce, setUserRateFetchNonce ] = useState( 0 );
	const [ errors, setErrors ] = useState<
		Record<
			string | 'endpoint',
			| boolean
			| null
			| Record< string | 'rates' | 'message', string | string[] >
		>
	>( {} );

	const selectedRateOptionsForAllShipments =
		select( labelPurchaseStore ).getSelectedRateOptions();

	const [ selectedRateOptions, selectRateOptions ] = useState<
		Record< string, RateExtraOptions >
	>( {
		...selectedRateOptionsForAllShipments,
		[ currentShipmentId ]:
			selectedRateOptionsForAllShipments[ currentShipmentId ] ?? {},
	} );

	const selectRateOption = useCallback(
		(
			option: RateExtraOptionNames,
			value: RateExtraOptionValue,
			surcharge: number
		) => {
			selectRateOptions( ( prev ) => ( {
				...prev,
				[ currentShipmentId ]:
					/**
					 * Remove it if the value is false or 'no'
					 * Even though the API supports it, we don't want to show it in the UI.
					 */
					value === false || value === 'no'
						? omit( prev[ currentShipmentId ], option )
						: {
								...prev[ currentShipmentId ],
								[ option ]: {
									value,
									surcharge,
								},
						  },
			} ) );
		},
		[ currentShipmentId, selectRateOptions ]
	);

	const getSelectedRateOptions = useCallback(
		() => selectedRateOptions[ currentShipmentId ] ?? {},
		[ selectedRateOptions, currentShipmentId ]
	);

	const availableRates = useSelect(
		( selector ) => {
			return selector( labelPurchaseStore ).getRatesForShipment(
				currentShipmentId
			);
		},
		[ currentShipmentId ]
	);

	const resetSelectedRateOptions = useCallback( () => {
		selectRateOptions( ( prev ) => ( {
			...prev,
			[ currentShipmentId ]: {},
		} ) );
	}, [ currentShipmentId, selectRateOptions ] );

	const selectRate = useCallback(
		( rate: Rate, parent?: Rate ) => {
			setAccountSettings( {
				...accountSettings,
				userMeta: {
					...accountSettings.userMeta,
					last_carrier_id: rate.carrierId,
					last_service_id: rate.serviceId,
				},
			} );

			/**
			 * We need to reset the selected rate options when:
			 * - The parent is null, we are selecting the default rate, so we need to reset the selected rate options.
			 * - The shipmentId of the selected rate is different from the current shipmentId (e.g. when we've refetched rates or switched to another base rate).
			 */
			if (
				! parent &&
				selectedRates[ currentShipmentId ]?.rate?.serviceId !==
					rate.serviceId
			) {
				resetSelectedRateOptions();
			}

			return selectRates( ( prev ) => ( {
				...prev,
				[ currentShipmentId ]: {
					rate,
					parent: parent ?? null,
				},
			} ) );
		},
		[
			accountSettings,
			currentShipmentId,
			resetSelectedRateOptions,
			selectedRates,
		]
	);

	const getSelectedRate = useCallback(
		() => selectedRates[ currentShipmentId ],
		[ currentShipmentId, selectedRates ]
	);

	/**
	 * Remove the currently selected shipment rate.
	 *
	 * This could be useful e.g. after a label has been refunded, and we want
	 * to remove the current selection.
	 */
	const removeSelectedRate = useCallback( () => {
		selectRates( ( currentSelectedRates ) => {
			if ( currentSelectedRates[ currentShipmentId ] ) {
				return {
					...currentSelectedRates,
					[ currentShipmentId ]: null,
				};
			}
			return currentSelectedRates;
		} );

		resetSelectedRateOptions();
	}, [ currentShipmentId, resetSelectedRateOptions ] );

	const preselectRateBasedOnLastSelections = useCallback(
		// Passing shipmentId makes sure the auto-selection happens for the shipment that initiated the request
		( shipmentId: string ) => {
			if ( ! accountSettings.purchaseSettings.use_last_service ) {
				return;
			}

			const { last_carrier_id, last_service_id } =
				accountSettings.userMeta;
			const rates: RatesByCarrier | undefined =
				select( labelPurchaseStore ).getRatesForShipment( shipmentId );

			if ( rates?.[ last_carrier_id ] ) {
				const ratesForService = rates[ last_carrier_id ];
				const selectableRate = ratesForService.find(
					( rate ) => rate.serviceId === last_service_id
				);

				if ( selectableRate ) {
					// Move the preselected rate to the first index
					const updatedRates = [
						selectableRate,
						...ratesForService.filter(
							( rate ) => rate !== selectableRate
						),
					];
					rates[ last_carrier_id ] = updatedRates;

					/**
					 * selectRates is favor over selectRate here because selectRate remembers the selected rate
					 * and we don't want to override that here. Besides we need to specify the shipmentId here.
					 */
					selectRates( ( prev ) => ( {
						...prev,
						[ shipmentId ]: {
							rate: selectableRate,
							parent: null,
						},
					} ) );
				}
			}
			return rates;
		},
		[ selectRates, accountSettings ]
	);

	const resetRates = useCallback( () => {
		dispatch( labelPurchaseStore ).ratesReset();
		setErrors( ( prev ) => ( { ...prev, endpoint: null } ) );
	}, [] );

	const fetchRates = useCallback(
		async (
			pkg: ( Package | CustomPackage ) & {
				isLetter?: boolean;
			},
			{ userInitiated = true }: { userInitiated?: boolean } = {}
		) => {
			// Early return when all origin addresses are unverified (or none exist).
			const originAddresses = select( addressStore ).getOriginAddresses();
			if ( areAllOriginsUnverified( originAddresses ) ) {
				return;
			}

			// Mark that the merchant explicitly requested rates so dependent
			// notices (purchase/status errors) can clear themselves. System
			// refetches pass `userInitiated: false` and are intentionally
			// excluded.
			if ( userInitiated ) {
				setUserRateFetchNonce( ( nonce ) => nonce + 1 );
			}

			setIsFetching( true );
			setErrors( { ...errors, endpoint: null } );
			removeSelectedRate();

			const {
				type,
				isLetter,
				id = CUSTOM_BOX_ID_PREFIX,
				length,
				width,
				height,
			} = pkg;

			const dimensions = mapValues(
				{ length, width, height },
				parseFloat
			);
			const requestPackage: RequestPackage = {
				id: currentShipmentId,
				box_id: id,
				...dimensions,
				weight: totalWeight,
				is_letter: type
					? type === CUSTOM_PACKAGE_TYPES.ENVELOPE
					: isLetter ?? false,
			};

			const currentShipmentDate = getCurrentShipmentDate()?.shippingDate;

			// @ts-ignore TODO: Convert getRates to TypeScript
			const { payload, type: responseType } = await dispatch(
				labelPurchaseStore
			).getRates( {
				packages: [
					maybeApplyCustomsToPackage(
						applyHazmatToPackage( requestPackage )
					),
				],
				orderId: getCurrentOrder().id,
				origin: getShipmentOrigin(),
				is_return: getCurrentShipmentIsReturn(),
				shipment_options: {
					label_date: currentShipmentDate
						? toUTCMidnightISOString( currentShipmentDate )
						: undefined,
				},
			} );

			if ( responseType === RATES_FETCH_ABORTED ) {
				// Aborted, do nothing, the previous request was aborted, so we don't need to set any errors.
				// no need to setIsFetching( false ) because the request was aborted because of a new request.
				return;
			}

			if ( responseType === RATES_FETCH_FAILED ) {
				setErrors( ( prev ) => ( {
					...prev,
					endpoint: {
						rates:
							maybeReformatInvalidParamError( payload ) ??
							payload?.message ??
							__(
								'There was an issue getting rates for this package, please try again.',
								'woocommerce-shipping'
							),
					},
				} ) );
			}

			const endpointErrors: {
				message: string;
			}[] = payload?.[ currentShipmentId ]?.default?.errors ?? [];

			if ( endpointErrors.length ) {
				setErrors( ( prev ) => ( {
					...prev,
					endpoint: {
						rates: [
							// Remove duplicate errors by using a Set as Sets can only contain unique values.
							...new Set(
								endpointErrors.map( ( { message } ) => message )
							),
						],
					},
				} ) );
			}

			setIsFetching( false );

			preselectRateBasedOnLastSelections( currentShipmentId );
		},
		[
			errors,
			removeSelectedRate,
			currentShipmentId,
			totalWeight,
			maybeApplyCustomsToPackage,
			applyHazmatToPackage,
			getShipmentOrigin,
			getCurrentShipmentIsReturn,
			preselectRateBasedOnLastSelections,
			getCurrentShipmentDate,
		]
	);

	/**
	 * Sort Rates when filter dropdown is used.
	 * @param rates
	 * @return Sorted rates
	 */
	const sortRates = useCallback(
		( rates: Rate[], sortingBy: string ) => {
			let sortedRates;
			if ( sortingBy === SORT_BY.CHEAPEST ) {
				sortedRates = sortBy( rates, ( rate ) =>
					rate.promoId
						? applyPromo( rate.rate, rate.promoId )
						: rate.rate
				);
			} else if ( sortingBy === SORT_BY.FASTEST ) {
				sortedRates = sortBy( rates, DELIVERY_PROPERTIES );
			} else {
				sortedRates = rates;
			}

			// Always put MediaMail at the bottom of the list.
			const mediaMailRate = sortedRates.find(
				( rate ) => rate?.serviceId === 'MediaMail'
			);
			if ( mediaMailRate ) {
				const filteredRates = sortedRates.filter(
					( rate ) => rate && rate.serviceId !== 'MediaMail'
				);
				sortedRates = [ ...filteredRates, mediaMailRate ];
			}
			if ( accountSettings.purchaseSettings.use_last_service ) {
				const last_service_id =
					accountSettings.userMeta.last_service_id;
				const selectableRate = sortedRates.find(
					( rate ) => rate.serviceId === last_service_id
				);

				if ( selectableRate ) {
					sortedRates = [
						selectableRate,
						...sortedRates.filter(
							( rate ) => rate !== selectableRate
						),
					];
				}
			}

			return sortedRates;
		},
		[
			accountSettings.purchaseSettings.use_last_service,
			accountSettings.userMeta.last_service_id,
		]
	);

	/**
	 * Reselect the rate and its parent.
	 * This is useful when we've refetched rates and we want to select the rate with the same serviceId.
	 * The rate is matched by serviceId, allowing for minor price changes between fetches.
	 * If the price change exceeds RATE_MATCH_PRICE_THRESHOLD, the match fails to protect
	 * merchants from unexpected significant price increases.
	 *
	 * @param {RateWithParent} selectedRate - The rate that was previously selected, including its parent rate if applicable.
	 * @return {RateWithParent | false} The rate and its parent rate if found, or false if not found.
	 */
	const matchAndSelectRate = useCallback(
		( selectedRate: RateWithParent ): RateWithParent | false => {
			const allRatesForType: RatesByCarrier | undefined = select(
				labelPurchaseStore
			).getRatesForShipment(
				currentShipmentId,
				snakeCase(
					selectedRate.rate.type ?? LABEL_RATE_TYPE.DEFAULT
				) as RecordValues< typeof LABEL_RATE_TYPE >
			);

			// Match by serviceId first.
			const foundRate = allRatesForType?.[
				selectedRate.rate.carrierId as Carrier
			]?.find(
				( rate ) => rate.serviceId === selectedRate.rate.serviceId
			);

			if ( ! foundRate ) {
				return false;
			}

			// Check if the price change exceeds the threshold.
			// This protects merchants from unexpected significant price increases
			// while still allowing minor fluctuations.
			const priceChange = Math.abs(
				foundRate.rate - selectedRate.rate.rate
			);
			if ( priceChange > RATE_MATCH_PRICE_THRESHOLD ) {
				return false;
			}

			// Default parent rate is undefined.
			let parentRate = null;

			// If the rate is not the default rate, we need to find its parent rate.
			if (
				selectedRate.parent &&
				snakeCase( selectedRate.rate.type ) !== LABEL_RATE_TYPE.DEFAULT
			) {
				const allDefaultRates: RatesByCarrier | undefined = select(
					labelPurchaseStore
				).getRatesForShipment(
					currentShipmentId,
					snakeCase(
						selectedRate.rate.type ?? LABEL_RATE_TYPE.DEFAULT
					) as RecordValues< typeof LABEL_RATE_TYPE >
				);
				parentRate = allDefaultRates?.[
					foundRate.carrierId as Carrier
				]?.find(
					( rate ) =>
						rate.serviceId === selectedRate.parent?.serviceId
				);

				// If the parent rate is not found, we don't reselect the rate.
				if ( ! parentRate ) {
					return false;
				}
			}

			selectRate( foundRate, parentRate ?? undefined );
			return {
				rate: foundRate,
				parent: parentRate,
			};
		},
		[ selectRate, currentShipmentId ]
	);

	/**
	 * Updates the rates based on the current package data
	 */
	const updateRates = useCallback(
		async ( { preserveSelection = false } = {} ) => {
			// Not updating if still fetching and to prevent a double fetch at render, or if totalWeight is 0.
			if (
				isFetching ||
				typeof availableRates === 'undefined' ||
				totalWeight === 0 ||
				! Number.isFinite( parseFloat( `${ totalWeight }` ) ) // If any error occurs, totalWeight will be a string, null or undefined, so we need to convert it to a number.
			) {
				return;
			}

			const pkg = getPackageForRequest();

			/**
			 * Excluding the boxWeight and name fields from the check boxWeight is
			 * not a mandatory field since it can be 0, and we always use totalWeight
			 * name is not a mandatory field since it's only used for custom packages
			 */
			if ( ! pkg ) {
				return;
			}
			// eslint-disable-next-line no-unused-vars
			const { name, boxWeight, isUserDefined, ...mandatoryFields } = pkg;

			// Max weight is not a mandatory field since it can be 0, and if it's 0 it won't affect isAnyFieldEmpty
			if ( 'maxWeight' in mandatoryFields ) {
				delete mandatoryFields.maxWeight;
			}

			const isAnyFieldEmpty = Object.values< string | boolean >(
				mandatoryFields
			).some( ( field ) => ! field && typeof field !== 'boolean' );
			if ( ! isAnyFieldEmpty ) {
				const selectedRateBeforeRefresh = preserveSelection
					? getSelectedRate()
					: null;

				await fetchRates( pkg );

				if ( selectedRateBeforeRefresh ) {
					matchAndSelectRate( selectedRateBeforeRefresh );
				}
			}
			// Adding isFetching to the dependencies array causes an intinite loop
			// as is being updated by fetchRates
			// eslint-disable-next-line react-hooks/exhaustive-deps
		},
		[
			availableRates,
			fetchRates,
			getPackageForRequest,
			getSelectedRate,
			isFetching,
			matchAndSelectRate,
			totalWeight,
		]
	);

	return {
		selectedRates,
		selectRates,
		selectRate,
		getSelectedRate,
		removeSelectedRate,
		isFetching,
		userRateFetchNonce,
		updateRates,
		fetchRates,
		resetRates,
		sortRates,
		errors,
		setErrors,
		matchAndSelectRate,
		availableRates,
		preselectRateBasedOnLastSelections,
		selectedRateOptions,
		selectRateOption,
		getSelectedRateOptions,
	};
}
