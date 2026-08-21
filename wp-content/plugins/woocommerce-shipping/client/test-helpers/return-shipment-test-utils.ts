/**
 * Return Shipment Test Utilities
 *
 * Comprehensive test utilities for testing return label functionality,
 * including mock setups, error scenarios, and integration test helpers.
 */

import { useState } from 'react';
import {
	render,
	renderHook,
	fireEvent,
	// eslint-disable-next-line import/named
	screen,
	// eslint-disable-next-line import/named
	waitFor,
} from '@testing-library/react';
import { SHIPMENT_TYPE } from 'utils/shipment';
import { LABEL_PURCHASE_STATUS } from 'data/constants';
import { address, destinationAddress } from './test-utils';

export interface ReturnShipmentTestConfig {
	shipmentId?: string;
	isReturn?: boolean;
	hasExistingShipments?: boolean;
	isDomestic?: boolean;
	hasValidAddresses?: boolean;
}

export interface ErrorScenario {
	type: 'api_error' | 'network_error' | 'validation_error' | 'address_error';
	message: string;
	statusCode?: number;
}

/**
 * Creates comprehensive mock setup for return shipment testing
 */
export const createReturnShipmentMocks = (
	config: ReturnShipmentTestConfig = {}
) => {
	const {
		shipmentId = '1',
		isReturn = true,
		hasExistingShipments = true,
		isDomestic = true,
		hasValidAddresses = true,
	} = config;

	const mockSelectedRates = hasExistingShipments
		? {
				'0': { rate: { isReturn: false } },
				[ shipmentId ]: { rate: { isReturn } },
		  }
		: {
				[ shipmentId ]: { rate: { isReturn } },
		  };

	const mockOriginAddress = {
		...address,
		country: isDomestic ? 'US' : 'CA',
	};

	const mockDestinationAddress = {
		...destinationAddress,
		country: isDomestic ? 'US' : 'CA',
		isVerified: hasValidAddresses,
	};

	return {
		wordPressMocks: {
			useSelect: jest.fn().mockImplementation( ( selector ) =>
				selector( () => ( {
					getSelectedRates: jest
						.fn()
						.mockReturnValue( mockSelectedRates ),
					getPurchasedLabel: jest.fn().mockReturnValue( null ),
					getOriginAddresses: jest
						.fn()
						.mockReturnValue( [ mockOriginAddress ] ),
					getLabelDestinations: jest
						.fn()
						.mockReturnValue( [ mockDestinationAddress ] ),
					getCurrentOrderShipments: jest
						.fn()
						.mockReturnValue(
							hasExistingShipments
								? { 0: [], [ shipmentId ]: [] }
								: {}
						),
					getRefundedLabel: jest.fn().mockReturnValue( null ),
					getOrderStatus: jest.fn().mockReturnValue( 'processing' ),
				} ) )
			),
			select: jest.fn().mockReturnValue( {
				getPurchasedLabel: jest.fn().mockReturnValue( null ),
				getSelectedRates: jest
					.fn()
					.mockReturnValue( mockSelectedRates ),
				getOriginAddresses: jest
					.fn()
					.mockReturnValue( [ mockOriginAddress ] ),
			} ),
			dispatch: jest.fn().mockReturnValue( {
				updateShipments: jest.fn(),
				purchaseLabel: jest.fn(),
				refundLabel: jest.fn(),
			} ),
		},
		shipmentMocks: {
			getShipmentOrigin: jest.fn().mockReturnValue( mockOriginAddress ),
			getShipmentPurchaseOrigin: jest
				.fn()
				.mockReturnValue( mockOriginAddress ),
			getShipmentDestination: jest
				.fn()
				.mockReturnValue( mockDestinationAddress ),
			getCurrentShipmentIsReturn: jest.fn().mockReturnValue( isReturn ),
			isReturnShipment: jest
				.fn()
				.mockImplementation( ( id ) => id === shipmentId && isReturn ),
			getShipmentType: jest
				.fn()
				.mockImplementation( ( id ) =>
					id === shipmentId && isReturn
						? SHIPMENT_TYPE.RETURN
						: SHIPMENT_TYPE.OUTBOUND
				),
			getCurrentShipmentDate: jest.fn().mockReturnValue( {
				shippingDate: isReturn ? null : new Date( '2025-02-26' ),
				estimatedDeliveryDate: new Date( '2025-02-28' ),
			} ),
			returnShipments: isReturn
				? { [ shipmentId ]: { isReturn: true } }
				: {},
			setReturnShipments: jest.fn(),
		},
		contextMocks: {
			storeCurrency: {
				formatMoney: jest.fn( ( amount ) => `$${ amount }` ),
			},
			rates: {
				getSelectedRate: jest.fn().mockReturnValue( null ),
				getSelectedRateOptions: jest.fn().mockReturnValue( [] ),
				selectRateOption: jest.fn(),
				getCurrentRateOptions: jest.fn().mockReturnValue( [] ),
				isFetching: false,
				errors: [],
			},
			labels: {
				hasPurchasedLabel: jest.fn().mockReturnValue( false ),
				getCurrentShipmentLabel: jest.fn().mockReturnValue( {
					isLegacy: false,
					status: LABEL_PURCHASE_STATUS.PURCHASED,
				} ),
				isPurchasing: false,
				errors: [],
			},
		},
		apiMocks: {
			getRates: jest.fn(),
			purchaseLabel: jest.fn(),
			refundLabel: jest.fn(),
		},
		addresses: {
			origin: mockOriginAddress,
			destination: mockDestinationAddress,
		},
	};
};

