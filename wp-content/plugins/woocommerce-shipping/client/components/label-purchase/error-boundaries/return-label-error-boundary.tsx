/**
 * Return Label Error Boundary
 *
 * Specialized error boundary for return label functionality that provides
 * context-specific error handling and recovery options for return shipments.
 */

import React, { Component, ReactNode, ErrorInfo } from 'react';
import * as Sentry from '@sentry/react';
import { __ } from '@wordpress/i18n';
import { createInterpolateElement } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { Link } from '@woocommerce/components';
import { info } from '@wordpress/icons';

interface ReturnLabelErrorBoundaryState {
	hasError: boolean;
	error?: Error | null;
	errorInfo?: ErrorInfo | null;
	eventId?: string;
	errorType?:
		| 'api_error'
		| 'validation_error'
		| 'network_error'
		| 'unknown_error';
	canRetry?: boolean;
	retryCount?: number;
}

export interface ReturnLabelErrorBoundaryProps {
	children: ReactNode;
	onError?: ( state: ReturnLabelErrorBoundaryState ) => void;
	onRetry?: () => void;
	fallbackComponent?: ReactNode;
	maxRetries?: number;
	showRetryButton?: boolean;
	contextInfo?: {
		shipmentId?: string;
		isDomestic?: boolean;
		operation?: 'create' | 'purchase' | 'validate' | 'address_check';
	};
}

/**
 * Specialized error boundary for return label operations
 */
export class ReturnLabelErrorBoundary extends Component<
	ReturnLabelErrorBoundaryProps,
	ReturnLabelErrorBoundaryState
