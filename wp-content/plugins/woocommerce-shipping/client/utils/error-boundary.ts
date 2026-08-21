import { ComponentType, ReactElement } from 'react';

type ReactElementWithDisplayOrName = ReactElement & {
	type: {
		displayName?: string;
		name?: string;
	};
};

const isReactElement = < P >(
	component: ComponentType< P > | ReactElement< P >
): component is ReactElementWithDisplayOrName => {
	// @ts-ignore
	return component.type !== undefined;
};

const isComponentType = < P >(
	component: ComponentType< P > | ReactElement< P >
): component is ComponentType< P > => {
	return ! isReactElement( component );
};

export const getComponentDisplayName = < P >(
	component: ComponentType< P > | ReactElement< P >
): string => {
	if ( isReactElement( component ) ) {
		return component.type.displayName ?? component.type.name ?? 'Unknown';
	}

	if ( isComponentType( component ) ) {
		return component.displayName ?? component.name ?? 'Unknown';
	}

	return 'Unknown';
};