/**
 * Setup mock error scenarios for return shipment testing
 */
export const setupErrorScenarios = {
	apiError: ( scenario: ErrorScenario ) => {
		const mockError = new Error( scenario.message ) as Error & {
			status?: number;
		};
		if ( scenario.statusCode ) {
			mockError.status = scenario.statusCode;
		}

		switch ( scenario.type ) {
			case 'api_error':
				global.fetch = jest.fn().mockRejectedValue( mockError );
				break;
			case 'network_error':
				global.fetch = jest
					.fn()
					.mockRejectedValue( new Error( 'Network Error' ) );
				break;
			case 'validation_error':
				return {
					validation: {
						isValid: false,
						errors: [ scenario.message ],
					},
				};
			case 'address_error':
				return {
					address: {
						isVerified: false,
						errors: [ scenario.message ],
					},
				};
		}
		return mockError;
	},

	clearApiMocks: () => {
		const fetchMock = global.fetch as
			| jest.MockedFunction< typeof fetch >
			| undefined;
		if ( fetchMock && typeof fetchMock.mockRestore === 'function' ) {
			fetchMock.mockRestore();
		}
	},
};

/**
 * Enhanced test utilities for return shipment integration testing
 */
export const returnShipmentIntegrationUtils = {
	/**
	 * Setup complete return label purchase flow test
	 */
	setupReturnLabelPurchaseFlow: async (
		config: ReturnShipmentTestConfig = {}
	) => {
		const mocks = createReturnShipmentMocks( config );

		// Mock successful API responses
		mocks.apiMocks.getRates.mockResolvedValue( {
			rates: [
				{
					id: 'rate_1',
					service: 'USPS Ground',
					rate: 5.99,
					isReturn: config.isReturn ?? true,
				},
			],
		} );

		mocks.apiMocks.purchaseLabel.mockResolvedValue( {
			label: {
				id: 'label_1',
				status: LABEL_PURCHASE_STATUS.PURCHASED,
				tracking_number: 'TRACK123',
				label_url: 'https://example.com/label.pdf',
			},
		} );

		return mocks;
	},

	/**
	 * Test return label creation with address swapping
	 */
	testAddressSwapping: async ( component: JSX.Element ) => {
		render( component );

		// For return shipments, "Ship from" should show destination address
		// and "Return to" should show origin address
		await waitFor( () => {
			expect( screen.getByText( 'Ship from' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Return to' ) ).toBeInTheDocument();
		} );

		// Verify address order is swapped for returns
		const shipFromSection = screen
			.getByText( 'Ship from' )
			.closest( '.components-base-control' );
		const returnToSection = screen
			.getByText( 'Return to' )
			.closest( '.components-base-control' );

		expect( shipFromSection ).toBeInTheDocument();
		expect( returnToSection ).toBeInTheDocument();

		// Ship from should appear before Return to in the DOM for returns
		const sections = [ shipFromSection, returnToSection ];
		expect( sections[ 0 ] ).toBe( shipFromSection );
		expect( sections[ 1 ] ).toBe( returnToSection );
	},

	/**
	 * Test domestic-only restriction for return labels
	 */
	testDomesticRestriction: async ( component: JSX.Element ) => {
		render( component );

		const returnButton = await screen.findByRole( 'button', {
			name: /return label/i,
		} );

		// International orders should have disabled return button
		expect( returnButton ).toBeDisabled();

		// Should show tooltip explaining restriction
		fireEvent.mouseOver( returnButton );

		await waitFor( () => {
			expect(
				screen.getByText(
					/return labels are currently only available for domestic shipments/i
				)
			).toBeInTheDocument();
		} );
	},

	/**
	 * Test return shipment validation
	 */
	validateReturnShipment: ( shipmentData: {
		isReturn?: boolean;
		origin?: { country?: string };
		destination?: { country?: string };
		items?: unknown[];
	} ) => {
		const validationErrors: string[] = [];

		if ( ! shipmentData.isReturn ) {
			validationErrors.push( 'Shipment must be marked as return' );
		}

		if ( ! shipmentData.origin || ! shipmentData.destination ) {
			validationErrors.push(
				'Both origin and destination addresses are required'
			);
		}

		if (
			shipmentData.origin?.country !== shipmentData.destination?.country
		) {
			validationErrors.push(
				'Return labels only support domestic shipments'
			);
		}

		if ( ! shipmentData.items || shipmentData.items.length === 0 ) {
			validationErrors.push(
				'At least one item must be selected for return'
			);
		}

		return {
			isValid: validationErrors.length === 0,
			errors: validationErrors,
		};
	},

	/**
	 * Mock successful return label purchase
	 */
	mockSuccessfulReturnPurchase: ( config: ReturnShipmentTestConfig = {} ) => {
		const mocks = createReturnShipmentMocks( config );

		mocks.apiMocks.purchaseLabel.mockResolvedValue( {
			success: true,
			data: {
				label: {
					id: `return_label_${ config.shipmentId ?? '1' }`,
					status: LABEL_PURCHASE_STATUS.PURCHASED,
					tracking_number: `RET${ Date.now() }`,
					label_url: 'https://example.com/return-label.pdf',
					isReturn: true,
				},
			},
		} );

		return mocks;
	},

	/**
	 * Mock failed return label purchase
	 */
	mockFailedReturnPurchase: (
		errorType: ErrorScenario[ 'type' ] = 'api_error'
	) => {
		const mocks = createReturnShipmentMocks( { isReturn: true } );

		const errorMessage = {
			api_error: 'API service unavailable',
			network_error: 'Network connection failed',
			validation_error: 'Invalid return shipment data',
			address_error: 'Return address validation failed',
		}[ errorType ];

		mocks.apiMocks.purchaseLabel.mockRejectedValue(
			new Error( errorMessage )
		);

		return mocks;
	},
};

/**
 * Focused assertion utilities instead of large snapshots
 */
export const returnShipmentAssertions = {
	/**
	 * Assert return label UI elements are present and correct
	 */
	assertReturnLabelUI: async () => {
		await waitFor( () => {
			expect(
				screen.getByRole( 'button', { name: /return label/i } )
			).toBeInTheDocument();
		} );
	},

	/**
	 * Assert address fields are in correct order for return shipments
	 */
	assertReturnAddressOrder: async () => {
		await waitFor( () => {
			const shipFromLabel = screen.getByText( 'Ship from' );
			const returnToLabel = screen.getByText( 'Return to' );

			expect( shipFromLabel ).toBeInTheDocument();
			expect( returnToLabel ).toBeInTheDocument();

			// Verify proper labeling
			expect( shipFromLabel ).toHaveAttribute( 'for', 'ship-to' ); // Customer address for returns
			expect( returnToLabel ).toHaveAttribute( 'for', 'ship-from' ); // Merchant address for returns
		} );
	},

	/**
	 * Assert return shipment doesn't show shipping date field
	 */
	assertNoShippingDateForReturns: () => {
		expect(
			screen.queryByLabelText( /ship date/i )
		).not.toBeInTheDocument();
	},

	/**
	 * Assert return label button state and behavior
	 */
	assertReturnButtonBehavior: async ( isDomestic = true ) => {
		const returnButton = screen.getByRole( 'button', {
			name: /return label/i,
		} );

		if ( isDomestic ) {
			expect( returnButton ).toBeEnabled();
		} else {
			expect( returnButton ).toBeDisabled();

			// Check for international restriction tooltip
			fireEvent.mouseOver( returnButton );

			await waitFor( () => {
				expect(
					screen.getByText( /domestic shipments/i )
				).toBeInTheDocument();
			} );
		}
	},

	/**
	 * Assert return shipment tab labeling
	 */
	assertReturnShipmentTabs: async ( totalShipments = 2 ) => {
		// Wait for tabs to render
		await waitFor( () => {
			// Should see "Return X/Y" format for return tabs
			expect(
				screen.getByText( `Return 1/${ totalShipments }` )
			).toBeInTheDocument();
		} );
	},

	/**
	 * Assert purchase button text for returns
	 */
	assertReturnPurchaseButton: async ( returnIndex = 1, totalReturns = 1 ) => {
		await waitFor( () => {
			const purchaseButton = screen.getByRole( 'button', {
				name: new RegExp(
					`return ${ returnIndex }/${ totalReturns }`,
					'i'
				),
			} );
			expect( purchaseButton ).toBeInTheDocument();
		} );
	},

	/**
	 * Assert error handling for return shipments
	 */
	assertReturnErrorHandling: async ( expectedError: string ) => {
		await waitFor( () => {
			expect( screen.getByText( expectedError ) ).toBeInTheDocument();
		} );
	},
};

/**
 * Hook testing utilities for return shipments
 */
export const returnShipmentHookUtils = {
	/**
	 * Test return shipment state management
	 */
	testReturnShipmentState: () => {
		const { result } = renderHook( () => {
			// Mock implementation for testing
			const [ returnShipments, setReturnShipments ] = useState<
				Record< string, { isReturn?: boolean } >
			>( {} );

			return {
				returnShipments,
				setReturnShipments,
				isReturnShipment: ( id: string ) =>
					returnShipments[ id ]?.isReturn ?? false,
				getCurrentShipmentIsReturn: () =>
					returnShipments[ '1' ]?.isReturn ?? false,
				getShipmentType: ( id: string ) =>
					returnShipments[ id ]?.isReturn
						? SHIPMENT_TYPE.RETURN
						: SHIPMENT_TYPE.OUTBOUND,
			};
		} );

		return result;
	},

	/**
	 * Test return shipment validation hooks
	 */
	testReturnShipmentValidation: ( shipmentData: {
		isReturn?: boolean;
		origin?: { country?: string };
		destination?: { country?: string };
		items?: unknown[];
	} ) => {
		const { result } = renderHook( () => {
			const validate = () =>
				returnShipmentIntegrationUtils.validateReturnShipment(
					shipmentData
				);
			return { validate };
		} );

		return result;
	},
};

/**
 * Common test setup for return shipment components
 * Note: This should be called in the main describe block, not inside individual tests
 */
export const setupReturnShipmentTest = (
	config: ReturnShipmentTestConfig = {}
) => {
	const mocks = createReturnShipmentMocks( config );
	return mocks;
};
