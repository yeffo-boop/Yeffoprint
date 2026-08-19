import { Icon, Spinner } from '@wordpress/components';
import clsx from 'clsx';
import { STEP_STATUS } from 'components/label-purchase/step-status';

const renderSummaryText = () => {
	return '';
};

export const StepContainerSummary = ( { status } ) => {
	const getIcon = () => {
		if ( status === STEP_STATUS.SUCCESS ) {
			return 'check';
		}
		if ( status === STEP_STATUS.WARNING ) {
			return 'warning';
		}
		if ( status === STEP_STATUS.ERROR ) {
			return 'info';
		}
		return '';
	};
	const className = clsx( {
		'is-success': status === STEP_STATUS.SUCCESS,
		'is-warning': status === STEP_STATUS.WARNING,
		'is-error': status === STEP_STATUS.ERROR,
	} );

	return (
		<span className={ className }>
			<span>{ renderSummaryText() }</span>
			<div className="label-purchase-modal__step-status">
				{ status === STEP_STATUS.IN_PROGRESS ? (
					<Spinner size={ 18 } />
				) : (
					<Icon
						icon={ getIcon() }
						className={ className }
						size={ 18 }
					/>
				) }
			</div>
		</span>
	);
};
