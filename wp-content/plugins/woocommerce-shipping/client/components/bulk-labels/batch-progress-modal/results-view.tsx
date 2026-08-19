import { Button, Notice } from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';
import { useCallback, useState } from '@wordpress/element';
import { Icon, check, error as errorIcon, external } from '@wordpress/icons';
import {
	formatCurrency,
	getCurrencyObject,
} from 'components/label-purchase/design-next/utils';
import {
	BulkPrintDialog,
	type BulkPrintDialogPrintResult,
} from '../bulk-print-dialog';
import type { FailedRow, SettledRow, SucceededRow } from './types';
import {
	BATCH_INTERRUPTED_ERROR_CODE,
	BATCH_TRANSPORT_ERROR_CODE,
} from './types';
import {
	failedRows,
	formatFailureMessage,
	getEditOrderUrl,
	orderLabel,
} from './helpers';

/**
 * Batch-level error codes mark per-row failures that were not caused by
 * the order itself (server unreachable, merchant aborted mid-progress).
 * Opening the order edit page for those does not help the merchant fix
 * anything, so the per-row "Fix and retry" affordance is suppressed and
 * a top-level hint is shown instead.
 */
const isBatchLevelFailure = ( row: FailedRow ): boolean =>
	row.error_code === BATCH_TRANSPORT_ERROR_CODE ||
	row.error_code === BATCH_INTERRUPTED_ERROR_CODE;

interface ResultsViewProps {
	rows: SettledRow[];
	onClose: () => void;
	autoPrintSuccessfulLabels?: boolean;
}

const getResultsTitle = (
	hasSucceeded: boolean,
	hasFailed: boolean
): string => {
	if ( hasSucceeded && hasFailed ) {
		return __( 'Some labels need attention', 'woocommerce-shipping' );
	}
	if ( hasSucceeded ) {
		return __( 'All labels created', 'woocommerce-shipping' );
	}
	return __( 'No labels were created', 'woocommerce-shipping' );
};

/**
 * Per-order print error state. Keyed by `order_id` so a second print
 * attempt on a different row doesn't stomp the error from the first.
 * The `Print all labels` button uses the synthetic `__all__` key so
 * its error doesn't collide with a single-row retry.
 */
type PrintErrorMap = Record< string, string >;

const PRINT_ALL_KEY = '__all__';

