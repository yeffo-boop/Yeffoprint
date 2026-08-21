import { addFilter } from '@wordpress/hooks';
import { loadConfig } from './utils';

addFilter(
	'woocommerce_shipping.settings.content',
	'wcshipping/next',
	async () => {
		await loadConfig();
		const settings = await import( './settings' );
		return settings.WooCommerceShippingSettings;
	}
);
