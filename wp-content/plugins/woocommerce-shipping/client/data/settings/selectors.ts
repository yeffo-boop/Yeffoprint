import { WCShippingSettingsConfig } from 'types';

export const getSettings = (
	state: WCShippingSettingsConfig[ 'accountSettings' ]
) => {
	return state;
};

export const getAddPaymentMethodURL = (
	state: WCShippingSettingsConfig[ 'accountSettings' ]
) => {
	//TODO: Why did we call it formMeta? Can we rename this from the API?
	return (
		state.formMeta?.add_payment_method_url ??
		'https://wordpress.com/me/purchases/add-credit-card'
	);
};

export const getPaymentMethods = (
	state: WCShippingSettingsConfig[ 'accountSettings' ]
) => {
	//TODO: Why did we call it formMeta? Can we rename this from the API?
	return state.formMeta?.payment_methods ?? [];
};

export const getSelectedPaymentMethod = (
	state: WCShippingSettingsConfig[ 'accountSettings' ]
) => {
	return state.formData?.selected_payment_method_id;
};

export const getConfigSettings = (
	state: WCShippingSettingsConfig[ 'accountSettings' ]
) => {
	//TODO: Why did we call it formData? Can we rename this from the API?
	return state.formData;
};

export const getConfigMeta = (
	state: WCShippingSettingsConfig[ 'accountSettings' ]
) => {
	//TODO: Why did we call it formMeta? Can we rename this from the API?
	return state.formMeta;
};

export const getEnabledServices = (
	state: WCShippingSettingsConfig[ 'accountSettings' ]
) => {
	return state?.enabledServices ?? [];
};
