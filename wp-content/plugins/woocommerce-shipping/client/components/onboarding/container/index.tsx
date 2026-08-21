import React, { ReactNode } from 'react';
import {
	__experimentalElevation as Elevation,
	__experimentalSurface as Surface,
	Flex,
	FlexItem,
} from '@wordpress/components';
import { useViewportMatch } from '@wordpress/compose';

import './style.scss';
import fallbackImage from 'images/wcshipping-fallback.jpg';

interface ContainerProps {
	imageSrc?: string;
	imageBackground?: string;
	children: ReactNode;
}

const Container: React.FC< ContainerProps > = ( {
	imageSrc,
	imageBackground,
	children,
} ) => {
	const isMobile = useViewportMatch( 'large', '<' );

	return (
		<Surface className="wcshipping-onboarding-container">
			<Flex
				className="wcshipping-onboarding-container__row"
				direction={ isMobile ? 'column' : 'row' }
			>
				<FlexItem className="wcshipping-onboarding-container__content">
					{ children }
				</FlexItem>
				<FlexItem
					display="flex"
					className="wcshipping-onboarding-container__media"
					style={ {
						background: imageBackground ?? '#F2EDFF',
					} }
				>
					<img
						className="wcshipping-onboarding-container__image"
						src={ imageSrc ?? fallbackImage }
						alt=""
					/>
				</FlexItem>
			</Flex>
			<Elevation value={ 3 } />
		</Surface>
	);
};

export default Container;
