/**
 * Type definitions for ScanForm Modal
 */

import { OriginAddress } from './origin-address';
import { ResponseLabel } from './connect-server';

export type ScanFormLabel = Pick<
	ResponseLabel,
	'label_id' | 'tracking' | 'service_name'
> & {
	created: string;
	order_id: number;
	order_number: string;
	shipping_date: string;
	is_domestic?: boolean;
};

/**
 * Origin address type for ScanForm - derived from LocationResponse.
 */
export type ScanFormOriginAddress = Pick<
	OriginAddress,
	'address1' | 'address2' | 'city' | 'state' | 'postcode' | 'country'
>;

export interface ScanFormOrigin {
	origin_id: string;
	origin_address: ScanFormOriginAddress;
	labels: ScanFormLabel[];
	label_count: number;
}

export interface ReviewResult {
	eligible: number[];
	already_scanned: number[];
	not_found: number[];
	invalid_site: number[];
	excluded_labels: Partial< Record< string, number[] > >;
}

export interface OriginsApiResponse {
	success: boolean;
	origins?: ScanFormOrigin[];
	excluded_labels?: Partial< Record< string, number[] > >;
}

export interface ReviewApiResponse {
	success: boolean;
	eligible?: number[];
	already_scanned?: number[];
	not_found?: number[];
	invalid_site?: number[];
	excluded_labels?: Partial< Record< string, number[] > >;
}

export interface CreateApiResponse {
	success: boolean;
	scan_form?: {
		scan_form_id: string | null;
		pdf_url: string | null;
		created: string;
		label_count: number;
	};
}

export interface ScanFormErrorData {
	message: string;
	failed_labels: number[];
	valid_labels: number[];
}

export interface ScanFormApiError extends Error {
	data?: ScanFormErrorData;
}

export interface ScanFormModalProps {
	onClose: () => void;
}

export interface ScanFormHistoryItem {
	scan_form_id: string;
	pdf_url: string;
	created: string;
	label_count: number;
	label_ids: number[];
	origin_address: ScanFormOriginAddress | null;
}

export interface ScanFormHistoryResponse {
	success: boolean;
	scan_forms: ScanFormHistoryItem[];
	total: number;
	page: number;
	per_page: number;
	total_pages: number;
}

export interface ScanFormLabelsResponse {
	success: boolean;
	labels: ScanFormLabel[];
}

export interface ScanFormLabelsModalProps {
	scanForm: ScanFormHistoryItem;
	onClose: () => void;
}
