import type { PurchasableBulkPurchaseOrder } from 'data/bulk-labels';

/**
 * Props for the BulkPurchaseModal component.
 *
 * Record-level types (`OrderShippingContextRecord`, `OrderSelectedPackage`,
 * `PurchasableBulkPurchaseOrder`) live in the data package. See
 * `client/data/bulk-labels/`.
 */
export interface BulkPurchaseModalProps {
	orderIds: number[];
	onClose: () => void;
	/**
	 * Called when the merchant clicks "Purchase labels" with the
	 * eligible (ready) orders for the batch. The entrypoint hands these
	 * off to the batch-progress modal. Leaving this undefined keeps the
	 * rate-review modal a no-op for callers that have not yet wired the
	 * progress flow.
	 */
	onCreateLabels?: (
		orders: PurchasableBulkPurchaseOrder[],
		origin: Record< string, unknown >
	) => void;
}
