import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ScanFormHistoryItem } from 'types';
import { addressToString } from 'utils';

/**
 * Format date for display
 */
const formatDate = ( dateString: string ): string => {
	if ( ! dateString ) {
		return '';
	}
	return new Date( dateString ).toLocaleDateString( 'en-US', {
		month: 'short',
		day: 'numeric',
		year: 'numeric',
	} );
};

/**
 * Map scan form data to table row format
 */
export const mapScanFormToTableRow = (
	scanForm: ScanFormHistoryItem,
	onLabelCountClick: ( scanForm: ScanFormHistoryItem ) => void
) => {
	return [
		{
			display: (
				<div>
					<div className="scan-form-history__scan-form-id">
						{ scanForm.scan_form_id.toUpperCase() }
					</div>
					<div className="scan-form-history__date">
						{ formatDate( scanForm.created ) }
					</div>
				</div>
			),
			value: scanForm.scan_form_id,
		},
		{
			display: addressToString( scanForm.origin_address ),
			value: addressToString( scanForm.origin_address ),
		},
		{
			display: (
				<Button
					variant="link"
					onClick={ () => onLabelCountClick( scanForm ) }
					className="scan-form-history__label-count-button"
				>
					{ scanForm.label_count }
				</Button>
			),
			value: scanForm.label_count,
		},
		{
			display: (
				<Button
					variant="link"
					href={ scanForm.pdf_url }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Download', 'woocommerce-shipping' ) }
				</Button>
			),
			value: scanForm.pdf_url,
		},
	];
};
