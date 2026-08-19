import { createReduxStore } from '@wordpress/data';
import { controls } from '@wordpress/data-controls';
import { AnalyticsReducer as reducer } from './reducer';
import * as selectors from './selectors';
import * as actions from './actions';
import * as resolvers from './resolvers';
import { ANALYTICS_STORE_NAME } from 'data/constants';

export const createStore = () =>
	createReduxStore( ANALYTICS_STORE_NAME, {
		reducer,
		actions,
		selectors,
		controls,
		resolvers,
	} );
