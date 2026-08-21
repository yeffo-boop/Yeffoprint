import React from 'react';
import { Icon } from '@wordpress/components';
import './style.scss';

interface StatusIconProps {
	isSuccessful: boolean;
}

export const StatusIcon: React.FC< StatusIconProps > = ( props ) => {
	if ( props.isSuccessful ) {
		return (
			<Icon icon="yes" className="health-status-card__icon--success" />
		);
	}
	return <Icon icon="no" className="health-status-card__icon--error" />;
};
