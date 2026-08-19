import { mapKeys, mapValues } from 'lodash';
import {
	camelCasePackageResponse,
	createReducer,
	getConfig,
	getCustomsInformation,
	getLabelDestinations,
	getLabelOrigins,
	getPurchasedLabels,
	getSelectedHazmat,
	getSelectedRateOptions,
	getSelectedRates,
	groupRatesByCarrier,
} from 'utils';
import {
	ORDER_STATUS_UPDATE_FAILED,
	ORDER_STATUS_UPDATED,
	RATES_FETCHED,
	RATES_RESET,
	STATE_RESET,
} from './action-types';
import { LabelPurchaseState } from '../types';
import {
	LabelPurchaseActions,
	LabelPurchaseSuccessAction,
	LabelStatusResolvedAction,
	OrderStatusUpdatedAction,
	OrderStatusUpdatedFailedAction,
	PackageUpdateAction,
	PackageUpdateFailedAction,
	RatesFetchedAction,
	StageLabelsNewShipmentIdsAction,
} from './types.d';
import {
	PACKAGES_UPDATE,
	PACKAGES_UPDATE_ERROR,
} from './packages/action-types';
import {
	LABEL_PURCHASE_SUCCESS,
	LABEL_STAGE_NEW_SHIPMENT_IDS,
	LABEL_STATUS_RESOLVED,
} from './label/action-types';
import { SelectedRates } from 'types';
import { LABEL_PURCHASE_STATUS } from '../constants';

const {
	packagesSettings: { packages },
} = getConfig();

const defaultState = (): LabelPurchaseState =>
	( {
		packages: {
			...camelCasePackageResponse( packages ),
			errors: {},
		},
		rates: {},
		labels: getPurchasedLabels(),
		selectedRates: getSelectedRates(),
		selectedHazmatConfig: getSelectedHazmat(),
		selectedDestinations: getLabelDestinations(),
		selectedOrigins: getLabelOrigins(),
		purchaseAPIErrors: {},
		customsInformation: getCustomsInformation(),
		selectedRateOptions: getSelectedRateOptions(),
	} ) as const;

export const labelPurchaseReducer = createReducer( defaultState() )
	.on( PACKAGES_UPDATE, ( state, { payload }: PackageUpdateAction ) => ( {
		...state,
		packages: {
			...state.packages,
			...payload,
		},
	} ) )
	.on(
		PACKAGES_UPDATE_ERROR,
		( state, { payload }: PackageUpdateFailedAction ) => {
			return {
				...state,
				packages: {
					...state.packages,
					errors: payload,
				},
			};
		}
	)
	.on( RATES_FETCHED, ( state, { payload }: RatesFetchedAction ) => ( {
		...state,
		rates: {
			...state.rates,
			...groupRatesByCarrier( payload ),
		},
	} ) )
	.on( RATES_RESET, ( state ) => ( {
		...state,
		rates: undefined,
	} ) )
	.on( STATE_RESET, () => ( { ...defaultState() } ) )
	.on(
		LABEL_STATUS_RESOLVED,
		( state, { payload }: LabelStatusResolvedAction ) => ( {
			...state,
			labels: payload?.labelId
				? mapValues( state.labels, ( shipmentLabels ) => {
						return shipmentLabels.map( ( label ) => {
							if ( label.labelId === payload.labelId ) {
								return payload;
							}
							return label;
						} );
				  } )
				: state.labels,
			// If the label is refunded or status is unknown or purchase_error, we need to remove the selected rate from the selectedRates object
			selectedRates: Object.entries( state.selectedRates ?? {} ).reduce(
				( acc, [ key, rate ] ) => {
					// Find the shipment index (0, 1, 2, etc.) using label.labelId
					const shipmentIndex =
						( payload?.refund ??
							[
								LABEL_PURCHASE_STATUS.PURCHASE_ERROR,
								LABEL_PURCHASE_STATUS.UNKNOWN,
							].includes( payload?.status ?? '' ) ) &&
						typeof state.labels === 'object' &&
						state.labels
							? Object.keys( state.labels ).find(
									( shipmentId ) => {
										return state.labels![ shipmentId ].some(
											( label ) =>
												label.labelId ===
												payload?.labelId
										);
									}
							  )
							: null;

					if (
						shipmentIndex &&
						key === `shipment_${ shipmentIndex }`
					) {
						return acc;
					}

					// Safe to cast as keyof SelectedRates because we know the key is in the object
					acc[ key as keyof SelectedRates ] = rate;
					return acc;
				},
				{} as SelectedRates
			),
		} )
	)
	.on(
		LABEL_PURCHASE_SUCCESS,
		(
			state,
			{
				payload: {
					label,
					selectedRates,
					selectedHazmat,
					selectedDestinations,
					selectedOrigins,
				},
			}: LabelPurchaseSuccessAction
		) => {
			// Note: Package dimensions sync to global config is now handled
			// in the action (see label/actions.ts) to keep reducer pure
			return {
				...state,
				labels: {
					...state.labels,
					...label,
				},
				selectedRates: {
					...state.selectedRates,
					...selectedRates,
				},
				selectedHazmatConfig: {
					...state.selectedHazmatConfig,
					...selectedHazmat,
				},
				selectedDestinations: {
					...state.selectedDestinations,
					...selectedDestinations,
				},
				selectedOrigins: {
					...state.selectedOrigins,
					...selectedOrigins,
				},
			};
		}
	)
	.on(
		LABEL_STAGE_NEW_SHIPMENT_IDS,
		(
			state,
			{ payload: shipmentIdsToUpdate }: StageLabelsNewShipmentIdsAction
		) => ( {
			...state,
			labels: mapKeys(
				mapValues( state.labels, ( label, key ) =>
					key === shipmentIdsToUpdate[ key ]
						? {
								...label,
								id: shipmentIdsToUpdate[ key ],
						  }
						: label
				),
				( _, key ) => shipmentIdsToUpdate[ key ] ?? key
			),
		} )
	)
	.on(
		ORDER_STATUS_UPDATED,
		( state, { payload }: OrderStatusUpdatedAction ) => {
			return {
				...state,
				order: {
					...state.order,
					status: payload.status,
				},
			};
		}
	)
	.on(
		ORDER_STATUS_UPDATE_FAILED,
		( state, { payload }: OrderStatusUpdatedFailedAction ) => {
			return {
				...state,
				order: {
					...state.order,
					status: '',
					error: payload.error,
				},
			};
		}
	)
	.bind< LabelPurchaseActions >();
