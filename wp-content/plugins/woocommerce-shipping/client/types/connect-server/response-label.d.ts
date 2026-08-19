import { LABEL_PURCHASE_STATUS } from 'data/constants';
import { RecordValues } from '../helpers';
import { Carrier } from '../carrier';

export interface ResponseLabel {
	label_id: number;
	fulfillment_id?: number;
	id: string;
	tracking: null | string;
	refundable_amount: number;
	created: number;
	carrier_id: null | Carrier;
	service_name: string;
	status: RecordValues< LABEL_PURCHASE_STATUS >;
	commercial_invoice_url: string;
	is_commercial_invoice_submitted_electronically: string | boolean;
	package_name: string;
	is_letter: boolean;
	product_names: string[];
	product_ids: number[];
	rate: number;
	receipt_item_id: number;
	created_date: number;
	currency: string;
	expiry_date: number;
	label_cached: number;
	main_receipt_id: number;
	used_date?: number;
	refund?: {
		is_manual: boolean;
		requested_date: number;
		status: 'pending' | 'complete' | 'rejected' | 'unknown';
	};
	error?: string;
	// Is the label migrated from the legacy plugin?
	is_legacy?: boolean;
	// Is this a return label?
	is_return?: boolean;
	// For return labels: which shipment is this a return for?
	parent_shipment_id?: string;
	promo_id?: string;
	promo_discount?: number;
	// Package dimensions (stored in metadata by LabelPurchaseService.php)
	// These are available via getStoredPackageDimensions() from config
	package_weight?: number;
	package_weight_unit?: string;
	package_length?: number;
	package_width?: number;
	package_height?: number;
	package_dimensions_unit?: string;
	is_return?: boolean;
}
