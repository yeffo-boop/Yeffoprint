/**
 * Review Step - Step 3 of ScanForm creation
 */

import { Notice } from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';
import { useCallback } from '@wordpress/element';
import { getExclusionNotice } from 'utils/scan-form';
import type { ReviewResult, ScanFormLabel } from 'types';

interface ReviewStepProps {
	reviewResult: ReviewResult;
	getLabelInfo: ( labelId: number ) => ScanFormLabel | null;
	mixedBatchNotice: string | null;
}

export const ReviewStep = ( {
	reviewResult,
	getLabelInfo,
	mixedBatchNotice,
}: ReviewStepProps ) => {
	/**
	 * Render review list item
	 */
	const renderReviewListItem = useCallback(
		( labelId: number ) => {
			const label = getLabelInfo( labelId );
			return label ? (
				<li key={ labelId }>
					{ sprintf(
						/* translators: %1$s is order number, %2$s is tracking number */
						__( 'Order #%1$s - %2$s', 'woocommerce-shipping' ),
						label.order_number ?? '',
						label.tracking ?? ''
					) }
				</li>
			) : (
				<li key={ labelId }>
					{ sprintf(
						/* translators: %d is label ID */
						__( 'Label ID: %d', 'woocommerce-shipping' ),
						labelId
					) }
				</li>
			);
		},
		[ getLabelInfo ]
	);

	return (
		<>
			<p className="scan-form-modal__step-description">
				{ __(
					'Review the results below before creating the SCAN Form.',
					'woocommerce-shipping'
				) }
			</p>

			{ /* Info notice: mixed batch and/or excluded labels */ }
			{ ( ( mixedBatchNotice ?? false ) ||
				Object.values( reviewResult.excluded_labels ).some(
					( ids ) => ids && ids.length > 0
				) ) && (
				<div className="scan-form-modal__review-section">
					<Notice status="info" isDismissible={ false }>
						{ mixedBatchNotice && <p>{ mixedBatchNotice }</p> }
						{ Object.entries( reviewResult.excluded_labels ).map(
							( [ reason, labelIds ] ) =>
								labelIds && labelIds.length > 0 ? (
									<p key={ reason }>
										{ getExclusionNotice(
											reason,
											labelIds.length
										) }
									</p>
								) : null
						) }
					</Notice>
					{ Object.entries( reviewResult.excluded_labels ).map(
						( [ reason, labelIds ] ) =>
							labelIds && labelIds.length > 0 ? (
								<ul
									key={ reason }
									className="scan-form-modal__review-list"
								>
									{ labelIds.map( renderReviewListItem ) }
								</ul>
							) : null
					) }
				</div>
			) }

			{ /* Eligible Labels */ }
			{ reviewResult.eligible.length > 0 && (
				<div className="scan-form-modal__review-section">
					<Notice status="success" isDismissible={ false }>
						{ sprintf(
							/* translators: %d is number of eligible labels */
							_n(
								'%d label is eligible for SCAN Form creation.',
								'%d labels are eligible for SCAN Form creation.',
								reviewResult.eligible.length,
								'woocommerce-shipping'
							),
							reviewResult.eligible.length
						) }
					</Notice>
					<ul className="scan-form-modal__review-list">
						{ reviewResult.eligible.map( renderReviewListItem ) }
					</ul>
				</div>
			) }

			{ /* Already Scanned Labels */ }
			{ reviewResult.already_scanned.length > 0 && (
				<div className="scan-form-modal__review-section">
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %d is number of already scanned labels */
							_n(
								'%d label has already been included in a SCAN Form and will be skipped.',
								'%d labels have already been included in a SCAN Form and will be skipped.',
								reviewResult.already_scanned.length,
								'woocommerce-shipping'
							),
							reviewResult.already_scanned.length
						) }
					</Notice>
					<ul className="scan-form-modal__review-list">
						{ reviewResult.already_scanned.map(
							renderReviewListItem
						) }
					</ul>
				</div>
			) }

			{ /* Not Found Labels */ }
			{ reviewResult.not_found.length > 0 && (
				<div className="scan-form-modal__review-section">
					<Notice status="error" isDismissible={ false }>
						{ sprintf(
							/* translators: %d is number of not found labels */
							_n(
								'%d label was not found and will be skipped.',
								'%d labels were not found and will be skipped.',
								reviewResult.not_found.length,
								'woocommerce-shipping'
							),
							reviewResult.not_found.length
						) }
					</Notice>
					<ul className="scan-form-modal__review-list">
						{ reviewResult.not_found.map( renderReviewListItem ) }
					</ul>
				</div>
			) }

			{ /* Invalid Site Labels */ }
			{ reviewResult.invalid_site.length > 0 && (
				<div className="scan-form-modal__review-section">
					<Notice status="error" isDismissible={ false }>
						{ sprintf(
							/* translators: %d is number of invalid site labels */
							_n(
								'%d label belongs to a different site and will be skipped.',
								'%d labels belong to a different site and will be skipped.',
								reviewResult.invalid_site.length,
								'woocommerce-shipping'
							),
							reviewResult.invalid_site.length
						) }
					</Notice>
					<ul className="scan-form-modal__review-list">
						{ reviewResult.invalid_site.map(
							renderReviewListItem
						) }
					</ul>
				</div>
			) }

			{ /* No Eligible Labels Error */ }
			{ reviewResult.eligible.length === 0 && (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'No eligible labels found. Please go back and select different labels.',
						'woocommerce-shipping'
					) }
				</Notice>
			) }
		</>
	);
};
