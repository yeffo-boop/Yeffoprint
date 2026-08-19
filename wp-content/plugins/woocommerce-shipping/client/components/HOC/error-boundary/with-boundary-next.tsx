import { ComponentType } from 'react';
import { ErrorBoundaryNext } from './error-boundary-next';

/**
 * Higher-Order Component that wraps a component with ErrorBoundaryNext.
 *
 * This HOC ensures that errors occurring anywhere in the wrapped component
 * (including hooks, lifecycle methods, and render logic) are caught by the
 * error boundary.
 *
 * @example
 * // Basic usage
 * const SafeComponent = withBoundaryNext(MyComponent)();
 *
 * // With custom display name
 * const SafeComponent = withBoundaryNext(MyComponent)('MyComponentWithBoundary');
 *
 * @param WrappedComponent - The component to wrap with error boundary
 * @return A function that optionally accepts a display name and returns the wrapped component
 */
export const withBoundaryNext =
	< P extends Record< string, unknown > | object >(
		WrappedComponent: ComponentType< P >
	) =>
	( displayName?: string ): ComponentType< P > => {
		const ComponentWithBoundary = ( props: P ) => {
			return (
				<ErrorBoundaryNext>
					<WrappedComponent { ...props } />
				</ErrorBoundaryNext>
			);
		};

		// Set display name for debugging
		// Use || instead of ?? to handle empty strings (anonymous components have name = '')
		/* eslint-disable @typescript-eslint/prefer-nullish-coalescing */
		const wrappedName =
			displayName ||
			WrappedComponent.displayName ||
			WrappedComponent.name ||
			'Component';
		ComponentWithBoundary.displayName = `withBoundaryNext(${ wrappedName })`;
		/* eslint-enable @typescript-eslint/prefer-nullish-coalescing */
		return ComponentWithBoundary;
	};
