import { __ } from '@wordpress/i18n';
import * as Sentry from '@sentry/react';
import type { BulkPurchaseOrder } from 'data/bulk-labels';
import {
	BATCH_INTERRUPTED_ERROR_CODE,
	BATCH_TRANSPORT_ERROR_CODE,
	BatchPurchaseEntry,
	BatchPurchaseErrorCode,
	FailedRow,
	OrderRow,
	PARTIAL_LABEL_LOSS_ERROR_CODE,
	PendingRow,
	SucceededRow,
	UNKNOWN_RESPONSE_ERROR_CODE,
	isBatchErrorEntry,
	isBatchSuccessEntry,
} from './types';

/**
 * Render an order's display label, preferring the merchant-facing
 * `order_number` (e.g. `WC-1234`) and falling back to the numeric id.
 */
export const orderLabel = ( order: {
	order_id: number;
	order_number?: string;
} ): string => `#${ order.order_number ?? String( order.order_id ) }`;

/**
 * Friendly messages for the small set of batch error codes the UI
 * knows about. Anything else falls through to the server-supplied
 * `error_message`, then to a generic fallback.
 */
const KNOWN_ERROR_MESSAGES: Record< string, string > = {
	[ BATCH_INTERRUPTED_ERROR_CODE ]: __(
		'Label creation was interrupted before this order finished. Reopen and try again.',
		'woocommerce-shipping'
	),
	[ BATCH_TRANSPORT_ERROR_CODE ]: __(
		'We could not reach the shipping service. Try again in a moment.',
		'woocommerce-shipping'
	),
	address_validation_failed: __(
		'Destination address could not be validated. Verify the recipient address and try again.',
		'woocommerce-shipping'
	),
	rate_unavailable: __(
		'The selected service is no longer available for this shipment. Pick a different rate and retry.',
		'woocommerce-shipping'
	),
	package_dimensions_invalid: __(
		'Package dimensions are missing or invalid for this carrier. Update the package and retry.',
		'woocommerce-shipping'
	),
};

export const mapErrorCodeToMessage = (
	errorCode: BatchPurchaseErrorCode | undefined
): string | undefined =>
	errorCode ? KNOWN_ERROR_MESSAGES[ errorCode ] : undefined;

/**
 * Result of detecting which WordPress admin screen the bulk-labels
 * banner is rendering on. The orders list page has two flavors:
 * the modern HPOS screen at `admin.php?page=wc-orders` and the legacy
 * posts-table screen at `edit.php?post_type=shop_order`.
 */
export type EditOrderScreen = 'hpos' | 'legacy' | 'unknown';

/**
 * Detect the orders screen we're on. Reads the `page` query arg first
 * (HPOS sets it to `wc-orders`), then falls back to the pathname for
 * the legacy posts-table screen.
 */
export const detectEditOrderScreen = (): EditOrderScreen => {
	if ( typeof window === 'undefined' ) {
		return 'unknown';
	}
	const page = new URLSearchParams( window.location.search ).get( 'page' );
	if ( page === 'wc-orders' ) {
		return 'hpos';
	}
	if ( window.location.pathname.includes( 'edit.php' ) ) {
		return 'legacy';
	}
	return 'unknown';
};

/**
 * Build the WP admin "edit order" URL. The bulk-labels banner renders
 * on both HPOS (`woocommerce_page_wc-orders`) and legacy
 * (`edit.php?post_type=shop_order`) screens, so the URL needs to vary.
 *
 * When the screen can't be detected, fall back to the LEGACY path
 * (`post.php?action=edit&post=<id>`). On a store that has only HPOS
 * enabled, the legacy URL redirects to the HPOS edit page; the
 * reverse (HPOS URL on a legacy-only store) returns a "screen not
 * found" error. The legacy fallback is therefore the safer default
 * when the screen is unknown.
 */
export const getEditOrderUrl = ( orderId: number ): string => {
	const screen = detectEditOrderScreen();
	if ( screen === 'hpos' ) {
		return `admin.php?page=wc-orders&action=edit&id=${ orderId }`;
	}
	if ( screen === 'legacy' ) {
		return `post.php?action=edit&post=${ orderId }`;
	}
	// Unknown screen. Drop a Sentry breadcrumb so the gap is visible in
	// support; production merchants do not need the noise in their
	// browser console.
	Sentry.addBreadcrumb( {
		category: 'batch-progress-modal',
		level: 'warning',
		message:
			'getEditOrderUrl: unknown screen, falling back to legacy edit URL.',
		data: {
			page:
				typeof window !== 'undefined'
					? new URLSearchParams( window.location.search ).get(
							'page'
					  )
					: null,
			pathname:
				typeof window !== 'undefined' ? window.location.pathname : null,
		},
	} );
	return `post.php?action=edit&post=${ orderId }`;
};

/**
 * Exhaustiveness check for `switch` statements that the type system
 * believes is closed. If a new case is added without updating the
 * switch, the compile-time check fires here instead of silently doing
 * nothing at runtime.
 */
export const assertNever = ( value: never ): never => {
	throw new Error(
		`Unexpected variant in exhaustive switch: ${ JSON.stringify( value ) }`
	);
};

/**
 * Convert a single per-order entry from the batch response into either
 * a `SucceededRow` or a `FailedRow` shape. Pulled out so the test
 * and the live consumer share one path.
 */
