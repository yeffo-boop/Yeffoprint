/**
 * ScanForm utility functions for domestic/international grouping and label exclusion notices.
 *
 * The server is authoritative for domestic/international classification —
 * it populates `label.is_domestic` in the origins response. These helpers
 * just group by that flag; they do not re-apply USPS territory rules on
 * the client.
 */

import { getProtocol } from '@wordpress/url';
import { sprintf, _n } from '@wordpress/i18n';
import type { ScanFormLabel } from 'types';

const SAFE_URL_PROTOCOLS = [ 'http:', 'https:' ];

/**
 * Returns true when a URL uses http or https — safe to pass to window.open().
 * Rejects javascript:, data:, and any other protocol that could execute code.
 */
export const isSafePdfUrl = ( url: string ): boolean =>
	SAFE_URL_PROTOCOLS.includes( getProtocol( url ) ?? '' );

/**
 * Per-reason notice messages for labels excluded from SCAN Form eligibility.
 * Add a new entry here when a new exclusion reason is introduced server-side.
 * Unknown reasons fall back to the generic message in getExclusionNotice().
 */
const EXCLUSION_NOTICES: Record< string, ( count: number ) => string > = {
	missing_destination: ( count ) =>
		sprintf(
			/* translators: %d is number of labels excluded due to missing destination country */
			_n(
				'%d label was excluded because its destination country could not be determined and cannot be added to a SCAN Form.',
				'%d labels were excluded because their destination country could not be determined and cannot be added to a SCAN Form.',
				count,
				'woocommerce-shipping'
			),
			count
		),
	envelope_type: ( count ) =>
		sprintf(
			/* translators: %d is number of envelope labels excluded from SCAN Form */
			_n(
				'%d label is an envelope shipment and cannot be included in a SCAN Form.',
				'%d labels are envelope shipments and cannot be included in a SCAN Form.',
				count,
				'woocommerce-shipping'
			),
			count
		),
};

/**
 * Get a localized notice string for a given exclusion reason and label count.
 * Falls back to a generic message for unknown reasons.
 */
export const getExclusionNotice = ( reason: string, count: number ): string =>
	EXCLUSION_NOTICES[ reason ]?.( count ) ??
	sprintf(
		/* translators: %d is number of labels that could not be added */
		_n(
			'%d label could not be added to a SCAN Form.',
			'%d labels could not be added to a SCAN Form.',
			count,
			'woocommerce-shipping'
		),
		count
	);

/**
 * Check whether a label has been classified as a domestic USPS shipment.
 */
export const isDomesticShipment = ( label: ScanFormLabel ): boolean =>
	!! label.is_domestic;

/**
 * Split an array of label IDs into domestic and international batches
 * using the server-provided classification.
 *
 * @param labelIds Array of label IDs to split.
 * @param labels   Array of label objects carrying is_domestic.
 * @return Object with domestic and international label ID arrays.
 */
export const splitLabelsByDestination = (
	labelIds: number[],
	labels: ScanFormLabel[]
): { domestic: number[]; international: number[] } => {
	const labelMap = new Map( labels.map( ( l ) => [ l.label_id, l ] ) );
	const domestic: number[] = [];
	const international: number[] = [];

	for ( const labelId of labelIds ) {
		const label = labelMap.get( labelId );
		if ( ! label ) {
			continue;
		}
		if ( label.is_domestic ) {
			domestic.push( labelId );
		} else {
			international.push( labelId );
		}
	}

	return { domestic, international };
};