> {
	private retryTimeoutId?: ReturnType< typeof setTimeout >;

	constructor( props: ReturnLabelErrorBoundaryProps ) {
		super( props );
		this.state = {
			hasError: false,
			error: null,
			errorInfo: null,
			eventId: '',
			errorType: 'unknown_error',
			canRetry: true,
			retryCount: 0,
		};
	}

	static getDerivedStateFromError(
		error: Error
	): Partial< ReturnLabelErrorBoundaryState > {
		// Analyze error to determine type and retry capability
		const errorType = ReturnLabelErrorBoundary.determineErrorType( error );
		const canRetry = ReturnLabelErrorBoundary.isRetryableError(
			error,
			errorType
		);

		return {
			hasError: true,
			error,
			errorType,
			canRetry,
		};
	}

	componentDidCatch( error: Error, errorInfo: ErrorInfo ) {
		const { contextInfo, onError } = this.props;

		// Enhanced logging for return label specific errors
		// Log error information to Sentry instead of console

		// Log to Sentry with return label specific context
		Sentry.withScope( ( scope ) => {
			scope.setTag( 'component', 'return-label' );
			scope.setTag( 'error_type', this.state.errorType );

			if ( contextInfo ) {
				scope.setContext( 'return_label_context', {
					shipmentId: contextInfo.shipmentId,
					isDomestic: contextInfo.isDomestic,
					operation: contextInfo.operation,
				} );
			}

			const eventId = Sentry.captureException( error, {
				captureContext: {
					contexts: {
						react: {
							componentStack: errorInfo.componentStack,
							...( errorInfo as Record< string, unknown > ),
						},
					},
				},
			} );

			this.setState( {
				eventId,
				errorInfo,
			} );
		} );

		// Call onError callback if provided
		if ( onError ) {
			onError( {
				...this.state,
				error,
				errorInfo,
			} );
		}
	}

	componentWillUnmount() {
		if ( this.retryTimeoutId ) {
			clearTimeout( this.retryTimeoutId );
		}
	}

	/**
	 * Determine the type of error for better handling
	 */
	private static determineErrorType(
		error: Error
	): ReturnLabelErrorBoundaryState[ 'errorType' ] {
		const message = error.message.toLowerCase();

		if ( message.includes( 'network' ) || message.includes( 'fetch' ) ) {
			return 'network_error';
		}

		if (
			message.includes( 'validation' ) ||
			message.includes( 'invalid' )
		) {
			return 'validation_error';
		}

		if (
			message.includes( 'api' ) ||
			message.includes( 'server' ) ||
			message.includes( '400' ) ||
			message.includes( '500' )
		) {
			return 'api_error';
		}

		return 'unknown_error';
	}

	/**
	 * Determine if error is retryable
	 */
	private static isRetryableError(
		error: Error,
		errorType: ReturnLabelErrorBoundaryState[ 'errorType' ]
	): boolean {
		// Network errors are usually retryable
		if ( errorType === 'network_error' ) {
			return true;
		}

		// Some API errors are retryable (5xx but not 4xx)
		if ( errorType === 'api_error' ) {
			const message = error.message;
			return (
				! message.includes( '400' ) &&
				! message.includes( '401' ) &&
				! message.includes( '403' )
			);
		}

		// Validation errors usually aren't retryable without user action
		if ( errorType === 'validation_error' ) {
			return false;
		}

		// Unknown errors might be retryable
		return true;
	}

	/**
	 * Get user-friendly error message based on error type
	 */
	private getErrorMessage(): string {
		const { errorType } = this.state;
		const { contextInfo } = this.props;

		switch ( errorType ) {
			case 'network_error':
				return __(
					'Unable to connect to the shipping service. Please check your internet connection and try again.',
					'woocommerce-shipping'
				);

			case 'api_error':
				return __(
					'The shipping service is temporarily unavailable. Please try again in a few minutes.',
					'woocommerce-shipping'
				);

			case 'validation_error':
				if ( contextInfo?.operation === 'address_check' ) {
					return __(
						'There was an issue validating the return address. Please verify the address details and try again.',
						'woocommerce-shipping'
					);
				}
				return __(
					'The return shipment information is invalid. Please check your selections and try again.',
					'woocommerce-shipping'
				);

			default:
				return __(
					'An unexpected error occurred while processing the return label. Please try again.',
					'woocommerce-shipping'
				);
		}
	}

	/**
	 * Get specific guidance based on error type and context
	 */
	private getErrorGuidance(): ReactNode {
		const { errorType } = this.state;
		const { contextInfo } = this.props;

		switch ( errorType ) {
			case 'network_error':
				return (
					<ul style={ { marginLeft: '20px', marginTop: '10px' } }>
						<li>
							{ __(
								'Check your internet connection',
								'woocommerce-shipping'
							) }
						</li>
						<li>
							{ __(
								'Ensure firewall settings allow shipping service access',
								'woocommerce-shipping'
							) }
						</li>
						<li>
							{ __(
								'Try refreshing the page',
								'woocommerce-shipping'
							) }
						</li>
					</ul>
				);

			case 'validation_error':
				if ( ! contextInfo?.isDomestic ) {
					return (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'Return labels are currently only available for domestic shipments (US to US).',
								'woocommerce-shipping'
							) }
						</Notice>
					);
				}
				return (
					<ul style={ { marginLeft: '20px', marginTop: '10px' } }>
						<li>
							{ __(
								'Verify all required fields are filled',
								'woocommerce-shipping'
							) }
						</li>
						<li>
							{ __(
								'Check that addresses are valid and complete',
								'woocommerce-shipping'
							) }
						</li>
						<li>
							{ __(
								'Ensure at least one item is selected for return',
								'woocommerce-shipping'
							) }
						</li>
					</ul>
				);

			case 'api_error':
				return (
					<Notice status="warning" isDismissible={ false }>
						{ createInterpolateElement(
							__(
								'This appears to be a temporary service issue. You can check the <status>service status page</status> or try again in a few minutes.',
								'woocommerce-shipping'
							),
							{
								status: (
									<Link
										href="https://status.woocommerce.com/"
										target="_blank"
										type="external"
									/>
								),
							}
						) }
					</Notice>
				);

			default:
				return null;
		}
	}

	/**
	 * Handle retry operation
	 */
	private handleRetry = () => {
		const { onRetry, maxRetries = 3 } = this.props;
		const { retryCount = 0 } = this.state;

		if ( retryCount >= maxRetries ) {
			this.setState( { canRetry: false } );
			return;
		}

		this.setState( {
			hasError: false,
			error: null,
			errorInfo: null,
			retryCount: retryCount + 1,
		} );

		// Call retry callback if provided
		if ( onRetry ) {
			// Delay retry slightly to prevent rapid-fire retries
			this.retryTimeoutId = setTimeout( () => {
				onRetry();
			}, 1000 );
		}
	};

	/**
	 * Render error fallback UI
	 */
	private renderErrorFallback(): ReactNode {
		const {
			fallbackComponent,
			showRetryButton = true,
			maxRetries = 3,
		} = this.props;
		const { canRetry, retryCount = 0 } = this.state;

		if ( fallbackComponent ) {
			return fallbackComponent;
		}

		const errorMessage = this.getErrorMessage();
		const errorGuidance = this.getErrorGuidance();
		const showRetry =
			showRetryButton && canRetry && retryCount < maxRetries;

		return (
			<div className="return-label-error-boundary">
				<Notice status="error" isDismissible={ false }>
					<div>
						<h4 style={ { margin: '0 0 10px 0' } }>
							{ __(
								'Return Label Error',
								'woocommerce-shipping'
							) }
						</h4>

						<p style={ { margin: '0 0 10px 0' } }>
							{ errorMessage }
						</p>

						{ errorGuidance }

						{ showRetry && (
							<div style={ { marginTop: '15px' } }>
								<Button
									variant="secondary"
									onClick={ this.handleRetry }
									icon={ info }
								>
									{ __(
										'Try Again',
										'woocommerce-shipping'
									) }
									{ retryCount > 0 &&
										` (${ retryCount }/${ maxRetries })` }
								</Button>
							</div>
						) }

						{ ! canRetry || retryCount >= maxRetries ? (
							<div style={ { marginTop: '15px' } }>
								<p>
									{ createInterpolateElement(
										__(
											'If this problem persists, please <support>contact support</support> with the error details below.',
											'woocommerce-shipping'
										),
										{
											support: (
												<Link
													href="https://woocommerce.com/products/shipping/"
													target="_blank"
													type="external"
												/>
											),
										}
									) }
								</p>

								<Button
									variant="link"
									onClick={ () => {
										if ( this.state.eventId ) {
											Sentry.showReportDialog( {
												eventId: this.state.eventId,
											} );
										}
									} }
								>
									{ __(
										'Report this error',
										'woocommerce-shipping'
									) }
								</Button>
							</div>
						) : null }

						{ process.env.NODE_ENV === 'development' &&
							this.state.error && (
								<details style={ { marginTop: '15px' } }>
									<summary>
										{ __(
											'Technical Details (Development)',
											'woocommerce-shipping'
										) }
									</summary>
									<pre
										style={ {
											background: '#f0f0f0',
											padding: '10px',
											borderRadius: '4px',
											fontSize: '12px',
											overflow: 'auto',
											maxHeight: '200px',
										} }
									>
										{ this.state.error.stack }
									</pre>
								</details>
							) }
					</div>
				</Notice>
			</div>
		);
	}

	render() {
		if ( this.state.hasError ) {
			return this.renderErrorFallback();
		}

		return this.props.children;
	}
}

/**
 * HOC for wrapping components with return label error boundary
 */
export function withReturnLabelErrorBoundary< P extends object >(
	WrappedComponent: React.ComponentType< P >,
	errorBoundaryProps?: Partial< ReturnLabelErrorBoundaryProps >
) {
	const WithReturnLabelErrorBoundaryComponent = ( props: P ) => (
		<ReturnLabelErrorBoundary { ...errorBoundaryProps }>
			<WrappedComponent { ...props } />
		</ReturnLabelErrorBoundary>
	);

	WithReturnLabelErrorBoundaryComponent.displayName = `withReturnLabelErrorBoundary(${
		WrappedComponent.displayName ?? WrappedComponent.name
	})`;

	return WithReturnLabelErrorBoundaryComponent;
}
