import { Label } from 'types';
import { LABEL_PURCHASE_STATUS } from '../../data/constants';

export const getRefundDuration = ( label: Label ) =>
	label.carrierId === 'dhlexpress' ? '31' : '14';

export const hasLabelExpired = ( label?: Label ) => {
	if ( ! label ) {
		return true;
	}

	const { status, usedDate, expiryDate } = label;

	return [
		status === LABEL_PURCHASE_STATUS.ANONYMIZED,
		Boolean( usedDate ),
		expiryDate < new Date().getTime(),
	].some( ( value ) => value );
};
export const canRefundLabel = ( label?: Label ) => {
	if ( ! label ) {
		return false;
	}

	const { createdDate, carrierId, tracking } = label;

	const thirtyDaysAgo = new Date().setDate( new Date().getDate() - 30 );
	if ( createdDate < thirtyDaysAgo ) {
		return false;
	}

	return [
		hasLabelExpired( label ),
		carrierId === 'usps' && ! tracking,
		createdDate < thirtyDaysAgo,
	].every( ( value ) => ! value );
};
