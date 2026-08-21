/**
 * Common Test Patterns and Setup Utilities
 *
 * Centralized utilities for common test setup patterns, mock configurations,
 * and reusable test components across the WooCommerce Shipping test suite.
 */

import React from 'react';
import { render } from '@testing-library/react';
import type { RenderResult } from '@testing-library/react';
import { LabelPurchaseContext } from 'context/label-purchase';
import CurrencyFactory from '@woocommerce/currency';
import { SHIPMENT_TYPE } from 'utils/shipment';
import { address, destinationAddress, orderTestData } from './test-utils';

/**
 * Common mock configurations for different test scenarios
 */
export interface MockConfiguration {
	storeCurrency?: {
		formatMoney?: ( amount: string ) => string;
		[ key: string ]: unknown;
	};
	rates?: {
		getSelectedRate: jest.Mock;
		getSelectedRateOptions: jest.Mock;
		selectRateOption: jest.Mock;
		getCurrentRateOptions: jest.Mock;
		isFetching: boolean;
		errors: string[];
	};
	labels?: {
		hasPurchasedLabel: jest.Mock;
		getCurrentShipmentLabel: jest.Mock;
		isPurchasing: boolean;
		errors: string[];
	};
	shipment?: {
		getShipmentOrigin: jest.Mock;
		getShipmentPurchaseOrigin: jest.Mock;
		getShipmentDestination: jest.Mock;
		getCurrentShipmentDate: jest.Mock;
		getCurrentShipmentIsReturn: jest.Mock;
		isReturnShipment: jest.Mock;
		getShipmentType: jest.Mock;
	};
	addresses?: {
		origin?: Record< string, unknown >;
		destination?: Record< string, unknown >;
	};
	order?: {
		total: string;
		total_shipping: string;
		total_line_items_quantity: number;
		line_items: {
			id: number;
			name: string;
			quantity: number;
		}[];
	};
	isReturn?: boolean;
	isDomestic?: boolean;
	hasValidAddresses?: boolean;
}

/**
 * Enhanced mock factory for consistent test setups
 */
export class TestMockFactory {
	private static defaultConfig: Required< MockConfiguration > = {
		storeCurrency: CurrencyFactory( { code: 'USD' } ),
		rates: {
			getSelectedRate: jest.fn( () => null ),
			getSelectedRateOptions: jest.fn( () => [] ),
			selectRateOption: jest.fn(),
			getCurrentRateOptions: jest.fn( () => [] ),
			isFetching: false,
			errors: [],
		},
		labels: {
			hasPurchasedLabel: jest.fn( () => false ),
			getCurrentShipmentLabel: jest.fn( () => ( {
				isLegacy: false,
				status: 'purchased',
			} ) ),
			isPurchasing: false,
			errors: [],
		},
		shipment: {
			getShipmentOrigin: jest.fn( () => address ),
			getShipmentPurchaseOrigin: jest.fn( () => address ),
			getShipmentDestination: jest.fn( () => destinationAddress ),
			getCurrentShipmentDate: jest.fn( () => ( {
				shippingDate: null,
				estimatedDeliveryDate: null,
			} ) ),
			getCurrentShipmentIsReturn: jest.fn( () => false ),
			isReturnShipment: jest.fn( () => false ),
			getShipmentType: jest.fn( () => SHIPMENT_TYPE.OUTBOUND ),
		},
		addresses: {
			origin: address,
			destination: destinationAddress,
		},
		order: orderTestData,
		isReturn: false,
		isDomestic: true,
		hasValidAddresses: true,
	};

	/**
	 * Create mock configuration for specific test scenarios
	 */
	static createMocks(
		overrides: Partial< MockConfiguration > = {}
	): Required< MockConfiguration > {
		const config = {
			...this.defaultConfig,
			...overrides,
		};

		// Apply contextual overrides based on scenario flags
		if ( config.isReturn ) {
			config.shipment = {
				...config.shipment,
				getCurrentShipmentIsReturn: jest.fn( () => true ),
				isReturnShipment: jest.fn( () => true ),
				getShipmentType: jest.fn( () => SHIPMENT_TYPE.RETURN ),
			};

			// Return shipments don't have shipping dates
			config.shipment.getCurrentShipmentDate = jest.fn( () => ( {
				shippingDate: null,
				estimatedDeliveryDate: null,
			} ) );
		}

		if ( ! config.isDomestic ) {
			config.addresses = {
				origin: { ...address, country: 'US' },
				destination: { ...destinationAddress, country: 'CA' },
			};
		}

		if ( ! config.hasValidAddresses ) {
			config.addresses = {
				origin: { ...config.addresses.origin, isVerified: false },
				destination: {
					...config.addresses.destination,
					isVerified: false,
				},
			};
		}

		return config;
	}

