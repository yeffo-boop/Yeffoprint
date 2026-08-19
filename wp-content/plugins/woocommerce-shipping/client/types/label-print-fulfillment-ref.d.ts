import { Label } from './label';

/**
 * Fulfillment-backed label reference used by bulk print flows.
 *
 * The print endpoint still sends label IDs to Connect Server, but bulk
 * callers must carry the fulfillment ID that owns each label so the plugin
 * can resolve and validate the request against the fulfillment entity first.
 */
export interface LabelPrintFulfillmentRef {
	label_id: Label[ 'labelId' ];
	fulfillment_id: number;
}
