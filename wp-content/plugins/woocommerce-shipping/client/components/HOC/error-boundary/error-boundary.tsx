import React, {
	Component,
	Children,
	ReactNode,
	ErrorInfo,
	ComponentType,
} from 'react';
import * as Sentry from '@sentry/react';
import { getComponentDisplayName } from 'utils';
import { __ } from '@wordpress/i18n';
import { createInterpolateElement } from '@wordpress/element';
import { ExternalLink } from '@wordpress/components';

interface ErrorBoundaryState {
	hasError: boolean;
	error?: string | null;
	componentStack?: ErrorInfo | null;
	eventId?: string;
}

export interface ErrorBoundaryProps {
	onError?: ( state: ErrorBoundaryState ) => ReactNode;
	children?: ReactNode;
}

export class ErrorBoundary extends Component<
	ErrorBoundaryProps,
	ErrorBoundaryState
> {
	constructor( props: ErrorBoundaryProps ) {
		super( props );
		this.state = {
			hasError: false,
			error: null,
			componentStack: null,
			eventId: '',
		};
	}

	static getDerivedStateFromError(
		error: Error & {
			fileName?: string;
		}
	) {
		return {
			hasError: true,
			error: `${ error?.toString() } \nFile name: ${
				error?.fileName ?? 'No fileName reported'
			} \nStack trace: ${ error?.stack ?? 'No stack trace reported' }`,
		};
	}

	componentDidCatch( error: Error, componentStack: ErrorInfo ) {
		// eslint-disable-next-line no-console
		console.warn( 'logging', { error, componentStack } );

		// Log captured error with info to Sentry.
		Sentry.withScope( () => {
			const eventId = Sentry.captureException( error, {
				captureContext: {
					contexts: { react: { componentStack } },
				},
			} );
			this.setState( { eventId, componentStack } );
		} );
	}

	render() {
		const { onError, children } = this.props;
		const { hasError, error } = this.state;
		if ( hasError ) {
			if ( onError ) {
				return onError( this.state );
			}

			return (
				<>
					<h4>
						{ createInterpolateElement(
							__(
								'The component <mark/> encountered an error',
								'woocommerce-shipping'
							),
							{
								mark: (
									<mark>
										{ Children.map<
											ReactNode,
											ComponentType | unknown
										>( children, ( child ) =>
											getComponentDisplayName(
												// it's safe to cast here since the type is unknown and the function will return a string
												child as ComponentType
											)
										)?.join( ', ' ) }
									</mark>
								),
							}
						) }
					</h4>
					<p>
						{ createInterpolateElement(
							__(
								'Please report the following error by <feedbackform>submitting a crash report</feedbackform> or contacting <a>WooCommerce Shipping Support</a>',
								'woocommerce-shipping'
							),
							{
								feedbackform: (
									// eslint-disable-next-line jsx-a11y/anchor-is-valid
									<a
										href="#"
										onClick={ ( e ) => {
											e.preventDefault();
											Sentry.showReportDialog( {
												eventId: this.state.eventId,
											} );
										} }
									>
										{ __(
											'submitting a crash report',
											'woocommerce-shipping'
										) }
									</a>
								),
								a: (
									<ExternalLink href="https://woocommerce.com/products/shipping/">
										{ __(
											'WooCommerce Shipping Support',
											'woocommerce-shipping'
										) }
									</ExternalLink>
								),
							}
						) }
					</p>
					<code
						style={ {
							whiteSpace: 'pre-wrap',
							wordWrap: 'break-word',
							overflowX: 'auto',
						} }
					>
						{ error }
					</code>
				</>
			);
		}
		return this.props.children;
	}
}