	/**
	 * Create mocks for outbound shipment scenarios
	 */
	static createOutboundMocks(
		overrides: Partial< MockConfiguration > = {}
	): Required< MockConfiguration > {
		return this.createMocks( {
			isReturn: false,
			isDomestic: true,
			...overrides,
		} );
	}

	/**
	 * Create mocks for return shipment scenarios
	 */
	static createReturnMocks(
		overrides: Partial< MockConfiguration > = {}
	): Required< MockConfiguration > {
		return this.createMocks( {
			isReturn: true,
			isDomestic: true,
			...overrides,
		} );
	}

	/**
	 * Create mocks for international shipment scenarios
	 */
	static createInternationalMocks(
		overrides: Partial< MockConfiguration > = {}
	): Required< MockConfiguration > {
		return this.createMocks( {
			isReturn: false,
			isDomestic: false,
			...overrides,
		} );
	}

	/**
	 * Create mocks for error scenarios
	 */
	static createErrorMocks(
		errorType: 'network' | 'api' | 'validation' = 'api'
	): Required< MockConfiguration > {
		const baseConfig = this.createMocks();

		switch ( errorType ) {
			case 'network':
				return {
					...baseConfig,
					rates: {
						...baseConfig.rates,
						isFetching: false,
						errors: [ 'Network connection failed' ],
					},
				};

			case 'api':
				return {
					...baseConfig,
					labels: {
						...baseConfig.labels,
						errors: [ 'API service unavailable' ],
					},
				};

			case 'validation':
				return {
					...baseConfig,
					hasValidAddresses: false,
					addresses: {
						origin: { ...address, address: '', isVerified: false },
						destination: {
							...destinationAddress,
							address: '',
							isVerified: false,
						},
					},
				};

			default:
				return baseConfig;
		}
	}
}

/**
 * Common test component patterns
 */
export class TestComponentPatterns {
	/**
	 * Render component with LabelPurchaseContext
	 */
	static renderWithLabelPurchaseContext(
		component: React.ReactElement,
		contextValue?: Partial< MockConfiguration >
	): RenderResult {
		const mocks = TestMockFactory.createMocks( contextValue );

		const contextWrapper = React.createElement(
			LabelPurchaseContext.Provider,
			{
				value: {
					storeCurrency: mocks.storeCurrency,
					rates: mocks.rates,
					labels: mocks.labels,
					shipment: mocks.shipment,
					orderItems: [],
					hazmat: { hasHazmat: false },
					packages: { getSelectedPackage: jest.fn() },
					weight: { getTotalWeight: jest.fn() },
					order: mocks.order ?? {},
					dimensions: { getTotalDimensions: jest.fn() },
					insurance: { getInsuranceValue: jest.fn() },
					// eslint-disable-next-line @typescript-eslint/no-explicit-any
				} as any,
			},
			component
		);

		return render( contextWrapper );
	}

	/**
	 * Render return shipment component with appropriate context
	 */
	static renderReturnShipmentComponent(
		component: React.ReactElement,
		overrides?: Partial< MockConfiguration >
	): RenderResult {
		const mocks = TestMockFactory.createReturnMocks( overrides );
		return this.renderWithLabelPurchaseContext( component, mocks );
	}

	/**
	 * Render outbound shipment component with appropriate context
	 */
	static renderOutboundShipmentComponent(
		component: React.ReactElement,
		overrides?: Partial< MockConfiguration >
	): RenderResult {
		const mocks = TestMockFactory.createOutboundMocks( overrides );
		return this.renderWithLabelPurchaseContext( component, mocks );
	}

	/**
	 * Create error boundary test wrapper
	 */
	static createErrorBoundaryWrapper(
		onError: jest.Mock = jest.fn()
	): React.ComponentType< { children: React.ReactNode } > {
		return ( { children }: { children: React.ReactNode } ) => {
			try {
				return React.createElement( React.Fragment, null, children );
			} catch ( error ) {
				onError( error );
				return React.createElement( 'div', null, 'Error caught' );
			}
		};
	}
}

