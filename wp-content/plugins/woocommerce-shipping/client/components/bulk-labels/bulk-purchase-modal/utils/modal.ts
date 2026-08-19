import type {
	BulkRequestPackage,
	OrderRate,
	OrderShippingContextRecord,
	RateRequestOrder,
	SelectedBatchRate,
} from 'data/bulk-labels';

/**
 * Sentinel values for the "Apply to all" package dropdown. They sit
 * alongside real package keys, so they use a prefix no package key uses
 * (`AssignablePackage.key` is `custom:…` / `predef:…`).
 */
export const AUTO_PACKAGE_VALUE = '__auto__';
export const MANUAL_PACKAGE_VALUE = '__manual__';
export const DEFAULT_BATCH_PACKAGE_ID = 'default_box';

/**
 * wp-admin edit URL for an order. The modal always opens from the orders
 * list, so the current page tells us whether the store is on HPOS
 * (`page=wc-orders`) or legacy post-table orders. URLs are relative to
 * wp-admin (same convention as the analytics order links).
 */
export const getOrderEditUrl = ( orderId: number ): string =>
	window.location.href.includes( 'page=wc-orders' )
		? `admin.php?page=wc-orders&action=edit&id=${ orderId }`
		: `post.php?post=${ orderId }&action=edit`;

/**
 * "City, ST Postcode" — the compact line under the recipient name.
 */
export const formatLocality = (
	destination: OrderShippingContextRecord[ 'destination' ]
): string => {
	const stateAndZip = [ destination?.state, destination?.postcode ]
		.filter( Boolean )
		.join( ' ' );
	return [ destination?.city, stateAndZip ]
		.filter( ( p ): p is string => Boolean( p?.trim() ) )
		.join( ', ' );
};

export const toSelectedBatchRate = (
	rate: OrderRate | null | undefined
): SelectedBatchRate | null => {
	if (
		! rate?.rateId ||
		! rate.serviceId ||
		! rate.carrierId ||
		! rate.shipmentId
	) {
		return null;
	}

	return {
		rate_id: rate.rateId,
		service_id: rate.serviceId,
		carrier_id: rate.carrierId,
		service_name: rate.title,
		shipment_id: rate.shipmentId,
		rate: rate.rate,
		retail_rate: rate.retailRate,
	};
};

export const toBulkRequestPackage = (
	order: RateRequestOrder
): BulkRequestPackage => ( {
	id: DEFAULT_BATCH_PACKAGE_ID,
	box_id: order.package.box_id || DEFAULT_BATCH_PACKAGE_ID,
	length: order.package.length,
	width: order.package.width,
	height: order.package.height,
	weight: order.package.weight,
	is_letter: order.package.is_letter,
	products: [],
} );
