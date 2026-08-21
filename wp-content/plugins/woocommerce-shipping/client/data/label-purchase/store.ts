import { createReduxStore } from '@wordpress/data';
import { controls as wpControls } from '@wordpress/data-controls';
import { labelPurchaseReducer } from './reducer';
import * as selectors from './selectors';
import * as actions from './actions';
import { packagesActions, packagesSelectors } from './packages';
import { labelActions, labelSelectors } from './label';
import { LABEL_PURCHASE_STORE_NAME } from 'data/constants';

export const labelPurchaseConfig = {
	reducer: labelPurchaseReducer,
	selectors,
	actions,
};
const storeSelectors = {
	...labelPurchaseConfig.selectors,
	...packagesSelectors,
	...labelSelectors,
};
const storeActions = {
	...labelPurchaseConfig.actions,
	...packagesActions,
	...labelActions,
};
const resolvers = {};

export const createStore = () =>
	createReduxStore( LABEL_PURCHASE_STORE_NAME, {
		reducer: labelPurchaseConfig.reducer,
		actions: storeActions,
		selectors: storeSelectors,
		controls: wpControls,
		resolvers,
	} );
