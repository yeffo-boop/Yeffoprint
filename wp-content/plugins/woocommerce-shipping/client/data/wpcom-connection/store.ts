import { createReduxStore } from '@wordpress/data';
import { controls } from '@wordpress/data-controls';
import { WPCOMConnectionReducer as reducer } from './reducer';
import * as selectors from './selectors';
import * as actions from './actions';
import { WPCOM_CONNECTION_STORE_NAME } from 'data/constants';

export const createStore = () =>
	createReduxStore( WPCOM_CONNECTION_STORE_NAME, {
		reducer,
		actions,
		selectors,
		controls,
		resolvers: {},
	} );
