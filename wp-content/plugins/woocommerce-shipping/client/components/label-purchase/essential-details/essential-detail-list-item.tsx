import './style.scss';
import { Icon } from '@wordpress/components';
import { check } from '@wordpress/icons';
import dot from './icons/dot';
import React, { ReactNode } from 'react';

interface EssentialDetailListItemProps {
	isCompleted: boolean;
	children?: ReactNode;
}

const EssentialDetailListItem = ( {
	isCompleted,
	children,
}: EssentialDetailListItemProps ) => {
	return (
		<li>
			{ isCompleted ? (
				<Icon
					icon={ check }
					fill="#4AB866"
					className="essential-details__icon--completed"
				/>
			) : (
				<Icon
					icon={ dot }
					fill="#D9D9D9"
					className="essential-details__icon--not-completed"
				/>
			) }
			<p className="essential-details__text">{ children }</p>
		</li>
	);
};

export default EssentialDetailListItem;
