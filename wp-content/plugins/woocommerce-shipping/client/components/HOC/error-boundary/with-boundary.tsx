import React, { ComponentType } from 'react';
import { ErrorBoundary, ErrorBoundaryProps } from './error-boundary';

export const withBoundary =
	< P extends Record< string, unknown > | object >(
		WrappedComponent: ComponentType< P >,
		props?: ErrorBoundaryProps
	) =>
	( displayName?: string ): ComponentType< P > => {
		if ( displayName ) {
			WrappedComponent.displayName = displayName;
		}
		return function bounder( boundProps: P ) {
			return (
				<ErrorBoundary { ...props }>
					<WrappedComponent { ...boundProps } />
				</ErrorBoundary>
			);
		};
	};
