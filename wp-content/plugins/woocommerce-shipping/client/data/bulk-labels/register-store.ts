import { register } from '@wordpress/data';
import { createBulkLabelsStore } from './store';

let bulkLabelsStore: ReturnType< typeof createBulkLabelsStore >;

/**
 * Register the bulk-labels @wordpress/data store. Idempotent; safe to
 * call from every entrypoint that mounts the bulk-labels UI, alongside
 * registerOrdersShippingContextEntity().
 */
export const registerBulkLabelsStore = (): void => {
	bulkLabelsStore = bulkLabelsStore || createBulkLabelsStore();
	register( bulkLabelsStore );
};

export { bulkLabelsStore };
