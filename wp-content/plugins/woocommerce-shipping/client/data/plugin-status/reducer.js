import {
	STATUS_INIT,
	STATUS_REFRESH_SERVICE_DATA,
	STATUS_SAVE_DEBUG_TOGGLE,
} from 'data/plugin-status/action-types';

const defaultState = {};

export const pluginStatusReducer = (
	state = defaultState,
	{ type, ...action }
) => {
	switch ( type ) {
		case STATUS_INIT: {
			return {
				...state,
				...action.payload,
			};
		}
		case STATUS_SAVE_DEBUG_TOGGLE: {
			const newPluginStatus = { ...state };
			newPluginStatus.loggingEnabled = action.payload.loggingEnabled;
			newPluginStatus.debugEnabled = action.payload.debugEnabled;

			return {
				...state,
				...newPluginStatus,
			};
		}
		case STATUS_REFRESH_SERVICE_DATA: {
			const newPluginStatus = { ...state };
			newPluginStatus.healthItems.wcshipping.timestamp =
				action.payload.timestamp;
			return {
				...state,
				...newPluginStatus,
			};
		}
	}

	return state;
};

export default pluginStatusReducer;
