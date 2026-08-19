export const getWoocommerceHealthItem = ( state ) => {
	return state.pluginStatus?.healthItems?.woocommerce;
};

export const getWPComHealthItem = ( state ) => {
	return state.pluginStatus?.healthItems?.wordpress_com;
};

export const getWCShippingHealthItem = ( state ) => {
	return state.pluginStatus?.healthItems?.wcshipping;
};

export const getLogs = ( state ) => {
	return state.pluginStatus?.logs;
};

export const getLoggingEnabled = ( state ) => {
	return state.pluginStatus?.loggingEnabled;
};

export const getDebugEnabled = ( state ) => {
	return state.pluginStatus?.debugEnabled;
};
