import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { isEmpty, isEqual } from 'lodash';
import {
	CustomsItem,
	CustomsState,
	RequestPackage,
	RequestPackageWithCustoms,
	ShipmentItem,
} from 'types';
import { select, useSelect } from '@wordpress/data';
import type { FormErrors } from '@woocommerce/components';
import { contentTypes } from '../customs/constants';
import { normalizeITN } from '../customs/validators';
import {
	isCountryInEU,
	isCustomsRequired,
	isHSTariffNumberValid,
	sanitizeHSTariffNumber,
	getAccountSettings,
} from 'utils';
import { useShipmentState } from './shipment';
import { labelPurchaseStore } from 'data/label-purchase';

const getInitialShipmentCustomsState = < T >(
	items: T,
	isReturnToSender = false
) => ( {
	items,
	contentsType: contentTypes[ 0 ].value,
	restrictionType: 'none',
	isReturnToSender,
	itn: '',
} );

export function useCustomsState(
	currentShipmentId: string,
	shipments: Record< string, ShipmentItem[] >,
	selections: Record< string, ShipmentItem[] >,
	getShipmentItems: ReturnType<
		typeof useShipmentState
	>[ 'getShipmentItems' ],
	getSelectionItems: ReturnType<
		typeof useShipmentState
	>[ 'getSelectionItems' ],
	getShipmentOrigin: ReturnType<
		typeof useShipmentState
	>[ 'getShipmentOrigin' ],
	getShipmentDestination: ReturnType<
		typeof useShipmentState
	>[ 'getShipmentDestination' ]
) {
	const origin = getShipmentOrigin();
	const destination = getShipmentDestination();

	const storedCustomsInformationForShipment = useSelect(
		( s ) =>
			s( labelPurchaseStore ).getCustomsInformation( currentShipmentId ),
		[ currentShipmentId ]
	);

	const isCustomsNeeded = useCallback(
		() => isCustomsRequired( origin, destination ),
		[ origin, destination ]
	);

	const [ errors, setErrors ] = useState<
		FormErrors< CustomsState > & {
			items: FormErrors< CustomsItem >[];
		}
	>( {
		items: getShipmentItems()?.map( () => ( {} ) ),
	} );

	const getCustomsItems = useCallback(
		( shipmentId = currentShipmentId ) => {
			const items =
				getSelectionItems( shipmentId )?.length > 0
					? getSelectionItems( shipmentId )
					: getShipmentItems( shipmentId );

			return items?.map( ( props ) => ( {
				...props,
				description:
					props.meta?.customs_info?.description ?? props.name,
				hsTariffNumber:
					props.meta?.customs_info?.hs_tariff_number ?? '',
				originCountry:
					props.meta?.customs_info?.origin_country ?? origin.country,
			} ) );
		},
		[
			getSelectionItems,
			getShipmentItems,
			currentShipmentId,
			origin.country,
		]
	);

	const returnToSenderDefault =
		getAccountSettings()?.purchaseSettings?.return_to_sender_default ??
		false;

	const initialCustomsInfo: CustomsState =
		storedCustomsInformationForShipment ??
		getInitialShipmentCustomsState(
			getCustomsItems(),
			returnToSenderDefault
		);

	const initialCustomsState: Record<
		typeof currentShipmentId,
		CustomsState
	> = Object.keys( shipments ).reduce(
		(
			customs: Record< typeof currentShipmentId, CustomsState >,
			id: string
		) => {
			customs[ id ] = initialCustomsInfo;
			return customs;
		},
		{}
	);
	const [ state, setState ] =
		useState< Record< typeof currentShipmentId, CustomsState > >(
			initialCustomsState
		);

	const previousStateRef = useRef( state );
	/**
	 * Make sure on shipment change, the shipment has the correct customs information
	 * - If the shipment has no customs information, set it to the default
	 * - If the shipment has customs information, set it to the stored information
	 */
	useEffect( () => {
		const allTheCustomItems = (
			previousStateRef.current
				? Object.values( previousStateRef.current )
				: Object.values( state )
		)
			.map( ( { items } ) => items )
			.flat();

		const currentShipmentCustomsInfo =
			select( labelPurchaseStore ).getCustomsInformation(
				currentShipmentId
			) ??
			getInitialShipmentCustomsState(
				getCustomsItems()?.map(
					( item ) =>
						allTheCustomItems.find(
							( i ) => i.product_id === item.product_id
						) ?? item
				),
				returnToSenderDefault
			);

		if ( isEmpty( state[ currentShipmentId ] ) ) {
			setState( ( prev ) => ( {
				...prev,
				[ currentShipmentId ]: currentShipmentCustomsInfo,
			} ) );
		}
	}, [ currentShipmentId, getCustomsItems, returnToSenderDefault, state ] );

	const getCustomsState = useCallback(
		() => state[ currentShipmentId ],
		[ state, currentShipmentId ]
	);

	/**
	 * Memoized fingerprint of the current customs state for rate invalidation.
	 * Only recomputes when the customs state for the current shipment changes.
	 */
	const customsFingerprint = useMemo( () => {
		if ( ! isCustomsNeeded() ) {
			return null;
		}
		const customs = state[ currentShipmentId ];
		if ( ! customs?.items?.length ) {
			return null;
		}
		return JSON.stringify( {
			items: customs.items.map(
				( {
					price,
					weight,
					quantity,
					description,
					hsTariffNumber,
					originCountry,
				} ) => ( {
					price,
					weight,
					quantity,
					description,
					hsTariffNumber,
					originCountry,
				} )
			),
			contentsType: customs.contentsType,
			contentsExplanation: customs.contentsExplanation,
			restrictionType: customs.restrictionType,
			restrictionComments: customs.restrictionComments,
			itn: customs.itn,
			isReturnToSender: customs.isReturnToSender,
		} );
	}, [ isCustomsNeeded, state, currentShipmentId ] );

	const setCustomsState = useCallback(
		( newState: CustomsState ) => {
			setState( ( prev ) => ( {
				...prev,
				[ currentShipmentId ]: newState,
			} ) );
		},
		[ currentShipmentId ]
	);

	/**
	 * Reset the customs errors if customs is no longer needed.
	 *
	 * Make sure that if a destination changes, but we're no longer showing the customs form,
	 * we reset the errors to avoid showing errors from a previous destination.
	 * The reason why we do not have to do something similar when the form is present is because
	 * it will re-validate the customs state when displayed.
	 */
	useEffect( () => {
		if ( ! isCustomsNeeded() ) {
			const newErrors = {
				items: getShipmentItems()?.map( () => ( {} ) ),
			};

			setErrors( ( prevErrors ) => {
				if ( ! isEqual( prevErrors, newErrors ) ) {
					return newErrors;
				}
				return prevErrors;
			} );
		}
	}, [ getShipmentItems, isCustomsNeeded ] );

	const maybeApplyCustomsToPackage = useCallback(
		< T = RequestPackage >(
			pkg: T
		): RequestPackageWithCustoms< T > | T => {
			if ( ! isCustomsNeeded() ) {
				return pkg;
			}
			const {
				contentsType: contents_type,
				contentsExplanation: contents_explanation,
				restrictionType: restriction_type,
				restrictionComments: restriction_comments,
				isReturnToSender,
				itn,
				items,
			} = getCustomsState();

			return {
				...pkg,
				contents_type,
				...( contents_type === 'other'
					? { contents_explanation }
					: {} ),
				restriction_type,
				...( restriction_type === 'other'
					? { restriction_comments }
					: {} ),
				non_delivery_option: isReturnToSender ? 'return' : 'abandon',
				itn: normalizeITN( itn ),
				items: items.map(
					( {
						description,
						quantity,
						weight,
						hsTariffNumber,
						originCountry: origin_country,
						product_id,
						price,
					} ) => ( {
						description,
						quantity,
						weight: parseFloat( weight ),
						hs_tariff_number: sanitizeHSTariffNumber(
							hsTariffNumber ?? ''
						),
						origin_country,
						product_id,
						value: parseFloat( price ),
					} )
				),
			};
		},
		[ getCustomsState, isCustomsNeeded ]
	);

	const isHSTariffNumberRequired = useCallback( () => {
		const destinationAddress = destination;
		return destinationAddress
			? isCountryInEU( destinationAddress.country )
			: false;
	}, [ destination ] );

	const hasErrors = useCallback( () => {
		const { items, ...rest } = errors;

		if ( isHSTariffNumberRequired() ) {
			const customsState = getCustomsState();
			const customItems = customsState ? customsState.items : [];
			const hasInvalidHsTariff = customItems.some(
				( { hsTariffNumber } ) =>
					! isHSTariffNumberValid( hsTariffNumber )
			);
			if ( hasInvalidHsTariff ) {
				return true;
			}
		}
		return (
			Object.values( rest ).length ||
			items.some( ( i ) => Object.values( i ).length )
		);
	}, [ errors, getCustomsState, isHSTariffNumberRequired ] );

	/**
	 * Update the customs state based on the shipment items.
	 * This is to be called when the shipment items change.
	 */
	const updateCustomsItems = () => {
		previousStateRef.current = state; // Store previous state reference for comparison

		// Flatten the customs items from all shipments to avoid redundant iterations
		const allCustomItems = Object.values( state ).flatMap(
			( { items } ) => items
		);

		// Reduce over the state and update the customs items for each shipment
		const newState = Object.entries( state ).reduce(
			( acc, [ shipmentId, customsState ] ) => {
				// Get the customs items for the current shipmentId
				const customsItems = getCustomsItems( shipmentId );

				// If no customs items, return the accumulator as-is (shipment not eligible for customs)
				if ( ! customsItems ) {
					return acc;
				}

				// Map over the customs items and merge with existing items if found
				const updatedItems = customsItems.map( ( customsItem ) => {
					// Try to find the customs item in the current shipment or across all shipments
					const existingItem =
						customsState.items.find(
							( item ) =>
								item.product_id === customsItem.product_id
						) ??
						allCustomItems.find(
							( item ) =>
								item.product_id === customsItem.product_id
						);

					// Merge customs item with existing data (if any), otherwise use new customs item
					return {
						...customsItem,
						...( existingItem ?? {} ), // Retain existing values if present
					};
				} );

				// Return updated state for this shipment
				return {
					...acc,
					[ shipmentId ]: {
						...customsState,
						items: updatedItems,
					},
				};
			},
			{} as typeof state
		);

		// Update the state with the new customs data
		setState( newState );
	};

	const updateCustomsItemsBasedOnSelections = useCallback(
		( currentState: Record< string, CustomsState > ) => {
			// Get selections for current shipment
			const shipmentSelections = selections[ currentShipmentId ];

			// Exit early if no selections exist
			if ( ! shipmentSelections?.length ) {
				return currentState;
			}

			// Get existing customs state or initialize new items
			const shipmentCustomsState = currentState[ currentShipmentId ];
			const customsItems = shipmentCustomsState?.items?.length
				? shipmentCustomsState.items
				: getCustomsItems();

			// Filter customs items based on selections
			const updatedItems = customsItems
				// Only keep items that exist in selections
				.filter( ( item ) =>
					shipmentSelections.some(
						( selection ) =>
							selection.product_id === item.product_id
					)
				)
				// Update quantities based on selections
				.map( ( item ) => {
					const itemQuantity = shipmentSelections
						.filter(
							( selection ) =>
								selection.product_id === item.product_id
						)
						.reduce(
							( total, { quantity } ) =>
								total + ( quantity ?? 1 ),
							0
						);

					return {
						...item,
						quantity: itemQuantity,
					};
				} ) as CustomsItem[];

			// Add missing items from shipmentSelections
			const missingItems = shipmentSelections.filter(
				( selection ) =>
					! updatedItems.some(
						( updatedItem ) =>
							updatedItem.product_id === selection.product_id
					)
			);

			// Return updated state
			return {
				...currentState,
				[ currentShipmentId ]: {
					...shipmentCustomsState,
					items: [
						...updatedItems,
						...getCustomsItems().filter( ( item ) =>
							missingItems.find(
								( missingItem ) =>
									missingItem.product_id === item.product_id
							)
						),
					],
				},
			};
		},
		[ currentShipmentId, getCustomsItems, selections ]
	);

	useEffect( () => {
		setState( updateCustomsItemsBasedOnSelections );
	}, [ updateCustomsItemsBasedOnSelections ] );

	return {
		getCustomsState,
		customsFingerprint,
		setCustomsState,
		maybeApplyCustomsToPackage,
		hasErrors,
		setErrors,
		isCustomsNeeded,
		isHSTariffNumberRequired,
		updateCustomsItems,
	};
}
