/**
 * Type definitions for the bulk-labels data package.
 *
 * - `OrderShippingContextRecord` is one record returned by
 *   GET /wcshipping/v1/orders/shipping-context. Records for orders
 *   that couldn't be loaded carry only `order_id` + `error`;
 *   everything else is optional so callers can branch on `error`.
 * - `BulkPurchaseOrder` is what the modal actually renders: a record
 *   plus the placeholder fields (service, cost, status, note)
 *   that WOOSHIP-2133 will fill in for real.
 * - `PackageDisplay`, `BatchSummary`, `AddressGrouping` are small
 *   value types the display helpers and hooks return.
 */

import type {
	LabelRequestPackages,
	LocationResponse,
	Package,
	Rate,
} from 'types';

/**
 * The package selected for the order, sourced from the shipping
 * method's `wcshipping_packages` meta. Reuses the shared `Package`
 * shape for `id` and `name`, then layers on the snake-cased fields
 * the order meta actually carries (numeric dimensions and weight,
 * plus the box id that links back to the package definition).
 */
export type OrderSelectedPackage = Pick< Package, 'id' | 'name' > & {
	box_id: string;
	length: number;
	width: number;
	height: number;
	weight: number;
};

export interface OrderShippingContextRecord {
	order_id: number;
	order_number?: string;
	customer_name?: string;
	destination?: Partial< LocationResponse >;
	item_count?: number;
	total_weight?: number;
	weight_unit?: string;
	package?: OrderSelectedPackage | null;
	error: {
		code: string;
		message: string;
	} | null;
}

/**
 * Display-ready package summary for the table cell. Sourced from the
 * real `package` on the order when available, falls back to a
 * placeholder so the row still renders before the merchant has packed
 * the order.
 */
export interface PackageDisplay {
	name: string;
	dimensions: string;
	weight: number;
	weight_unit: string;
	/**
	 * True when the box-packer returned a definitive non-`fit` result
	 * (no_packages, needs_split, missing_dimensions, …) so the cell must
	 * not fall back to a placeholder box — there's genuinely no package.
	 */
	unavailable?: boolean;
	/**
	 * Key of the package currently selected for this order (matches an
	 * `AssignablePackage.key`), so the package dropdown can pre-select it.
	 * Set for an auto-assigned `fit` or a manual pick; absent when no
	 * package is assigned yet.
	 */
	selected_key?: string;
}

/**
 * A shipping-context record decorated with the table-display fields
 * that the modal renders.
 */
export interface BulkPurchaseOrder extends OrderShippingContextRecord {
	package_display: PackageDisplay;
	service: {
		carrier: string;
		name: string;
		estimate: string;
	};
	cost: number;
	cost_savings: number;
	status: 'ready' | 'needs_fix';
	note: {
		type: 'warning' | 'info' | null;
		text: string;
	};
	auto_assigned?: AutoAssignedPackageResult;
}

/**
 * Package payload sent to the batch purchase endpoint. It is built from
 * the same effective package that was used for the batch rate quote.
 */
export type BulkRequestPackage = Pick<
	LabelRequestPackages,
	'id' | 'box_id' | 'length' | 'width' | 'height' | 'weight' | 'is_letter'
> &
	Partial< Pick< LabelRequestPackages, 'products' > >;

/**
 * The rate selected for a given order when the batch purchase is
 * started. Kept separate from `OrderRate` because the purchase endpoint
 * consumes snake-cased IDs in the package payload.
 */
export type SelectedBatchRate = Pick<
	LabelRequestPackages,
	'rate_id' | 'service_id' | 'carrier_id' | 'service_name' | 'shipment_id'
> & {
	rate: number;
	retail_rate: number;
};

/**
 * A display order after the merchant clicks Purchase. These fields are
 * required by the batch purchase request, so only rows with all three
 * are handed to the progress modal.
 */
export interface PurchasableBulkPurchaseOrder extends BulkPurchaseOrder {
	selected_rate: SelectedBatchRate;
	request_package: BulkRequestPackage;
	purchase_destination: Record< string, unknown >;
}

export type BatchPurchaseShipment = Record< string, unknown >;

export interface BatchPurchaseSuccessEntry {
	labels: {
		label_id: number;
		fulfillment_id?: number;
		rate?: number;
		[ key: string ]: unknown;
	}[];
	success: true;
}

export interface BatchPurchaseErrorEntry {
	error: {
		code: string;
		message: string;
	};
}

