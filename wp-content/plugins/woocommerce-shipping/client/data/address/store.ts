import { createReduxStore } from '@wordpress/data';
import { controls as wpControls } from '@wordpress/data-controls';
import { getReducer } from './reducer';
import * as selectors from './selectors';
import * as actions from './actions';
import { ADDRESS_STORE_NAME } from 'data/constants';

const resolvers = {};

export const createStore = ( withDestination: boolean ) =>
	createReduxStore( ADDRESS_STORE_NAME, {
		reducer: getReducer( withDestination ),
		actions,
		selectors,
		controls: wpControls,
		resolvers,
	} );
