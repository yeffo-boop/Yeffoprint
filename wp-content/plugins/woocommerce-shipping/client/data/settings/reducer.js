import {
	SETTINGS_SAVE,
	SETTINGS_UPDATE_FORM_DATA,
} from 'data/settings/action-types';

import { getAccountSettings } from 'utils';

// TODO: change default state
const defaultState = getAccountSettings();

export const settingsReducer = (
	state = defaultState,
	{ type, ...action }
) => {
	switch ( type ) {
		case SETTINGS_SAVE: {
			return {
				...state,
				...action.settings,
			};
		}
		case SETTINGS_UPDATE_FORM_DATA: {
			{
				return {
					...state,
					...{
						formData: {
							...state.formData,
							...action.payload,
						},
					},
				};
			}
		}
	}

	return state;
};

export default settingsReducer;
