import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import {
	getLabelsPrintPath,
	getLabelTestPrintPath,
	getPackingSlipPrintPath,
} from 'data/routes';
import type { Label, LabelPrintFulfillmentRef } from 'types';
import { getPaperSizeWithKey } from 'components/label-purchase/label/utils';

type LabelPrintTarget =
	| Label[ 'labelId' ]
	| Label[ 'labelId' ][]
	| LabelPrintFulfillmentRef[];

const isFulfillmentLabelRefList = (
	labelTarget: LabelPrintTarget
): labelTarget is LabelPrintFulfillmentRef[] =>
	Array.isArray( labelTarget ) &&
	labelTarget.length > 0 &&
	labelTarget.every(
		( labelRef ) =>
			typeof labelRef === 'object' &&
			labelRef !== null &&
			typeof labelRef.label_id === 'number' &&
			typeof labelRef.fulfillment_id === 'number'
	);

const getLabelIds = ( labelTarget: LabelPrintTarget ): Label[ 'labelId' ][] => {
	if ( isFulfillmentLabelRefList( labelTarget ) ) {
		return labelTarget.map( ( labelRef ) => labelRef.label_id );
	}
	return Array.isArray( labelTarget ) ? labelTarget : [ labelTarget ];
};

const getPDFURL = (
	paperSize: string,
	labelTarget: LabelPrintTarget,
	baseUrl: string,
	country?: string
) => {
	// `getPaperSizeWithKey` defaults country to 'US'. Without forwarding the
	// caller's country, a saved A4 preference on a non-US store would resolve
	// to `undefined` here and throw, even though A4 is a valid choice for
	// that store. Thread `country` through so the lookup uses the same list
	// of paper sizes as the picker that produced the key.
	const selectedPaperSize = getPaperSizeWithKey( paperSize, country );
	if ( ! selectedPaperSize ) {
		throw new Error(
			__(
				'Selected paper size is no longer available. Please pick another.',
				'woocommerce-shipping'
			)
		);
	}
	const labelIds = getLabelIds( labelTarget );
	const params = {
		paper_size: paperSize,
		// Pass the label ids as a single CSV value, not as a repeated `label_id[]`
		// array, to dodge a conflict with plugins that strip array query args
		// from the URL (see woocommerce-services #1111).
		label_id_csv: labelIds.join( ',' ),
		json: true,
	};
	if ( isFulfillmentLabelRefList( labelTarget ) ) {
		Object.assign( params, {
			fulfillment_id_csv: labelTarget
				.map( ( labelRef ) => labelRef.fulfillment_id )
				.join( ',' ),
		} );
	}
	return addQueryArgs( baseUrl, params );
};

export const getPrintURL = (
	paperSize: string,
	labelId: LabelPrintTarget,
	country?: string
) => getPDFURL( paperSize, labelId, getLabelsPrintPath(), country );

export const getPreviewURL = (
	paperSize: string,
	labelId: Label[ 'labelId' ],
	country?: string
) => getPDFURL( paperSize, labelId, getLabelTestPrintPath(), country );

export const getPackingSlipPrintURL = ( labelId: number, orderId: number ) =>
	getPackingSlipPrintPath( labelId, orderId );
