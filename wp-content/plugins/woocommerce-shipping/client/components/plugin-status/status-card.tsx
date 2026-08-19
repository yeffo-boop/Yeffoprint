import React from 'react';
import { StatusIcon } from './status-icon';
import './style.scss';

interface StatusCardProps {
	name: string;
	isSuccessful: boolean;
	message: string;
}

export const StatusCard: React.FC< StatusCardProps > = ( props ) => {
	return (
		<>
			<h3>{ props.name }</h3>
			<StatusIcon isSuccessful={ props.isSuccessful } />
			{ props.message }
		</>
	);
};