export const parseEntry = (
	order: Pick<
		BulkPurchaseOrder,
		'order_id' | 'order_number' | 'customer_name' | 'cost'
	>,
	entry: BatchPurchaseEntry
): SucceededRow | FailedRow => {
	if ( isBatchSuccessEntry( entry ) ) {
		const labelRefs = entry.labels
			.map( ( label ) => {
				if (
					typeof label.label_id !== 'number' ||
					typeof label.fulfillment_id !== 'number'
				) {
					return null;
				}
				return {
					label_id: label.label_id,
					fulfillment_id: label.fulfillment_id,
				};
			} )
			.filter(
				( ref ): ref is { label_id: number; fulfillment_id: number } =>
					ref !== null
			);
		const labelIds = labelRefs.map( ( ref ) => ref.label_id );
		// A success entry with fewer fulfillment-backed label refs than labels means
		// the server reported success but the wire shape is missing data needed to
		// prove each label belongs to a fulfillment entity. Promote to a failure so
		// the merchant doesn't print labels from an unvalidated legacy-style shape.
		if ( labelRefs.length !== entry.labels.length ) {
			Sentry.captureMessage(
				'Batch purchase success entry missing fulfillment-backed label ref',
				{
					level: 'error',
					tags: { component: 'batch-progress-modal' },
					extra: {
						order_id: order.order_id,
						expected_count: entry.labels.length,
						actual_count: labelRefs.length,
					},
				}
			);
			return {
				order_id: order.order_id,
				order_number: order.order_number,
				customer_name: order.customer_name,
				status: 'failed',
				error_code: PARTIAL_LABEL_LOSS_ERROR_CODE,
				error_message: __(
					'The shipping service returned a partial response. Try again.',
					'woocommerce-shipping'
				),
			};
		}
		// Sum label rates when the server attaches them, otherwise fall
		// back to the rate-quote cost so the merchant sees a value. A
		// breadcrumb records the fallback path so the gap is visible
		// without flooding Sentry with errors.
		const summedRate = entry.labels.reduce(
			( sum, l ) => sum + ( typeof l.rate === 'number' ? l.rate : 0 ),
			0
		);
		if ( summedRate <= 0 ) {
			Sentry.addBreadcrumb( {
				category: 'batch-progress-modal',
				level: 'info',
				message:
					'Batch purchase success entry missing rate; falling back to quoted cost.',
				data: {
					order_id: order.order_id,
					labels_count: entry.labels.length,
				},
			} );
		}
		return {
			order_id: order.order_id,
			order_number: order.order_number,
			customer_name: order.customer_name,
			status: 'succeeded',
			label_ids: labelIds,
			label_refs: labelRefs,
			cost: summedRate > 0 ? summedRate : order.cost,
		};
	}

	if ( isBatchErrorEntry( entry ) ) {
		return {
			order_id: order.order_id,
			order_number: order.order_number,
			customer_name: order.customer_name,
			status: 'failed',
			error_code: entry.error.code,
			error_message: entry.error.message,
		};
	}

	// Wire shape doesn't match either branch. Most likely schema drift.
	// Surface the order id and the raw entry shape so the schema gap is
	// visible in Sentry instead of being silently fabricated as a
	// generic failure.
	Sentry.captureMessage( 'Batch purchase entry has unknown shape', {
		level: 'error',
		tags: { component: 'batch-progress-modal' },
		extra: {
			order_id: order.order_id,
			entry_keys: Object.keys( entry ?? {} ),
		},
	} );
	return {
		order_id: order.order_id,
		order_number: order.order_number,
		customer_name: order.customer_name,
		status: 'failed',
		error_code: UNKNOWN_RESPONSE_ERROR_CODE,
		error_message: __(
			'The shipping service returned an unexpected response.',
			'woocommerce-shipping'
		),
	};
};

/**
 * Build the human-readable failure message for a failed row. Falls
 * through a small ladder: server-supplied `error_message`, then the
 * client-side map keyed by `error_code`, then a generic fallback.
 *
 * The error code stays on the row (it drives analytics, Sentry, and the
 * client-side message map), but is intentionally NOT appended to the
 * user-facing string. Raw codes like `rate_unavailable` or
 * `batch_transport_failed` are useful in support tickets and bug
 * reports, not in the merchant's inbox.
 */
export const formatFailureMessage = ( row: FailedRow ): string => {
	// `error_message` is sometimes an empty string when the server only
	// supplies a code, so use truthy semantics rather than nullish
	// coalescing for the first fallback (`??` would keep empty strings).
	const message = row.error_message
		? row.error_message
		: mapErrorCodeToMessage( row.error_code );
	return (
		message ??
		__(
			'We could not create this label. Open the order to retry.',
			'woocommerce-shipping'
		)
	);
};

/**
 * Pick out the successfully-settled rows from the unified row list.
 */
export const succeededRows = ( rows: OrderRow[] ): SucceededRow[] =>
	rows.filter( ( r ): r is SucceededRow => r.status === 'succeeded' );

/**
 * Pick out the failed rows from the unified row list.
 */
export const failedRows = ( rows: OrderRow[] ): FailedRow[] =>
	rows.filter( ( r ): r is FailedRow => r.status === 'failed' );

/**
 * Pick out the still-pending rows from the unified row list.
 */
export const pendingRows = ( rows: OrderRow[] ): PendingRow[] =>
	rows.filter( ( r ): r is PendingRow => r.status === 'pending' );