export type BatchPurchaseEntry =
	| BatchPurchaseSuccessEntry
	| BatchPurchaseErrorEntry;

/**
 * Top-level response from `POST /wcshipping/v1/label/purchase/batch`.
 * Keys are `order_<id>` (or `invalid_order_<index>` for malformed
 * rows). The string prefix keeps the JSON object-shaped regardless of
 * the numeric `order_id` so JS consumers don't need numeric coercion.
 */
export type BatchPurchaseResponse = Record< string, BatchPurchaseEntry >;

/**
 * Per-order result returned by POST /wcshipping/v1/label/auto-assign-packages.
 *
 * The endpoint is keyed by order_id; each entry carries a status plus
 * status-dependent fields. `fit` entries name a single package that holds
 * every shippable item, and may also carry the predefined-package
 * `service_id` so the rate-quote step can default the carrier. Non-fit
 * statuses carry an operator-facing `reason` instead.
 */
export type AutoAssignedPackageStatus =
	| 'fit'
	| 'needs_split'
	| 'missing_dimensions'
	| 'no_packages'
	| 'no_shippable_items'
	| 'error';

export interface AutoAssignedPackageResult {
	status: AutoAssignedPackageStatus;
	package_id?: string;
	package_name?: string;
	service_id?: string;
	reason?: string;
}

export type AutoAssignedPackagesMap = Record<
	number,
	AutoAssignedPackageResult
>;

/**
 * A single box-type package the merchant can manually assign to an order
 * whose auto-assignment didn't produce a `fit`. Built from
 * GET /wcshipping/v1/packages — saved custom boxes plus the merchant's
 * starred predefined boxes resolved against the predefined schema.
 */
export interface AssignablePackage {
	/** Stable, unique select value (e.g. `custom:<id>` / `predef:<carrier>:<id>`). */
	key: string;
	/** Underlying package id as the rate/label flow knows it. */
	package_id: string;
	/** Carrier/service id for predefined boxes; null for custom boxes. */
	service_id: string | null;
	name: string;
	/** Pre-formatted "L×W×H" for the package cell; empty when unknown. */
	dimensions: string;
	/** Numeric outer dimensions, in the store's dimension unit (0 when unknown). */
	length: number;
	width: number;
	height: number;
	/** Box tare weight, in the store's weight unit. */
	weight: number;
	/** Whether the box is an envelope/letter — always false for the MVP box filter. */
	is_letter: boolean;
}

/**
 * Per-order manual package override the merchant picked in the modal.
 * Keyed by order_id; takes precedence over the auto-assign suggestion.
 */
export type ManualPackageSelections = Record< number, AssignablePackage >;

/**
 * One quoted shipping service for an order. Reuses the shared `Rate`
 * shape (camelCase) — the bulk Service dropdown only needs this subset,
 * so no parallel rate type is introduced.
 *
 * The ship-from `origin` for the batch rate request is a
 * `Partial<LocationResponse>` (same snake-cased shape as `destination`),
 * so no separate origin type is defined here either.
 */
export type OrderRate = Pick<
	Rate,
	| 'rateId'
	| 'serviceId'
	| 'carrierId'
	| 'shipmentId'
	| 'title'
	| 'rate'
	| 'retailRate'
	| 'deliveryDays'
>;

/** order_id → quoted services (empty array when the order couldn't be quoted). */
export type OrderRatesMap = Record< number, OrderRate[] >;

/** order_id → operator-facing rate error message. */
export type OrderRateErrors = Record< number, string >;

/**
 * "Apply to all" service strategy. The two `cheapest`/`fastest`
 * sentinels resolve per-order against that order's quoted rates; any
 * other value is matched against rate titles/service ids.
 */
export const SERVICE_CHEAPEST = '__cheapest__';
export const SERVICE_FASTEST = '__fastest__';

/**
 * Per-order manual service override (rate id) the merchant picked in a
 * row dropdown; takes precedence over the apply-to-all strategy.
 */
export type ManualServiceSelections = Record< number, string >;

/**
 * Aggregated batch totals for the right-hand sidebar.
 */
export interface BatchSummary {
	readyCount: number;
	needsFixCount: number;
	subtotal: number;
	discount: number;
	total: number;
}

/**
 * The largest set of orders shipping to the same destination, used
 * for the "combine into one package?" suggestion. `null` when no
 * group of 2+ exists.
 */
export interface AddressGrouping {
	customerName: string;
	cityState: string;
	orderIds: number[];
}