export const ResultsView = ( {
	rows,
	onClose,
	autoPrintSuccessfulLabels = false,
}: ResultsViewProps ) => {
	const succeeded = rows.filter(
		( r ): r is SucceededRow => r.status === 'succeeded'
	);
	const failed = failedRows( rows );
	const allLabelRefs = succeeded.flatMap( ( r ) => r.label_refs );

	const hasSucceeded = succeeded.length > 0;
	const hasFailed = failed.length > 0;

	const [ printErrors, setPrintErrors ] = useState< PrintErrorMap >( {} );

	const clearPrintError = useCallback( ( key: string ) => {
		setPrintErrors( ( prev ) => {
			if ( ! ( key in prev ) ) {
				return prev;
			}
			const next = { ...prev };
			delete next[ key ];
			return next;
		} );
	}, [] );

	const handlePrintResult = useCallback(
		( key: string, result: BulkPrintDialogPrintResult ) => {
			if ( result.ok ) {
				clearPrintError( key );
				return;
			}
			setPrintErrors( ( prev ) => ( {
				...prev,
				[ key ]: result.messages[ 0 ],
			} ) );
		},
		[ clearPrintError ]
	);

	const currency = getCurrencyObject().code;
	const printAllError = printErrors[ PRINT_ALL_KEY ];

	return (
		<div className="bulk-batch-progress-modal__results">
			<h2
				className="bulk-batch-progress-modal__title"
				id="bulk-batch-progress-modal-title"
			>
				{ getResultsTitle( hasSucceeded, hasFailed ) }
			</h2>

			{ printAllError !== undefined && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => clearPrintError( PRINT_ALL_KEY ) }
				>
					{ printAllError }
				</Notice>
			) }

			{ hasSucceeded && (
				<section className="bulk-batch-progress-modal__section bulk-batch-progress-modal__section--succeeded">
					<header className="bulk-batch-progress-modal__section-header">
						<h3 className="bulk-batch-progress-modal__section-heading">
							<Icon icon={ check } size={ 18 } />
							{ sprintf(
								/* translators: %d: total number of labels printed across all succeeded orders (an order can produce more than one) */
								_n(
									'%d label created',
									'%d labels created',
									allLabelRefs.length,
									'woocommerce-shipping'
								),
								allLabelRefs.length
							) }
						</h3>
						<BulkPrintDialog
							labelRefs={ allLabelRefs }
							autoPrint={ autoPrintSuccessfulLabels }
							buttonLabel={ _n(
								'Print label',
								'Print all labels',
								allLabelRefs.length,
								'woocommerce-shipping'
							) }
							className="bulk-batch-progress-modal__print-all"
							onPrintResult={ ( result ) =>
								handlePrintResult( PRINT_ALL_KEY, result )
							}
						/>
					</header>
					<ul className="bulk-batch-progress-modal__order-list">
						{ succeeded.map( ( row ) => {
							const rowKey = String( row.order_id );
							const rowError = printErrors[ rowKey ];
							return (
								<li
									key={ row.order_id }
									className="bulk-batch-progress-modal__order-row"
								>
									<div className="bulk-batch-progress-modal__order-meta">
										<span className="bulk-batch-progress-modal__order-number">
											{ orderLabel( row ) }
										</span>
										{ row.customer_name && (
											<span className="bulk-batch-progress-modal__order-customer">
												{ row.customer_name }
											</span>
										) }
										{ rowError !== undefined && (
											<span className="bulk-batch-progress-modal__order-print-error">
												{ rowError }
											</span>
										) }
									</div>
									<div className="bulk-batch-progress-modal__order-cost">
										{ formatCurrency( row.cost, currency ) }
									</div>
									<BulkPrintDialog
										labelRefs={ row.label_refs }
										buttonLabel={ __(
											'Print',
											'woocommerce-shipping'
										) }
										buttonVariant="secondary"
										className="bulk-batch-progress-modal__print-row"
										onPrintResult={ ( result ) =>
											handlePrintResult( rowKey, result )
										}
									/>
								</li>
							);
						} ) }
					</ul>
				</section>
			) }

			{ hasFailed && (
				<section className="bulk-batch-progress-modal__section bulk-batch-progress-modal__section--failed">
					<header className="bulk-batch-progress-modal__section-header">
						<h3 className="bulk-batch-progress-modal__section-heading">
							<Icon icon={ errorIcon } size={ 18 } />
							{ sprintf(
								/* translators: %d: number of failed orders */
								_n(
									'%d order needs a fix',
									'%d orders need a fix',
									failed.length,
									'woocommerce-shipping'
								),
								failed.length
							) }
						</h3>
					</header>
					<Notice status="warning" isDismissible={ false }>
						{ failed.every( isBatchLevelFailure )
							? __(
									'The batch did not finish. Close this dialog and start the batch again from the orders list.',
									'woocommerce-shipping'
							  )
							: __(
									'Open each failed order to resolve the issue, then come back here and try the batch again.',
									'woocommerce-shipping'
							  ) }
					</Notice>
					<ul className="bulk-batch-progress-modal__order-list">
						{ failed.map( ( row ) => (
							<li
								key={ row.order_id }
								className="bulk-batch-progress-modal__order-row bulk-batch-progress-modal__order-row--failed"
							>
								<div className="bulk-batch-progress-modal__order-meta">
									<span className="bulk-batch-progress-modal__order-number">
										{ orderLabel( row ) }
									</span>
									{ row.customer_name && (
										<span className="bulk-batch-progress-modal__order-customer">
											{ row.customer_name }
										</span>
									) }
									<span className="bulk-batch-progress-modal__order-error">
										{ formatFailureMessage( row ) }
									</span>
								</div>
								{ /* Suppress "Fix and retry" for batch-level
								   failures (transport / merchant-cancel):
								   nothing on the order edit page can fix a
								   server outage or a closed modal, so the
								   per-row link would be a dead end. The
								   top-level Notice already tells the
								   merchant what to do next in that case. */ }
								{ ! isBatchLevelFailure( row ) && (
									<Button
										variant="secondary"
										href={ getEditOrderUrl( row.order_id ) }
										target="_blank"
										rel="noopener noreferrer"
										icon={ external }
										iconPosition="right"
									>
										{ __(
											'Fix and retry',
											'woocommerce-shipping'
										) }
									</Button>
								) }
							</li>
						) ) }
					</ul>
				</section>
			) }

			<div className="bulk-batch-progress-modal__results-actions">
				<Button variant="primary" onClick={ onClose }>
					{ __( 'Done', 'woocommerce-shipping' ) }
				</Button>
			</div>
		</div>
	);
};
