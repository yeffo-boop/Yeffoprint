import { createReduxStore } from '@wordpress/data';
import { controls as wpControls } from '@wordpress/data-controls';
import { settingsReducer } from './reducer';
import * as selectors from './selectors';
import * as actions from './actions';
import * as controls from './controls';

import { SETTINGS_STORE_NAME } from 'data/constants';

export const settingsConfig = {
	reducer: settingsReducer,
	selectors: {
		...selectors,
	},
	actions: {
		...actions,
	},
	controls,
};

const resolvers = {};

export const createStore = () =>
	createReduxStore( SETTINGS_STORE_NAME, {
		reducer: settingsConfig.reducer,
		actions: settingsConfig.actions,
		selectors: settingsConfig.selectors,
		controls: { ...wpControls, ...settingsConfig.controls },
		resolvers,
	} );
