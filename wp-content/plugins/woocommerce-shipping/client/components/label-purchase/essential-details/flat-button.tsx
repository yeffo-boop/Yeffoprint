import './style.scss';
import { Button } from '@wordpress/components';
import React, { ReactNode, MouseEventHandler } from 'react';

interface FlatButtonProps {
	onClick: MouseEventHandler;
	children?: ReactNode;
}

const FlatButton = ( { onClick, children }: FlatButtonProps ) => {
	return (
		<Button onClick={ onClick } className="essential-details-cta__button">
			{ children }
		</Button>
	);
};

export default FlatButton;
