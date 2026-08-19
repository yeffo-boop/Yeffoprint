/**
 * ScanForm Labels Preview Modal
 * Displays a view of all labels included in a specific ScanForm.
 */

import { Button, Flex, FlexItem, Modal, Spinner } from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';
import { useEffect, useState, useCallback, useMemo } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews/wp';
import apiFetch from '@wordpress/api-fetch';
import type { Field } from '@wordpress/dataviews/wp';
import type {
	ScanFormLabelsModalProps,
	ScanFormLabelsResponse,
	ScanFormLabel,
} from 'types';
import './style.scss';
import { getScanFormLabelsPath } from 'data/routes';

export const ScanFormLabelsModal = ( {
	scanForm,
	onClose,
}: ScanFormLabelsModalProps ) => {
	const [ labels, setLabels ] = useState< ScanFormLabel[] >( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	// Fetch labels for this scanform on mount.
	useEffect( () => {
		const fetchLabels = async () => {
			setIsLoading( true );
			setError( null );

			try {
				const response = await apiFetch< ScanFormLabelsResponse >( {
					path: getScanFormLabelsPath( scanForm.scan_form_id ),
					method: 'GET',
				} );

				if ( response.success && response.labels ) {
					setLabels( response.labels );
				} else {
					setError(
						__( 'Failed to fetch labels.', 'woocommerce-shipping' )
					);
				}
			} catch ( err ) {
				setError(
					err instanceof Error
						? err.message
						: __(
								'Failed to fetch labels.',
								'woocommerce-shipping'
						  )
				);
			} finally {
				setIsLoading( false );
			}
		};

		void fetchLabels();
	}, [ scanForm.scan_form_id ] );

	/**
	 * Format date for display
	 */
	const formatLabelDate = useCallback( ( dateString: string ): string => {
		if ( ! dateString || dateString === '-' ) {
			return '';
		}
		return new Date( dateString ).toLocaleDateString( 'en-US', {
			month: 'short',
			day: 'numeric',
			year: 'numeric',
		} );
	}, [] );

	// Define fields for DataViews (reusing pattern from label-selection-step).
	const fields = useMemo< Field< ScanFormLabel >[] >(
		() => [
			{
				id: 'label_info',
				label: sprintf(
					// translators: %d: Number of labels
					_n(
						'%d Label',
						'%d Labels',
						labels.length,
						'woocommerce-shipping'
					),
					labels.length
				).toUpperCase(),
				enableSorting: false,
				enableHiding: false,
				filterBy: false,
				render: ( { item }: { item: ScanFormLabel } ) => {
					const labelTitle = sprintf(
						// translators: %s: Order number
						__( '#%s', 'woocommerce-shipping' ),
						item.order_number
					);

					// Remove "USPS - " prefix from service name if it exists.
					const serviceName = item.service_name.replace(
						/^USPS - /i,
						''
					);

					return (
						<div>
							<div className="scan-form-modal__label-title">
								{ labelTitle }
							</div>
							<div className="scan-form-modal__label-service">
								{ serviceName } · { item.tracking }
							</div>
						</div>
					);
				},
			},
			{
				id: 'ship_date',
				label: __( 'Ship Date', 'woocommerce-shipping' ),
				type: 'text',
				enableSorting: false,
				enableHiding: false,
				filterBy: false,
				getValue: ( { item }: { item: ScanFormLabel } ) => {
					return formatLabelDate( item.shipping_date );
				},
			},
			{
				id: 'label_created',
				label: __( 'Label Created', 'woocommerce-shipping' ),
				type: 'text',
				enableSorting: false,
				enableHiding: false,
				filterBy: false,
				getValue: ( { item }: { item: ScanFormLabel } ) => {
					return formatLabelDate( item.created );
				},
			},
		],
		[ labels.length, formatLabelDate ]
	);

	return (
		<Modal
			title={ __( 'Labels', 'woocommerce-shipping' ) }
			onRequestClose={ onClose }
			className="scan-form-modal scan-form-labels-modal"
			shouldCloseOnClickOutside={ true }
		>
			{ /* Description */ }
			<p className="scan-form-modal__step-description">
				{ __(
					'This SCAN Form includes the following USPS labels. All labels share the same ship-from address and were created together as part of this SCAN Form.',
					'woocommerce-shipping'
				) }
			</p>

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

			{ /* Error State */ }
			{ ! isLoading && error && (
				<p style={ { color: '#d63638' } }>{ error }</p>
			) }

			{ /* Labels Table */ }
			{ ! isLoading && ! error && labels.length > 0 && (
				<div className="scan-form-modal__labels-container">
					<DataViews< ScanFormLabel >
						view={ {
							type: 'table',
							layout: {
								enableMoving: false,
								styles: {
									label_info: {
										width: '60%',
									},
									ship_date: {
										width: '20%',
									},
									label_created: {
										width: '20%',
									},
								},
							},
							fields: [
								'label_info',
								'ship_date',
								'label_created',
							],
						} }
						fields={ fields }
						data={ labels }
						isLoading={ false }
						onChangeView={ () => {
							// read-only modal.
						} }
						getItemId={ ( item: ScanFormLabel ) =>
							String( item.label_id )
						}
						search={ false }
						defaultLayouts={ {
							table: {
								showMedia: false,
							},
						} }
						paginationInfo={ {
							totalItems: labels.length,
							totalPages: 1,
						} }
						isItemClickable={ () => true }
					>
						<DataViews.Layout />
					</DataViews>
				</div>
			) }

			{ /* Empty State */ }
			{ ! isLoading && ! error && labels.length === 0 && (
				<p>
					{ __(
						'No labels found for this SCAN Form.',
						'woocommerce-shipping'
					) }
				</p>
			) }

			{ /* Footer */ }
			<Flex justify="flex-end" align="flex-end" as="footer">
				<FlexItem>
					<Button variant="primary" onClick={ onClose }>
						{ __( 'Close', 'woocommerce-shipping' ) }
					</Button>
				</FlexItem>
			</Flex>
		</Modal>
	);
};
