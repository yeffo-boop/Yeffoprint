import { register, select } from '@wordpress/data';
import { SETTINGS_STORE_NAME } from 'data/constants';
import { createStore } from './store';

let settingsStore: ReturnType< typeof createStore >;
export const registerSettingsStore = () => {
	settingsStore = settingsStore || createStore();
	if ( select( SETTINGS_STORE_NAME ) ) {
		return;
	}
	register( settingsStore );
};

export { settingsStore };
