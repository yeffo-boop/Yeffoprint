import { combineReducers, createReduxStore, register } from '@wordpress/data';
import { STORE_NAME } from './constants';
import { pluginStatusConfig } from './plugin-status';

const initStore = () => {
	return createReduxStore( STORE_NAME, {
		reducer: combineReducers( {
			pluginStatus: pluginStatusConfig.reducer,
		} ),
		actions: {
			...pluginStatusConfig.actions,
		},
		selectors: {
			...pluginStatusConfig.selectors,
		},
		controls: {
			...( window.__REDUX_DEVTOOLS_EXTENSION__
				? window.__REDUX_DEVTOOLS_EXTENSION__( {
						name: `wcshipping`,
				  } )
				: {} ),
			...pluginStatusConfig.controls,
		},
	} );
};

const initAndRegisterStore = () => {
	const store = initStore();
	register( store );
};

export { initStore, initAndRegisterStore };
