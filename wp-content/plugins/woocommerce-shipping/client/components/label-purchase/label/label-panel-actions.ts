import type {
	Label,
	RateWithParent,
	ReturnShipmentInfo,
	ShipmentItem,
} from 'types';
import { canRefundLabel } from 'utils';
import { pickupUrls, trackingUrls } from './constants';

export type LabelPanelAction =
	| 'copy_tracking'
	| 'track_shipment'
	| 'schedule_pickup'
	| 'request_refund'
	| 'return_label'
	| 'print_customs_form';

interface ReturnableShipmentItemsArgs {
	shipments: Record< string, ShipmentItem[] >;
	currentShipmentId: string;
	returnShipments?: Record< string, ReturnShipmentInfo >;
	selections: Record< string, ShipmentItem[] >;
	getShipmentLabel: ( shipmentId?: string ) => Label | undefined;
}

interface AvailableLabelPanelActionsArgs extends ReturnableShipmentItemsArgs {
	label?: Label;
	selectedRate?: RateWithParent | null;
	isReturn: boolean;
	isDomesticShipment: boolean;
	isRefundDisabled: boolean;
}

export const getLabelTrackingUrl = ( label?: Label ): string | null => {
	if ( ! label?.carrierId || ! label.tracking ) {
		return null;
	}

	return trackingUrls[ label.carrierId ]?.( label.tracking ) ?? null;
};

export const getLabelPickupUrl = ( label?: Label ): string | null => {
	if ( ! label?.carrierId ) {
		return null;
	}

	return pickupUrls[ label.carrierId ] ?? null;
};

export const canRequestLabelRefund = (
	label?: Label,
	selectedRate?: RateWithParent | null
): boolean => {
	const caveats = selectedRate?.rate.caveats ?? [];
	return ! caveats.includes( 'non-refundable' ) && canRefundLabel( label );
};

export const getReturnableShipmentItems = ( {
	shipments,
	currentShipmentId,
	returnShipments,
	selections,
	getShipmentLabel,
}: ReturnableShipmentItemsArgs ): ShipmentItem[] => {
	const returnedItemIds = new Set< number | string >();

	Object.entries( returnShipments ?? {} ).forEach(
		( [ shipmentId, returnInfo ] ) => {
			if (
				returnInfo.parentShipmentId !== currentShipmentId ||
				! returnInfo.isReturn
			) {
				return;
			}

			( selections[ shipmentId ] ?? [] ).forEach( ( item ) => {
				if ( item?.id ) {
					returnedItemIds.add( item.id );
				}
			} );
		}
	);

	Object.keys( shipments ).forEach( ( shipmentId ) => {
		if ( shipmentId === currentShipmentId ) {
			return;
		}

		const shipmentLabel = getShipmentLabel( shipmentId );
		if (
			! shipmentLabel?.isReturn ||
			shipmentLabel.parentShipmentId !== currentShipmentId
		) {
			return;
		}

		shipments[ shipmentId ].forEach( ( item ) => {
			if ( item?.id ) {
				returnedItemIds.add( item.id );
			}
		} );
	} );

	return ( shipments[ currentShipmentId ] ?? [] ).filter(
		( item ) => item !== null && ! returnedItemIds.has( item.id )
	);
};

export const getAvailableLabelPanelActions = ( {
	label,
	selectedRate,
	isReturn,
	isDomesticShipment,
	isRefundDisabled,
	shipments,
	currentShipmentId,
	returnShipments,
	selections,
	getShipmentLabel,
}: AvailableLabelPanelActionsArgs ): LabelPanelAction[] => {
	const actions: LabelPanelAction[] = [];

	if ( label?.tracking ) {
		actions.push( 'copy_tracking' );
	}
	if ( getLabelTrackingUrl( label ) ) {
		actions.push( 'track_shipment' );
	}
	if ( ! isReturn && getLabelPickupUrl( label ) ) {
		actions.push( 'schedule_pickup' );
	}
	if ( ! isRefundDisabled && canRequestLabelRefund( label, selectedRate ) ) {
		actions.push( 'request_refund' );
	}
	if (
		! isReturn &&
		isDomesticShipment &&
		getReturnableShipmentItems( {
			shipments,
			currentShipmentId,
			returnShipments,
			selections,
			getShipmentLabel,
		} ).length > 0
	) {
		actions.push( 'return_label' );
	}
	if ( label?.commercialInvoiceUrl ) {
		actions.push( 'print_customs_form' );
	}

	return actions;
};
