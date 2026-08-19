export interface Promotion {
	/** Unique promotion identifier */
	id: string;

	/** Carrier for the promotion */
	carrier: string;

	/** Date when promotion ends */
	endDate: string;

	/** Remaining promotion label purchases */
	remaining: number;

	/** Type of promotion discount */
	discountType: 'percentage' | 'fixed';

	/** Amount of the promotion discount */
	discountAmount: number;

	/** HTML to display in order detail notice */
	notice?: string;

	/** HTML to display in shipping label banner */
	banner?: string;

	/** Text to display in shipping flow badges */
	badge?: string;

	/** HTML to display in shipping service tooltip */
	tooltip?: string;
}
