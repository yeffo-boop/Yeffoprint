/**
 * Completion Step - Step 4 of ScanForm creation
 */

import { Button, Flex, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { isDomesticShipment, isSafePdfUrl } from 'utils/scan-form';
import type { ScanFormLabel } from 'types';

interface CompletionStepProps {
	successMessage: string;
	processedLabelIds: number[];
	pdfUrls: string[];
	processedLabelBatches: number[][];
	partialFailureMessage?: string | null;
	partialFailureLabelIds?: number[];
	getLabelInfo: ( labelId: number ) => ScanFormLabel | null;
}

export const CompletionStep = ( {
	successMessage,
	processedLabelIds,
	pdfUrls,
	processedLabelBatches,
	partialFailureMessage,
	partialFailureLabelIds,
	getLabelInfo,
}: CompletionStepProps ) => {
	return (
		<>
			<Notice status="success" isDismissible={ false }>
				{ successMessage }
			</Notice>

			{ partialFailureMessage && (
				<Notice status="warning" isDismissible={ false }>
					<p>{ partialFailureMessage }</p>
					{ partialFailureLabelIds &&
						partialFailureLabelIds.length > 0 && (
							<ul className="scan-form-modal__completion-list">
								{ partialFailureLabelIds.map( ( labelId ) => {
									const label = getLabelInfo( labelId );
									return label ? (
										<li key={ labelId }>
											{ sprintf(
												/* translators: %1$s is order number, %2$s is tracking number, %3$s is service name */
												__(
													'Order #%1$s - %2$s - %3$s',
													'woocommerce-shipping'
												),
												label.order_number ?? '',
												label.tracking ?? '',
												label.service_name
											) }
										</li>
									) : (
										<li key={ labelId }>
											{ sprintf(
												/* translators: %d is label ID */
												__(
													'Label ID: %d',
													'woocommerce-shipping'
												),
												labelId
											) }
										</li>
									);
								} ) }
							</ul>
						) }
				</Notice>
			) }

			<div className="scan-form-modal__completion-info">
				<strong className="scan-form-modal__completion-label">
					{ __( 'Processed Labels:', 'woocommerce-shipping' ) }
				</strong>
				<ul className="scan-form-modal__completion-list">
					{ processedLabelIds.map( ( labelId ) => {
						const label = getLabelInfo( labelId );
						return label ? (
							<li key={ labelId }>
								{ sprintf(
									/* translators: %1$s is order number, %2$s is tracking number, %3$s is service name */
									__(
										'Order #%1$s - %2$s - %3$s',
										'woocommerce-shipping'
									),
									label.order_number ?? '',
									label.tracking ?? '',
									label.service_name
								) }
							</li>
						) : (
							<li key={ labelId }>
								{ sprintf(
									/* translators: %d is label ID */
									__(
										'Label ID: %d',
										'woocommerce-shipping'
									),
									labelId
								) }
							</li>
						);
					} ) }
				</ul>
			</div>

			{ pdfUrls.length > 0 && (
				<Flex gap={ 2 } justify="flex-start">
					{ pdfUrls.map( ( url, index ) => {
						if ( ! isSafePdfUrl( url ) ) {
							return null;
						}

						const batch = processedLabelBatches[ index ] ?? [];
						const firstLabel =
							batch.length > 0
								? getLabelInfo( batch[ 0 ] )
								: null;
						const isDomestic = firstLabel
							? isDomesticShipment( firstLabel )
							: true;
						const batchLabel = isDomestic
							? __( 'Domestic', 'woocommerce-shipping' )
							: __( 'International', 'woocommerce-shipping' );

						return (
							<Button
								key={ index }
								variant="secondary"
								onClick={ () =>
									window.open(
										url,
										'_blank',
										'noopener,noreferrer'
									)
								}
							>
								{ pdfUrls.length > 1
									? sprintf(
											/* translators: %1$s is the scan form type (Domestic/International) */
											__(
												'View USPS SCAN Form — %1$s',
												'woocommerce-shipping'
											),
											batchLabel
									  )
									: __(
											'View SCAN Form PDF',
											'woocommerce-shipping'
									  ) }
							</Button>
						);
					} ) }
				</Flex>
			) }
		</>
	);
};
