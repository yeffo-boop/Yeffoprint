import { useSelect } from '@wordpress/data';
import { settingsStore } from 'data/settings';

export const useSettings = () => {
	// Get "formData"
	const defaultTaxIdentifiers = {}; // Using the same object as the default prevents exessive re-renders
	const {
		paper_size: labelSize,
		email_receipts: emailReceiptEnabled,
		use_last_service: rememberServiceEnabled,
		use_last_package: rememberPackageEnabled,
		checkout_address_validation: checkoutAddressValidation,
		automatically_open_print_dialog: automaticallyOpenPrintDialog,
		tax_identifiers: taxIdentifiers,
		remember_last_used_shipping_date: rememberLastUsedShippingDate,
		return_to_sender_default: returnToSenderDefault,
		scanform_enabled: scanformEnabled,
	} = useSelect( ( select ) => {
		const settings = select( settingsStore ).getConfigSettings();
		if ( ! settings ) {
			//defaults
			return {
				paper_size: '',
				email_receipts: '',
				use_last_service: '',
				use_last_package: '',
				checkout_address_validation: '',
				automatically_open_print_dialog: '',
				tax_identifiers: defaultTaxIdentifiers,
				remember_last_used_shipping_date: '',
				return_to_sender_default: '',
				scanform_enabled: '',
			};
		}
		return {
			paper_size: settings.paper_size ?? '',
			email_receipts: settings.email_receipts ?? '',
			use_last_service: settings.use_last_service ?? '',
			use_last_package: settings.use_last_package ?? '',
			checkout_address_validation:
				settings.checkout_address_validation ?? '',
			automatically_open_print_dialog:
				settings.automatically_open_print_dialog ?? '',
			tax_identifiers: settings.tax_identifiers ?? defaultTaxIdentifiers,
			remember_last_used_shipping_date:
				settings.remember_last_used_shipping_date ?? '',
			return_to_sender_default: settings.return_to_sender_default ?? '',
			scanform_enabled: settings.scanform_enabled ?? '',
		};
	} );

	// Get "formMeta"
	const {
		master_user_name: storeOwnerUsername,
		master_user_login: storeOwnerLogin,
		master_user_email: storeOwnerEmail,
		master_user_wpcom_login: storeOwnerWpcomLogin,
		can_manage_payments: canManagePayments,
	} = useSelect( ( select ) => {
		const settings = select( settingsStore ).getConfigMeta();
		if ( ! settings ) {
			//defaults
			return {
				master_user_name: '',
				master_user_login: '',
				master_user_email: '',
				master_user_wpcom_login: '',
				can_manage_payments: false,
			};
		}
		return {
			master_user_name: settings.master_user_name ?? '',
			master_user_login: settings.master_user_login ?? '',
			master_user_email: settings.master_user_email ?? '',
			master_user_wpcom_login: settings.master_user_wpcom_login ?? '',
			can_manage_payments: settings.can_manage_payments ?? false,
		};
	} );

	// Consolidate all settings into 1 object.
	return {
		labelSize,
		emailReceiptEnabled,
		rememberServiceEnabled,
		rememberPackageEnabled,
		checkoutAddressValidation,
		taxIdentifiers,
		storeOwnerUsername,
		storeOwnerLogin,
		storeOwnerEmail,
		storeOwnerWpcomLogin,
		automaticallyOpenPrintDialog,
		canManagePayments,
		rememberLastUsedShippingDate,
		returnToSenderDefault,
		scanformEnabled,
	};
};
