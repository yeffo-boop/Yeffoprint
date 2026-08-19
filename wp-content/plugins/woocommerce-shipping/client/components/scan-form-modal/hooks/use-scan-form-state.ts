/**
 * Custom hook for managing ScanForm state
 */

import { useState, useCallback, useMemo } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf, _n } from '@wordpress/i18n';
import type {
	ScanFormOrigin,
	ScanFormLabel,
	ReviewResult,
	OriginsApiResponse,
	ReviewApiResponse,
	CreateApiResponse,
	ScanFormApiError,
	ScanFormErrorData,
} from 'types';
import { splitLabelsByDestination } from 'utils/scan-form';
import {
	getCreateScanFormPath,
	getScanFormOriginsPath,
	getScanFormReviewPath,
} from 'data/routes';

export const useScanFormState = () => {
	// Loading and error states
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isCreating, setIsCreating ] = useState( false );
	const [ isReviewing, setIsReviewing ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ successMessage, setSuccessMessage ] = useState< string | null >(
		null
	);

	// Data states
	const [ origins, setOrigins ] = useState< ScanFormOrigin[] >( [] );
	const [ selectedOrigin, setSelectedOrigin ] =
		useState< ScanFormOrigin | null >( null );
	const [ labels, setLabels ] = useState< ScanFormLabel[] >( [] );

	const labelMap = useMemo( () => {
		const map = new Map< number, ScanFormLabel >();
		labels.forEach( ( l ) => map.set( l.label_id, l ) );
		return map;
	}, [ labels ] );
	const [ selectedLabels, setSelectedLabels ] = useState< Set< number > >(
		new Set()
	);
	const [ reviewResult, setReviewResult ] = useState< ReviewResult | null >(
		null
	);
	const [ pdfUrls, setPdfUrls ] = useState< string[] >( [] );
	const [ processedLabelIds, setProcessedLabelIds ] = useState< number[] >(
		[]
	);
	const [ processedLabelBatches, setProcessedLabelBatches ] = useState<
		number[][]
	>( [] );
	const [ failedLabelsError, setFailedLabelsError ] =
		useState< ScanFormErrorData | null >( null );
	const [ excludedLabels, setExcludedLabels ] = useState<
		Partial< Record< string, number[] > >
	>( {} );
	const [ mixedBatchNotice, setMixedBatchNotice ] = useState< string | null >(
		null
	);
	const [ partialFailureMessage, setPartialFailureMessage ] = useState<
		string | null
	>( null );
	const [ partialFailureLabelIds, setPartialFailureLabelIds ] = useState<
		number[]
	>( [] );

	// Step states
	const [ showLabelSelectionStep, setShowLabelSelectionStep ] =
		useState( false );
	const [ showReviewStep, setShowReviewStep ] = useState( false );
	const [ showCompletedStep, setShowCompletedStep ] = useState( false );

	/**
	 * Fetch origin addresses from the API (Step 1 - lightweight)
	 */
	const fetchOrigins = useCallback( async () => {
		setIsLoading( true );
		setError( null );

		try {
			const response = await apiFetch< OriginsApiResponse >( {
				path: getScanFormOriginsPath(),
				method: 'GET',
			} );

			if ( response.success && response.origins ) {
				setOrigins( response.origins );
				setExcludedLabels( response.excluded_labels ?? {} );
			} else {
				setError(
					__(
						'No eligible labels found. Please ensure you have USPS labels with tracking numbers that have not been refunded.',
						'woocommerce-shipping'
					)
				);
			}
		} catch ( err ) {
			const errorMessage =
				err instanceof Error
					? err.message
					: __(
							'Failed to fetch origin addresses.',
							'woocommerce-shipping'
					  );
			setError( errorMessage );
		} finally {
			setIsLoading( false );
		}
	}, [] );

	/**
	 * Toggle a single label selection
	 */
	const toggleLabel = useCallback( ( labelId: number ) => {
		setSelectedLabels( ( prevSelected ) => {
			const newSelected = new Set( prevSelected );
			if ( newSelected.has( labelId ) ) {
				newSelected.delete( labelId );
			} else {
				newSelected.add( labelId );
			}
			return newSelected;
		} );
	}, [] );

	/**
	 * Toggle all labels
	 */
	const toggleAllLabels = useCallback( () => {
		const allLabelIds = labels.map( ( label ) => label.label_id );

		setSelectedLabels( ( prevSelected ) => {
			const allSelected = allLabelIds.every( ( id ) =>
				prevSelected.has( id )
			);
			const newSelected = new Set( prevSelected );

			if ( allSelected ) {
				allLabelIds.forEach( ( id ) => newSelected.delete( id ) );
			} else {
				allLabelIds.forEach( ( id ) => newSelected.add( id ) );
			}

			return newSelected;
		} );
	}, [ labels ] );

	/**
	 * Review selected labels before creating ScanForm
	 */
	const reviewLabels = useCallback( async () => {
		if ( selectedLabels.size === 0 ) {
			setError(
				__(
					'Please select at least one label.',
					'woocommerce-shipping'
				)
			);
			return;
		}

		setIsReviewing( true );
		setError( null );
		setReviewResult( null );
		setMixedBatchNotice( null );

		try {
			const response = await apiFetch< ReviewApiResponse >( {
				path: getScanFormReviewPath(),
				method: 'POST',
				data: {
					label_ids: Array.from( selectedLabels ),
				},
			} );

			if ( response.success ) {
				const eligible = response.eligible ?? [];
				setReviewResult( {
					eligible,
					already_scanned: response.already_scanned ?? [],
					not_found: response.not_found ?? [],
					invalid_site: response.invalid_site ?? [],
					excluded_labels: response.excluded_labels ?? {},
				} );

				// Check if the eligible batch spans domestic and international.
				const { domestic, international } = splitLabelsByDestination(
					eligible,
					labels
				);
				if ( domestic.length > 0 && international.length > 0 ) {
					setMixedBatchNotice(
						__(
							'Your selected labels include both domestic and international shipments. This will create 2 USPS SCAN Forms: one for domestic labels and one for international labels.',
							'woocommerce-shipping'
						)
					);
				}

				setShowReviewStep( true );
			} else {
				setError(
					__( 'Failed to review labels.', 'woocommerce-shipping' )
				);
			}
		} catch ( err ) {
			const errorMessage =
				err instanceof Error
					? err.message
					: __( 'Failed to review labels.', 'woocommerce-shipping' );
			setError( errorMessage );
		} finally {
			setIsReviewing( false );
		}
	}, [ selectedLabels, labels ] );

	/**
	 * Create ScanForm from eligible labels (or specific label IDs if provided)
	 */
	const createScanForm = useCallback(
		async ( labelIds?: number[] ) => {
			const labelsToProcess = labelIds ?? reviewResult?.eligible ?? [];

			if ( labelsToProcess.length === 0 ) {
				setError(
					__(
						'No eligible labels to create SCAN Form.',
						'woocommerce-shipping'
					)
				);
				return;
			}

			setIsCreating( true );
			setError( null );
			setSuccessMessage( null );
			setFailedLabelsError( null );
			setPartialFailureMessage( null );
			setPartialFailureLabelIds( [] );
			setPdfUrls( [] );
			setProcessedLabelIds( [] );
			setProcessedLabelBatches( [] );

			// Split labels into domestic and international batches.
			const { domestic, international } = splitLabelsByDestination(
				labelsToProcess,
				labels
			);
			const batches: number[][] = [];
			if ( domestic.length > 0 ) {
				batches.push( domestic );
			}
			if ( international.length > 0 ) {
				batches.push( international );
			}

			const isSingleBatch = batches.length === 1;
			const successfulPdfs: string[] = [];
			const successfulBatches: number[][] = [];
			const allProcessedIds: number[] = [];
			const failedIds: number[] = [];
			const batchErrors: {
				message: string;
				failedLabels?: number[];
				validLabels?: number[];
			}[] = [];

			try {
				for ( const batch of batches ) {
					try {
						const response = await apiFetch< CreateApiResponse >( {
							path: getCreateScanFormPath(),
							method: 'POST',
							data: {
								label_ids: batch,
							},
						} );

						if ( response.success && response.scan_form ) {
							if ( response.scan_form.pdf_url ) {
								successfulPdfs.push(
									response.scan_form.pdf_url
								);
								successfulBatches.push( batch );
							}
							allProcessedIds.push( ...batch );
						} else {
							failedIds.push( ...batch );
							batchErrors.push( {
								message: __(
									'Failed to create SCAN Form.',
									'woocommerce-shipping'
								),
							} );
						}
					} catch ( err ) {
						const apiError = err as ScanFormApiError;
						// In single-batch mode, surface structured label errors via the retry modal.
						if (
							isSingleBatch &&
							apiError.data?.failed_labels &&
							apiError.data?.valid_labels
						) {
							setFailedLabelsError( apiError.data );
							return;
						}
						failedIds.push( ...batch );
						batchErrors.push( {
							message:
								apiError.data?.message ??
								( err instanceof Error ? err.message : null ) ??
								( err as { message?: string } ).message ??
								__(
									'Failed to create SCAN Form.',
									'woocommerce-shipping'
								),
							failedLabels: apiError.data?.failed_labels,
							validLabels: apiError.data?.valid_labels,
						} );
					}
				}

				if ( allProcessedIds.length > 0 ) {
					// At least one batch succeeded — show the completion step.
					setSuccessMessage(
						sprintf(
							/* translators: %d is number of labels */
							_n(
								'SCAN Form created successfully for %d label!',
								'SCAN Form created successfully for %d labels!',
								allProcessedIds.length,
								'woocommerce-shipping'
							),
							allProcessedIds.length
						)
					);
					setPdfUrls( successfulPdfs );
					setProcessedLabelIds( allProcessedIds );
					setProcessedLabelBatches( successfulBatches );
					setShowReviewStep( false );
					setShowCompletedStep( true );

					if ( batchErrors.length > 0 ) {
						// Surface partial-failure details inside the completion step.
						// Dedupe messages so identical errors across batches aren't repeated,
						// then join with a separator so every distinct batch error is visible.
						const uniqueMessages = Array.from(
							new Set( batchErrors.map( ( e ) => e.message ) )
						);
						setPartialFailureLabelIds( failedIds );
						setPartialFailureMessage(
							[
								sprintf(
									/* translators: %d is number of labels that failed */
									_n(
										'%d label could not be added to a SCAN Form and will need to be retried.',
										'%d labels could not be added to a SCAN Form and will need to be retried.',
										failedIds.length,
										'woocommerce-shipping'
									),
									failedIds.length
								),
								...uniqueMessages,
							].join( ' ' )
						);
					}
				} else if ( batchErrors.length > 0 ) {
					// No batch succeeded — keep merchant on the review step to retry.
					// Join all distinct batch errors so per-batch context isn't lost.
					const uniqueMessages = Array.from(
						new Set( batchErrors.map( ( e ) => e.message ) )
					);
					setError( uniqueMessages.join( ' ' ) );
				}
			} finally {
				setIsCreating( false );
			}
		},
		[ reviewResult, labels ]
	);

	/**
	 * Select an origin and proceed to label selection
	 */
	const selectOriginAndProceed = useCallback( ( origin: ScanFormOrigin ) => {
		setSelectedOrigin( origin );
		setError( null );
		setShowLabelSelectionStep( true );

		// Use labels from the origin (already fetched with origins)
		setLabels( origin.labels );

		// Auto-select all labels
		const allLabelIds = origin.labels.map( ( label ) => label.label_id );
		setSelectedLabels( new Set( allLabelIds ) );
	}, [] );

	/**
	 * Go back to origin selection step
	 */
	const goBackToOriginSelection = useCallback( () => {
		setShowLabelSelectionStep( false );
		setSelectedOrigin( null );
		setLabels( [] );
		setSelectedLabels( new Set() );
		setError( null );
	}, [] );

	/**
	 * Go back to label selection step
	 */
	const goBackToLabelSelection = useCallback( () => {
		setShowReviewStep( false );
		setReviewResult( null );
		setMixedBatchNotice( null );
	}, [] );

	/**
	 * Get label info by label ID
	 */
	const getLabelInfo = useCallback(
		( labelId: number ): ScanFormLabel | null =>
			labelMap.get( labelId ) ?? null,
		[ labelMap ]
	);

	/**
	 * Retry creating ScanForm with only valid labels (after some labels failed)
	 */
	const retryWithValidLabels = useCallback( () => {
		if ( ! failedLabelsError?.valid_labels ) {
			return;
		}

		// Dismiss the error and retry with valid labels
		setFailedLabelsError( null );
		createScanForm( failedLabelsError.valid_labels );
	}, [ failedLabelsError, createScanForm ] );

	/**
	 * Dismiss the failed labels error dialog
	 */
	const dismissFailedLabelsError = useCallback( () => {
		setFailedLabelsError( null );
	}, [] );

	/**
	 * Set selected labels directly (for DataViews integration)
	 */
	const setLabelSelection = useCallback( ( labelIds: number[] ) => {
		setSelectedLabels( new Set( labelIds ) );
	}, [] );

	/**
	 * Set selected origin.
	 */
	const setOriginSelection = useCallback( ( origin: ScanFormOrigin ) => {
		setSelectedOrigin( origin );
	}, [] );

	/**
	 * Get domestic/international counts for the selected labels.
	 */
	const getSelectionCounts = useCallback( () => {
		const { domestic, international } = splitLabelsByDestination(
			Array.from( selectedLabels ),
			labels
		);
		return {
			domestic: domestic.length,
			international: international.length,
		};
	}, [ selectedLabels, labels ] );

	return {
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
		failedLabelsError,
		excludedLabels,
		mixedBatchNotice,
		partialFailureMessage,
		partialFailureLabelIds,
		showLabelSelectionStep,
		showReviewStep,
		showCompletedStep,

		// Actions
		fetchOrigins,
		toggleLabel,
		toggleAllLabels,
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
		setError,
	};
};
