import { ProgressBar } from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';
import { Icon, check, error as errorIcon } from '@wordpress/icons';
import type { OrderRow, OrderProgressStatus } from './types';
import { assertNever, orderLabel } from './helpers';

interface ProgressViewProps {
	rows: OrderRow[];
}

const renderStatusIcon = ( status: OrderProgressStatus ) => {
	switch ( status ) {
		case 'succeeded':
			return <Icon icon={ check } size={ 16 } />;
		case 'failed':
			return <Icon icon={ errorIcon } size={ 16 } />;
		case 'pending':
			return (
				<span
					aria-hidden="true"
					className="bulk-batch-progress-modal__pending-dot"
				/>
			);
		default:
			return assertNever( status );
	}
};

const renderStatusLabel = ( status: OrderProgressStatus ): string => {
	switch ( status ) {
		case 'succeeded':
			return __( 'Label created', 'woocommerce-shipping' );
		case 'failed':
			return __( 'Failed', 'woocommerce-shipping' );
		case 'pending':
			return __( 'Waiting…', 'woocommerce-shipping' );
		default:
			return assertNever( status );
	}
};

export const ProgressView = ( { rows }: ProgressViewProps ) => {
	const total = rows.length;
	const settled = rows.filter( ( r ) => r.status !== 'pending' ).length;
	const percent = total > 0 ? Math.round( ( settled / total ) * 100 ) : 0;
	const progressLabel = sprintf(
		/* translators: 1: number of orders processed, 2: total order count */
		__( 'Processed %1$d of %2$d orders.', 'woocommerce-shipping' ),
		settled,
		total
	);

	return (
		<div className="bulk-batch-progress-modal__progress">
			<h2 className="bulk-batch-progress-modal__title">
				{ __( 'Creating labels…', 'woocommerce-shipping' ) }
			</h2>
			<p className="bulk-batch-progress-modal__intro">
				{ progressLabel }
			</p>
			{ /*
			 * `<ProgressBar>` renders a native `<progress>` and forwards
			 * the rest of its props, so ARIA attributes go on it
			 * directly. Wrapping it in another `role="progressbar"`
			 * would announce two progressbars to screen readers.
			 */ }
			<ProgressBar
				value={ percent }
				className="bulk-batch-progress-modal__bar"
				aria-label={ __(
					'Bulk label purchase progress',
					'woocommerce-shipping'
				) }
				aria-valuetext={ progressLabel }
			/>

			<ul
				aria-live="polite"
				aria-atomic="false"
				className="bulk-batch-progress-modal__list"
			>
				{ rows.map( ( row ) => (
					<li
						key={ row.order_id }
						className={ `bulk-batch-progress-modal__list-item bulk-batch-progress-modal__list-item--${ row.status }` }
					>
						<span className="bulk-batch-progress-modal__list-status">
							{ renderStatusIcon( row.status ) }
						</span>
						<span className="bulk-batch-progress-modal__list-order">
							{ orderLabel( row ) }
							{ row.customer_name && (
								<span className="bulk-batch-progress-modal__list-customer">
									{ ' ' }
									{ row.customer_name }
								</span>
							) }
						</span>
						<span className="bulk-batch-progress-modal__list-state">
							{ renderStatusLabel( row.status ) }
						</span>
					</li>
				) ) }
			</ul>

			<p className="bulk-batch-progress-modal__footnote">
				{ _n(
					'Keep this window open. Closing it now cancels any unfinished label.',
					'Keep this window open. Closing it now cancels any unfinished labels.',
					total - settled,
					'woocommerce-shipping'
				) }
			</p>
		</div>
	);
};