/**
 * Common assertion patterns for tests
 */
export class TestAssertionPatterns {
	/**
	 * Assert address field ordering for shipment types
	 */
	static assertAddressFieldOrder(
		container: HTMLElement,
		shipmentType: 'outbound' | 'return'
	): void {
		const fromLabel = container.querySelector( 'label[for*="from"]' );
		const toLabel = container.querySelector( 'label[for*="to"]' );

		expect( fromLabel ).toBeInTheDocument();
		expect( toLabel ).toBeInTheDocument();

		if ( shipmentType === 'return' ) {
			expect( toLabel ).toHaveTextContent( 'Return to' );
		} else {
			expect( toLabel ).toHaveTextContent( 'Ship to' );
		}
	}

	/**
	 * Assert shipping date field visibility
	 */
	static assertShippingDateVisibility(
		container: HTMLElement,
		shouldBeVisible: boolean
	): void {
		const shippingDateLabel = container.querySelector(
			'label[for*="shipping-date"]'
		);

		if ( shouldBeVisible ) {
			expect( shippingDateLabel ).toBeInTheDocument();
		} else {
			expect( shippingDateLabel ).not.toBeInTheDocument();
		}
	}

	/**
	 * Assert error message display
	 */
	static assertErrorMessage(
		container: HTMLElement,
		expectedMessage: string | RegExp
	): void {
		const errorElement = container.querySelector(
			'[role="alert"], .error, .notice-error'
		);
		expect( errorElement ).toBeInTheDocument();
		expect( errorElement ).toHaveTextContent( expectedMessage );
	}

	/**
	 * Assert button state and accessibility
	 */
	static assertButtonState(
		button: HTMLElement,
		expectedState: {
			enabled?: boolean;
			text?: string | RegExp;
			ariaLabel?: string;
		}
	): void {
		if ( expectedState.enabled !== undefined ) {
			if ( expectedState.enabled ) {
				expect( button ).toBeEnabled();
			} else {
				expect( button ).toBeDisabled();
			}
		}

		if ( expectedState.text ) {
			expect( button ).toHaveTextContent( expectedState.text );
		}

		if ( expectedState.ariaLabel ) {
			expect( button ).toHaveAttribute(
				'aria-label',
				expectedState.ariaLabel
			);
		}
	}

	/**
	 * Assert form validation errors
	 */
	static assertFormValidationErrors(
		container: HTMLElement,
		expectedErrors: Record< string, string | RegExp >
	): void {
		Object.entries( expectedErrors ).forEach(
			( [ fieldName, errorMessage ] ) => {
				const errorElement = container.querySelector(
					`[data-field="${ fieldName }"] .error, [data-field="${ fieldName }"] + .error`
				);
				expect( errorElement ).toBeInTheDocument();
				expect( errorElement ).toHaveTextContent( errorMessage );
			}
		);
	}
}

/**
 * Test data generators for consistent test data
 */
export class TestDataGenerators {
	/**
	 * Generate address data with specific characteristics
	 */
	static createAddress(
		overrides: {
			country?: string;
			isVerified?: boolean;
			isResidential?: boolean;
			hasCompany?: boolean;
		} = {}
	): Record< string, unknown > {
		const baseAddress = {
			...address,
			name: 'Test Person',
			company: overrides.hasCompany ? 'Test Company' : '',
			address: '123 Test Street',
			city: 'Test City',
			state: 'CA',
			postcode: '90210',
			country: overrides.country ?? 'US',
			email: 'test@example.com',
			phone: '5551234567',
			isVerified: overrides.isVerified ?? true,
		};

		if ( overrides.isResidential === false ) {
			baseAddress.company = 'Test Business LLC';
			baseAddress.name = 'Business Contact';
		}

		return baseAddress;
	}

	/**
	 * Generate order data for testing
	 */
	static createOrder(
		overrides: {
			itemCount?: number;
			totalValue?: string;
			shippingCost?: string;
			isInternational?: boolean;
		} = {}
	): {
		total: string;
		total_shipping: string;
		total_line_items_quantity: number;
		line_items: {
			id: number;
			name: string;
			quantity: number;
		}[];
	} & Record< string, unknown > {
		return {
			...orderTestData,
			total: overrides.totalValue ?? '100.00',
			total_shipping: overrides.shippingCost ?? '10.00',
			total_line_items_quantity: overrides.itemCount ?? 2,
			line_items: Array.from(
				{ length: overrides.itemCount ?? 2 },
				( _, i ) => ( {
					id: i + 1,
					name: `Test Item ${ i + 1 }`,
					quantity: 1,
				} )
			),
		};
	}

