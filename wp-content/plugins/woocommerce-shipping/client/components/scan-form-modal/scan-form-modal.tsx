/**
 * ScanForm Modal - Main orchestrator component
 */

import {
	Button,
	Flex,
	FlexItem,
	Modal,
	Notice,
	Spinner,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import { useScanFormState } from './hooks/use-scan-form-state';
import { OriginSelectionStep } from './origin-selection-step';
import { LabelSelectionStep } from './label-selection-step';
import { ReviewStep } from './review-step';
import { CompletionStep } from './completion-step';
import { getExclusionNotice } from 'utils/scan-form';
import type { ScanFormModalProps } from 'types';
import './style.scss';

export const ScanFormModal = ( { onClose }: ScanFormModalProps ) => {
	const {
		// States
		isLoading,
		isCreating,
		isReviewing,
		error,
		successMessage,
		origins,
		selectedOrigin,
		labels,
		selectedLabels,
		reviewResult,
		pdfUrls,
		processedLabelIds,
		processedLabelBatches,
		mixedBatchNotice,
		partialFailureMessage,
		partialFailureLabelIds,
		failedLabelsError,
		excludedLabels,
		showLabelSelectionStep,
		showReviewStep,
		showCompletedStep,

		// Actions
		fetchOrigins,
		setLabelSelection,
		setOriginSelection,
		reviewLabels,
		createScanForm,
		selectOriginAndProceed,
		goBackToOriginSelection,
		goBackToLabelSelection,
		getLabelInfo,
		getSelectionCounts,
		retryWithValidLabels,
		dismissFailedLabelsError,
	} = useScanFormState();

	// Fetch origins on mount
	useEffect( () => {
		void fetchOrigins();
	}, [ fetchOrigins ] );

	// Auto-select origin if only one exists.
	useEffect( () => {
		if (
			! isLoading &&
			origins.length === 1 &&
			! selectedOrigin &&
			! showLabelSelectionStep
		) {
			selectOriginAndProceed( origins[ 0 ] );
		}
	}, [
		origins,
		isLoading,
		selectedOrigin,
		showLabelSelectionStep,
		selectOriginAndProceed,
	] );

	// Determine current step.
	const isOriginSelectionStep =
		! isLoading &&
		! error &&
		origins.length > 0 &&
		! showLabelSelectionStep &&
		! showReviewStep &&
		! showCompletedStep;

	const isLabelSelectionStep =
		showLabelSelectionStep &&
		selectedOrigin &&
		! showReviewStep &&
		! showCompletedStep;

	const isReviewStepActive =
		showReviewStep && reviewResult && ! showCompletedStep;

	const isCompletionStepActive = showCompletedStep && successMessage;

	// Compute counts once per render for the label-selection footer.
	const selectionCounts = isLabelSelectionStep
		? getSelectionCounts()
		: { domestic: 0, international: 0 };

	// Dynamic modal title based on step.
	const getModalTitle = () => {
		if ( isOriginSelectionStep ) {
			return __( 'Select ship-from address', 'woocommerce-shipping' );
		}

		if ( isLabelSelectionStep ) {
			return __( 'Select labels', 'woocommerce-shipping' );
		}

		return __( 'Create USPS® SCAN Form', 'woocommerce-shipping' );
	};

	return (
		<Modal
			title={ getModalTitle() }
			onRequestClose={ onClose }
			className="scan-form-modal"
			shouldCloseOnClickOutside={ false }
		>
			{ /* Loading State */ }
			{ isLoading && (
				<Flex
					justify="center"
					align="center"
					className="scan-form-modal__loading"
				>
					<Spinner />
				</Flex>
			) }

			{ /* Error Notice */ }
			{ ! isLoading && error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ /* Failed Labels Error Dialog */ }
			{ failedLabelsError && (
				<>
					{ failedLabelsError.valid_labels.length === 0 ? (
						/* No valid labels - show error only */
						<Notice status="error" isDismissible={ false }>
							<p>
								<strong>
									{ __(
										'No Valid Labels Found',
										'woocommerce-shipping'
									) }
								</strong>
							</p>
							<p>
								{ __(
									'All selected labels are ineligible for SCAN Form creation. Labels must have EasyPost shipment IDs, shipment dates must be today or later, and must not already be manifested.',
									'woocommerce-shipping'
								) }
							</p>
							<Flex gap={ 2 } style={ { marginTop: '12px' } }>
								<Button
									variant="primary"
									onClick={ dismissFailedLabelsError }
								>
									{ __( 'OK', 'woocommerce-shipping' ) }
								</Button>
							</Flex>
						</Notice>
					) : (
						/* Some valid labels remaining - show confirmation */
						<Notice status="warning" isDismissible={ false }>
							<p>
								<strong>
									{ __(
										'Some Labels Could Not Be Processed',
										'woocommerce-shipping'
									) }
								</strong>
							</p>
							<p>
								{ /* Check if error is about already manifested labels */ }
								{ failedLabelsError.message
									?.toLowerCase()
									.includes( 'manifest' )
									? sprintf(
											/* translators: %d is number of labels */
											_n(
												'%d label has already been included in a SCAN Form or has been shipped.',
												'%d labels have already been included in a SCAN Form or have been shipped.',
												failedLabelsError.failed_labels
													.length,
												'woocommerce-shipping'
											),
											failedLabelsError.failed_labels
												.length
									  )
									: sprintf(
											/* translators: %d is number of labels */
											_n(
												'%d label could not be included in the SCAN Form (it may have been refunded or deleted).',
												'%d labels could not be included in the SCAN Form (they may have been refunded or deleted).',
												failedLabelsError.failed_labels
													.length,
												'woocommerce-shipping'
											),
											failedLabelsError.failed_labels
												.length
									  ) }
							</p>
							<p>
								{ sprintf(
									/* translators: %d is number of valid labels */
									_n(
										'You still have %d valid label. Would you like to create a SCAN Form with the remaining valid label?',
										'You still have %d valid labels. Would you like to create a SCAN Form with the remaining valid labels?',
										failedLabelsError.valid_labels.length,
										'woocommerce-shipping'
									),
									failedLabelsError.valid_labels.length
								) }
							</p>
							<Flex gap={ 2 } style={ { marginTop: '12px' } }>
								<Button
									variant="secondary"
									onClick={ dismissFailedLabelsError }
								>
									{ __( 'Cancel', 'woocommerce-shipping' ) }
								</Button>
								<Button
									variant="primary"
									onClick={ retryWithValidLabels }
								>
									{ sprintf(
										/* translators: %d is number of valid labels */
										_n(
											'Proceed with %d Valid Label',
											'Proceed with %d Valid Labels',
											failedLabelsError.valid_labels
												.length,
											'woocommerce-shipping'
										),
										failedLabelsError.valid_labels.length
									) }
								</Button>
							</Flex>
						</Notice>
					) }
				</>
			) }

			{ /* Success Notice (not on completed step) */ }
			{ ! isLoading && successMessage && ! showCompletedStep && (
				<Notice status="success" isDismissible={ false }>
					{ successMessage }
				</Notice>
			) }

			{ /* No Eligible Labels Warning */ }
			{ ! isLoading && ! error && origins.length === 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'No eligible USPS labels found in the selected orders. Labels must be USPS, have a tracking number, and not be refunded.',
						'woocommerce-shipping'
					) }
				</Notice>
			) }

			{ /* Step 1: Origin Selection */ }
			{ isOriginSelectionStep && (
				<OriginSelectionStep
					origins={ origins }
					onSelectOrigin={ setOriginSelection }
				/>
			) }

			{ /* Step 2: Label Selection — exclusion notices */ }
			{ isLabelSelectionStep &&
				Object.entries( excludedLabels ).map(
					( [ reason, labelIds ] ) => {
						if ( ! labelIds || labelIds.length === 0 ) {
							return null;
						}
						return (
							<Notice
								key={ reason }
								status="warning"
								isDismissible={ false }
							>
								{ getExclusionNotice(
									reason,
									labelIds.length
								) }
							</Notice>
						);
					}
				) }
			{ isLabelSelectionStep && labels.length > 0 && (
				<LabelSelectionStep
					selectedOrigin={ selectedOrigin }
					labels={ labels }
					selectedLabels={ selectedLabels }
					onSetSelection={ setLabelSelection }
				/>
			) }

			{ /* Step 3: Review */ }
			{ isReviewStepActive && (
				<ReviewStep
					reviewResult={ reviewResult }
					getLabelInfo={ getLabelInfo }
					mixedBatchNotice={ mixedBatchNotice }
				/>
			) }

			{ /* Step 4: Completion */ }
			{ isCompletionStepActive && (
				<CompletionStep
					successMessage={ successMessage }
					processedLabelIds={ processedLabelIds }
					processedLabelBatches={ processedLabelBatches }
					pdfUrls={ pdfUrls }
					partialFailureMessage={ partialFailureMessage }
					partialFailureLabelIds={ partialFailureLabelIds }
					getLabelInfo={ getLabelInfo }
				/>
			) }

			{ /* Footer */ }
			<Flex justify="space-between" align="flex-end" as="footer">
				{ isLabelSelectionStep && (
					<FlexItem>
						<div className="scan-form-modal__label-count">
							<span>
								{ sprintf(
									/* translators: %d is number of selected labels */
									_n(
										'%d label selected',
										'%d labels selected',
										selectedLabels.size,
										'woocommerce-shipping'
									),
									selectedLabels.size
								) }
							</span>
							{ selectionCounts.domestic > 0 &&
								selectionCounts.international > 0 && (
									<span className="scan-form-modal__label-count-breakdown">
										{ sprintf(
											/* translators: 1: domestic count, 2: international count */
											__(
												'%1$d domestic + %2$d international',
												'woocommerce-shipping'
											),
											selectionCounts.domestic,
											selectionCounts.international
										) }
									</span>
								) }
						</div>
					</FlexItem>
				) }
				<FlexItem>
					<Flex gap={ 2 }>
						{ /* Done Button (Completed Step) */ }
						{ showCompletedStep && (
							<Button variant="primary" onClick={ onClose }>
								{ __( 'Done', 'woocommerce-shipping' ) }
							</Button>
						) }

						{ /* Active Steps Footer */ }
						{ ! showCompletedStep && (
							<>
								{ /* Cancel Button */ }
								<Button
									variant="tertiary"
									onClick={ onClose }
									disabled={ isCreating || isReviewing }
								>
									{ __( 'Cancel', 'woocommerce-shipping' ) }
								</Button>

								{ /* Origin Selection Step Buttons */ }
								{ isOriginSelectionStep && (
									<Button
										variant="primary"
										onClick={ () =>
											selectedOrigin &&
											selectOriginAndProceed(
												selectedOrigin
											)
										}
										disabled={ ! selectedOrigin }
									>
										{ __(
											'Continue',
											'woocommerce-shipping'
										) }
									</Button>
								) }

								{ /* Review Step Buttons */ }
								{ showReviewStep && (
									<>
										<Button
											variant="secondary"
											onClick={ goBackToLabelSelection }
											disabled={ isCreating }
										>
											{ __(
												'Back',
												'woocommerce-shipping'
											) }
										</Button>
										<Button
											variant="primary"
											onClick={ () => createScanForm() }
											isBusy={ isCreating }
											disabled={
												isCreating ||
												!! successMessage ||
												! reviewResult ||
												reviewResult.eligible.length ===
													0
											}
										>
											{ __(
												'Create SCAN Form',
												'woocommerce-shipping'
											) }
										</Button>
									</>
								) }

								{ /* Label Selection Step Buttons */ }
								{ showLabelSelectionStep &&
									! showReviewStep && (
										<>
											<Button
												variant="secondary"
												onClick={
													goBackToOriginSelection
												}
												disabled={ isReviewing }
											>
												{ __(
													'Back',
													'woocommerce-shipping'
												) }
											</Button>
											<Button
												variant="primary"
												onClick={ reviewLabels }
												isBusy={ isReviewing }
												disabled={
													isLoading ||
													selectedLabels.size === 0 ||
													isReviewing ||
													!! successMessage
												}
											>
												{ __(
													'Review Labels',
													'woocommerce-shipping'
												) }
											</Button>
										</>
									) }
							</>
						) }
					</Flex>
				</FlexItem>
			</Flex>
		</Modal>
	);
};
