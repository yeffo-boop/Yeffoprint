import { PaperSizeKey } from './paper-size';

export interface PurchaseSettings {
	email_receipts: boolean;
	enabled: boolean;
	paper_size: PaperSizeKey;
	selected_payment_method_id: number;
	use_last_package: boolean;
	use_last_service: boolean;
	checkout_address_validation: boolean;
	automatically_open_print_dialog: boolean;
	remember_last_used_shipping_date: boolean;
	return_to_sender_default: boolean;
	scanform_enabled: boolean;
}
