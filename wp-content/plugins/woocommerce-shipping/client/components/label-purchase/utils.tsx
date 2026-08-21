import { __, _n, sprintf } from '@wordpress/i18n';
import React from 'react';
import { getCustomFulfillmentSummary } from 'utils';
import { SHIPMENT_TYPE } from 'utils/shipment';
import type { ShipmentType } from 'types';

export const getShipmentTitle = (
	index: string | number,
	totalCount: number,
	type: ShipmentType
) => {
	if ( type === SHIPMENT_TYPE.RETURN ) {
		return sprintf(
			// translators: %1$d is the shipment number, %2$d is the total number of shipments
			__( 'Return %1$d/%2$d', 'woocommerce-shipping' ),
			parseInt( `${ index }`, 10 ) + 1,
			totalCount
		);
	}

	return sprintf(
		// translators: %1$d is the shipment number, %2$d is the total number of shipments
		__( 'Shipment %1$d/%2$d', 'woocommerce-shipping' ),
		parseInt( `${ index }`, 10 ) + 1,
		totalCount
	);
};

export const getShipmentSummaryText = (
	orderFulfilled: boolean,
	purchasedLabelProductCount: number,
	totalProductCount: number
) => {
	const classNames = 'wcshipping-shipping-label-meta-box__summary';

	// If there is a custom message, display that instead.
	if ( getCustomFulfillmentSummary() ) {
		return (
			<span className={ classNames }>
				{ getCustomFulfillmentSummary() }
			</span>
		);
	}

	if ( orderFulfilled ) {
		return (
			<span className={ classNames }>
				{ sprintf(
					// translators: %1$d: number of items
					_n(
						'%1$d item was fulfilled.',
						'%1$d items were fulfilled.',
						totalProductCount,
						'woocommerce-shipping'
					),
					totalProductCount
				) }
			</span>
		);
	} else if (
		purchasedLabelProductCount < totalProductCount &&
		purchasedLabelProductCount > 0
	) {
		return (
			<span className={ classNames }>
				{ sprintf(
					// translators: %1$d: number of items fulfilled
					_n(
						'%1$d item was fulfilled,',
						'%1$d items were fulfilled,',
						purchasedLabelProductCount,
						'woocommerce-shipping'
					),
					purchasedLabelProductCount
				) }{ ' ' }
				{ sprintf(
					// translators: %d: number of items to be fulfilled
					_n(
						'%d item still requires fulfillment.',
						'%d items still require fulfillment.',
						totalProductCount - purchasedLabelProductCount,
						'woocommerce-shipping'
					),
					totalProductCount - purchasedLabelProductCount
				) }
			</span>
		);
	}

	return (
		<span className={ classNames }>
			{ sprintf(
				// translators: %d: number of items
				_n(
					'%d item is ready to be fulfilled',
					'%d items are ready to be fulfilled',
					totalProductCount,
					'woocommerce-shipping'
				),
				totalProductCount
			) }
		</span>
	);
};
