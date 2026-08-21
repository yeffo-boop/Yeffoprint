import {
	SETTINGS_SAVE,
	SETTINGS_UPDATE_FORM_DATA,
} from 'data/settings/action-types';
import { SettingsFormData } from 'types';

export function* saveSettings( {
	payload,
}: {
	payload: SettingsFormData;
} ): Generator<
	{
		type: 'API_SAVE_ACCOUNT_SETTINGS';
		payload: SettingsFormData;
	},
	{
		type: typeof SETTINGS_SAVE;
		result: {
			success: boolean;
			data: SettingsFormData;
		};
	},
	{
		success: boolean;
		data: SettingsFormData;
	}
> {
	const result = yield {
		type: 'API_SAVE_ACCOUNT_SETTINGS',
		payload,
	};

	return {
		type: SETTINGS_SAVE,
		result,
	};
}

export function updateFormData(
	formInputKey: string,
	formInputvalue: boolean | string | null
) {
	return {
		type: SETTINGS_UPDATE_FORM_DATA,
		payload: {
			[ formInputKey ]: formInputvalue,
		},
	};
}
