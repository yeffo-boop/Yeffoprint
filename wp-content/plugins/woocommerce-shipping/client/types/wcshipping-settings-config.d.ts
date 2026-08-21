import { StoreOptions } from './store-options.d';
import { UserMeta } from './user-meta.d';
import { PurchaseSettings } from './purchase-settings';
import { PurchaseMeta } from './purchase-meta';

export interface SettingsFormData extends PurchaseSettings {
	checkout_address_validation: boolean;
}

interface SettingsFormMeta extends PurchaseMeta {
	warnings: {
		payment_methods: string;
	};
}

interface AccountSettings {
	storeOptions: StoreOptions;
	formData: SettingsFormData;
	formMeta: SettingsFormMeta;
	userMeta: UserMeta;
	enabledServices: string[];
}

/**
 * TODO: Augment this interface with the rest of the properties from WCShipping_Config
 * object on wp-admin/admin.php?page=wc-settings&tab=shipping&section=woocommerce-shipping-settings
 */
export interface WCShippingSettingsConfig {
	accountSettings: AccountSettings;
}
