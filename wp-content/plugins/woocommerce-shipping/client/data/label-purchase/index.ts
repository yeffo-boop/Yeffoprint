import { register } from '@wordpress/data';
import { createStore } from './store';

let labelPurchaseStore: ReturnType< typeof createStore >;
export const registerLabelPurchaseStore = () => {
	labelPurchaseStore = labelPurchaseStore || createStore();
	register( labelPurchaseStore );
};

export { labelPurchaseStore };