	/**
	 * Generate rate data for testing
	 */
	static createRate(
		overrides: {
			isReturn?: boolean;
			carrier?: string;
			service?: string;
			price?: number;
		} = {}
	): {
		id: string;
		carrier: string;
		service: string;
		rate: number;
		isReturn: boolean;
		delivery_days: number;
		currency: string;
	} {
		return {
			id: 'test_rate_1',
			carrier: overrides.carrier ?? 'USPS',
			service: overrides.service ?? 'Ground',
			rate: overrides.price ?? 9.99,
			isReturn: overrides.isReturn ?? false,
			delivery_days: 3,
			currency: 'USD',
		};
	}

	/**
	 * Generate shipment data for testing
	 */
	static createShipment(
		overrides: {
			id?: string;
			type?: 'outbound' | 'return';
			items?: {
				id: number;
				name: string;
				quantity: number;
			}[];
			addresses?: {
				origin?: Record< string, unknown >;
				destination?: Record< string, unknown >;
			};
		} = {}
	): Record< string, unknown > {
		return {
			id: overrides.id ?? 'shipment_1',
			type: overrides.type ?? 'outbound',
			items: overrides.items ?? [
				{ id: 1, name: 'Test Item', quantity: 1 },
			],
			origin: overrides.addresses?.origin ?? this.createAddress(),
			destination:
				overrides.addresses?.destination ?? this.createAddress(),
			status: 'ready',
		};
	}
}

/**
 * Test cleanup utilities
 */
export class TestCleanupUtils {
	/**
	 * Clear all mocks and restore original implementations
	 */
	static clearAllMocks(): void {
		jest.clearAllMocks();
	}

	/**
	 * Reset mock implementations to defaults
	 */
	static resetMockImplementations(): void {
		jest.resetAllMocks();
	}

	/**
	 * Clean up timers and async operations
	 */
	static cleanupTimers(): void {
		jest.clearAllTimers();
		jest.useRealTimers();
	}

	/**
	 * Full test cleanup
	 */
	static fullCleanup(): void {
		this.clearAllMocks();
		this.cleanupTimers();
	}
}

/**
 * Common test scenario configurations
 */
export const TestScenarios = {
	/**
	 * Return label creation scenario
	 */
	returnLabelCreation: {
		description: 'Return label creation with domestic addresses',
		mocks: TestMockFactory.createReturnMocks(),
		expectations: {
			addressSwapped: true,
			noShippingDate: true,
			domesticOnly: true,
		},
	},

	/**
	 * International shipment scenario (should be disabled for returns)
	 */
	internationalReturn: {
		description: 'International return attempt (should be disabled)',
		mocks: TestMockFactory.createInternationalMocks( { isReturn: true } ),
		expectations: {
			buttonDisabled: true,
			showTooltip: true,
			errorMessage: /domestic shipments/i,
		},
	},

	/**
	 * Outbound shipment scenario
	 */
	outboundShipment: {
		description: 'Standard outbound shipment',
		mocks: TestMockFactory.createOutboundMocks(),
		expectations: {
			addressNormal: true,
			hasShippingDate: true,
			internationalAllowed: true,
		},
	},

	/**
	 * Error handling scenario
	 */
	errorHandling: {
		description: 'API error handling',
		mocks: TestMockFactory.createErrorMocks( 'api' ),
		expectations: {
			showError: true,
			allowRetry: true,
			errorBoundaryTriggered: false,
		},
	},
};

/**
 * Export commonly used test setup functions
 * Note: This function should be called at the describe block level, not inside tests
 */
export const setupCommonTest = ( scenario: keyof typeof TestScenarios ) => {
	const config = TestScenarios[ scenario ];
	return config;
};

/**
 * Common test hooks that should be used in describe blocks
 */
export const useCommonTestHooks = () => {
	beforeEach( () => {
		TestCleanupUtils.clearAllMocks();
	} );

	afterEach( () => {
		TestCleanupUtils.fullCleanup();
	} );
};
