/**
 * Label Selection Step - Step 2 of ScanForm creation
 */

import { Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useCallback, useMemo } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews/wp';
import type { ScanFormOrigin, ScanFormLabel } from 'types';
import type { Field } from '@wordpress/dataviews/wp';
import { addressToString } from 'utils';

interface LabelSelectionStepProps {
	selectedOrigin: ScanFormOrigin;
	labels: ScanFormLabel[];
	selectedLabels: Set< number >;
	onSetSelection: ( labelIds: number[] ) => void;
}

export const LabelSelectionStep = ( {
	selectedOrigin,
	labels,
	selectedLabels,
	onSetSelection,
}: LabelSelectionStepProps ) => {
	/**
	 * Format date for display
	 */
	const formatLabelDate = useCallback( ( dateString: string ): string => {
		if ( ! dateString ) {
			return '';
		}
		return new Date( dateString ).toLocaleDateString( 'en-US', {
			month: 'short',
			day: 'numeric',
			year: 'numeric',
		} );
	}, [] );

	/**
	 * Convert parent's Set<number> selection to DataViews' string[] format
	 * Use useMemo so it only recalculates when selectedLabels actually changes
	 */
	const selection = useMemo(
		() => Array.from( selectedLabels ).map( String ),
		[ selectedLabels ]
	);

	/**
	 * Handle selection changes from DataViews
	 * Simply convert string[] to number[] and set the selection
	 */
	const handleSelectionChange = useCallback(
		( newSelection: string[] ) => {
			const labelIds = newSelection.map( ( id ) => Number( id ) );
			onSetSelection( labelIds );
		},
		[ onSetSelection ]
	);

	// Define actions for DataViews (required for checkboxes to appear)
	const actions = useMemo(
		() => [
			{
				id: 'select-labels',
				label: __( 'Select labels', 'woocommerce-shipping' ),
				supportsBulk: true,
				callback: () => {
					// This action is just to enable checkboxes
					// The actual selection is handled by onChangeSelection
				},
			},
		],
		[]
	);

	// Define fields for DataViews
	const fields = useMemo< Field< ScanFormLabel >[] >(
		() => [
			{
				id: 'label_info',
				label: sprintf(
					/* translators: %d is number of eligible labels */
					_n(
						'Select All (%d Eligible label)',
						'Select All (%d Eligible labels)',
						labels.length,
						'woocommerce-shipping'
					),
					labels.length
				),
				enableSorting: false,
				enableHiding: false,
				filterBy: false,
				render: ( { item }: { item: ScanFormLabel } ) => {
					const labelTitle = sprintf(
						/* translators: %s is order number */
						__( '#%s', 'woocommerce-shipping' ),
						item.order_number
					);

					// Remove "USPS - " prefix from service name if it exists
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
		<>
			<p className="scan-form-modal__step-description">
				{ __(
					'Select the USPS labels you want to include in this SCAN Form. Only labels with the same ship-from address are shown here.',
					'woocommerce-shipping'
				) }
			</p>

			<Notice status="warning" isDismissible={ false }>
				{ __(
					'After creation, SCAN Form cannot be edited or updated.',
					'woocommerce-shipping'
				) }
			</Notice>

			<p className="scan-form-modal__ship-from">
				{ __( 'Ship from:', 'woocommerce-shipping' ) }{ ' ' }
				{ addressToString( selectedOrigin.origin_address ) }
			</p>

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
						fields: [ 'label_info', 'ship_date', 'label_created' ],
					} }
					fields={ fields }
					data={ labels }
					isLoading={ false }
					onChangeView={ () => {} } // eslint-disable-line @typescript-eslint/no-empty-function
					selection={ selection }
					onChangeSelection={ handleSelectionChange }
					isItemClickable={ () => false }
					actions={ actions }
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
					getItemId={ ( item: ScanFormLabel ) =>
						String( item.label_id )
					}
				>
					<DataViews.Layout />
				</DataViews>
			</div>
		</>
	);
};
