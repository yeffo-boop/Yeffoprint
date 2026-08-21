/**
 * Types for the batch progress / results modal.
 *
 * The wire shape mirrors the PHP batch purchase controller
 * (`LabelPurchaseRESTController::purchase_labels_batch`):
 * results are keyed `order_<id>` and each entry is either
 * `{ labels: [...], success: true }` or
 * `{ error: { code, message } }`.
 */

import type { LabelPrintFulfillmentRef } from 'types';
import type {
	BatchPurchaseEntry,
	BatchPurchaseErrorEntry,
	BatchPurchaseSuccessEntry,
} from 'data/bulk-labels';

export type {
	BatchPurchaseEntry,
	BatchPurchaseResponse,
	BatchPurchaseErrorEntry,
	BatchPurchaseSuccessEntry,
} from 'data/bulk-labels';

/**
 * Error code used when the modal is closed before every order in the
 * batch settles. The pending rows are promoted to failures with this
 * code so the merchant can retry them.
 */
export const BATCH_INTERRUPTED_ERROR_CODE = 'batch_interrupted';

/**
 * Error code used when the batch dispatch itself fails (network drop,
 * 5xx). Every still-pending row is promoted to a failure with this
 * code so the merchant can dismiss the modal and retry.
 */
export const BATCH_TRANSPORT_ERROR_CODE = 'batch_transport_failed';

/**
 * Client-only failure code for the case where the server reports
 * success but the response is missing one or more `label_id`s, or the
 * `labels` field is not an array. Surfaces a partial-success failure
 * so the merchant doesn't silently get fewer labels than orders.
 */
export const PARTIAL_LABEL_LOSS_ERROR_CODE = 'partial_label_loss';

/**
 * Client-only failure code used when the per-order entry has neither
 * `success` nor `error` (schema drift).
 */
export const UNKNOWN_RESPONSE_ERROR_CODE = 'unknown_response';

export interface SucceededOrder {
	order_id: number;
	order_number?: string;
	customer_name?: string;
	label_ids: number[];
	label_refs: LabelPrintFulfillmentRef[];
	cost: number;
}

export interface FailedOrder {
	order_id: number;
	order_number?: string;
	customer_name?: string;
	error_code: BatchPurchaseErrorCode;
	error_message: string;
}

export type OrderProgressStatus = 'pending' | 'succeeded' | 'failed';

/**
 * Shared identity columns every per-order row carries, regardless of
 * outcome. Used by the `OrderRow` discriminated union so the views
 * read the merchant-facing label off any row without branching.
 */
interface OrderRowIdentity {
	order_id: number;
	order_number?: string;
	customer_name?: string;
}

/**
 * A row that has not yet settled. Holds only the identity columns so
 * the progress view can render an entry per order while the batch is
 * in flight.
 */
export interface PendingRow extends OrderRowIdentity {
	status: 'pending';
}

/**
 * A row that has settled successfully. Carries the label IDs and cost
 * needed by the results-view print flow.
 */
export interface SucceededRow extends OrderRowIdentity {
	status: 'succeeded';
	label_ids: number[];
	label_refs: LabelPrintFulfillmentRef[];
	cost: number;
}

/**
 * A row that has settled with a failure. Carries the error code and
 * message needed by the results-view "Fix and retry" affordance.
 */
export interface FailedRow extends OrderRowIdentity {
	status: 'failed';
	error_code: BatchPurchaseErrorCode;
	error_message: string;
}

/**
 * Unified per-order row. The previous shape kept `succeeded`, `failed`,
 * and `progress` as three parallel arrays that the reducer hand-aligned
 * on each settle; the unified row makes drift impossible by design.
 * Consumers derive succeeded/failed lists with `filter` on demand.
 */
export type OrderRow = PendingRow | SucceededRow | FailedRow;

/**
 * Row constrained to settled outcomes. Used in the `results` arm of
 * `BatchPurchaseState` so consumers can't render the results view while
 * a row is still pending.
 */
export type SettledRow = SucceededRow | FailedRow;

/**
 * The batch state is a discriminated union so consumers can't end up
 * rendering the results view while a row is still pending, or running
 * the dispatch effect after results have arrived.
 */
export type BatchPurchaseState =
	| {
			phase: 'progress';
			rows: OrderRow[];
	  }
	| {
			phase: 'results';
			rows: SettledRow[];
	  };

export type BatchPurchasePhase = BatchPurchaseState[ 'phase' ];

/**
 * Known client-emitted error codes. The server's set stays open
 * (anything the controller returns), so the union mixes both. New
 * client-only codes should be added here so they're documented in the
 * type.
 */
export type BatchPurchaseErrorCode =
	| typeof BATCH_INTERRUPTED_ERROR_CODE
	| typeof BATCH_TRANSPORT_ERROR_CODE
	| typeof PARTIAL_LABEL_LOSS_ERROR_CODE
	| typeof UNKNOWN_RESPONSE_ERROR_CODE
	| ( string & {} );

export const isBatchSuccessEntry = (
	entry: BatchPurchaseEntry
): entry is BatchPurchaseSuccessEntry =>
	'success' in entry &&
	entry.success === true &&
	Array.isArray( ( entry as BatchPurchaseSuccessEntry ).labels );

export const isBatchErrorEntry = (
	entry: BatchPurchaseEntry
): entry is BatchPurchaseErrorEntry => {
	// `'error' in entry` alone would accept `{ error: null }` or
	// `{ error: {} }` and downstream `parseEntry` would dereference
	// `entry.error.code` / `entry.error.message` and crash. Validate
	// that `error` is a non-null object carrying string `code` and
	// `message` so malformed wire shapes fall through to the
	// `unknown_response` branch instead.
	if ( ! ( 'error' in entry ) ) {
		return false;
	}
	const error = ( entry as { error?: unknown } ).error;
	if ( error === null || typeof error !== 'object' ) {
		return false;
	}
	const { code, message } = error as {
		code?: unknown;
		message?: unknown;
	};
	return typeof code === 'string' && typeof message === 'string';
};
