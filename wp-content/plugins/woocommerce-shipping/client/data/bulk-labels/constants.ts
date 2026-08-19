import { __ } from '@wordpress/i18n';
import { NAMESPACE } from 'data/constants';
import { getConfig } from 'utils';

/**
 * core-data entity descriptor for the orders shipping-context endpoint.
 *
 * Following the same pattern WooCommerce-Next uses (entities registered
 * with `addEntities`, read with `useEntityRecords`) so the bulk-purchase
 * modal can read from one shared cache without each component juggling
 * its own apiFetch + state.
 */
export const ORDERS_SHIPPING_CONTEXT_ENTITY = {
	name: 'orders_shipping_context',
	kind: 'wcshipping',
	baseURL: `${ NAMESPACE }/orders/shipping-context`,
	label: __( 'Order shipping context', 'woocommerce-shipping' ),
	plural: __( 'Order shipping contexts', 'woocommerce-shipping' ),
	key: 'order_id',
	supportsPagination: false,
};

/**
 * Maximum number of orders that can flow through the bulk-purchase
 * modal at once. Sourced from PHP via the inline WCShipping_Config
 * payload (see `BulkLabelsBanner::enqueue_scripts`); PHP is the single
 * source of truth and shares the value with the downstream batch
 * rate-quote / purchase routes.
 *
 * Falls back to a sane default when the field isn't on the page yet
 * (e.g. tests, or a dev environment with a stale build).
 */
export const getBulkLabelsMaxOrders = (): number =>
	getConfig().bulk_labels_max_orders ?? 25;
