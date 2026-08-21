// eslint-disable-next-line import/named
import { render, screen } from '@testing-library/react';
import WCShippingPlugin from './index';
import { getConfig } from '../../utils';
import { ADDRESS_TYPES } from '../../data/constants';

// Mock dependencies
jest.mock( '../../utils', () => ( {
	getConfig: jest.fn(),
} ) );

jest.mock( '../../data/address', () => ( {
	addressStore: {},
	registerAddressStore: jest.fn(),
} ) );

jest.mock( '../../data/label-purchase', () => ( {
	labelPurchaseStore: {},
	registerLabelPurchaseStore: jest.fn(),
} ) );

jest.mock( '../../components/label-purchase/label-purchase-tabs', () => ( {
	LabelPurchaseTabs: jest.fn( () => <div>Label Purchase Tabs</div> ),
} ) );

jest.mock( '../../effects/label-purchase', () => ( {
	LabelPurchaseEffects: jest.fn( () => null ),
} ) );

jest.mock( '../../components/address-step/address_step', () => ( {
	AddressStep: jest.fn(
		( { type, onCompleteCallback, onCancelCallback } ) => (
			<div data-testid="address-step">
				<div>Address Step</div>
				<div data-testid="address-type">{ type }</div>
				<button
					onClick={ () => onCompleteCallback( { test: 'address' } ) }
				>
					Complete
				</button>
				<button onClick={ onCancelCallback }>Cancel</button>
			</div>
		)
	),
} ) );

describe( 'WCShippingPlugin', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	describe( 'Default mode (label-purchase)', () => {
		it( 'should render label purchase component when mode is not specified', () => {
			( getConfig as jest.Mock ).mockReturnValue( {
				order: { id: 123 },
			} );

			render( <WCShippingPlugin /> );

			expect(
				screen.getByText( 'Label Purchase Tabs' )
			).toBeInTheDocument();
		} );

		it( 'should render label purchase component when mode is explicitly set to label-purchase', () => {
			( getConfig as jest.Mock ).mockReturnValue( {
				order: { id: 123 },
				mode: 'label-purchase',
			} );

			render( <WCShippingPlugin /> );

			expect(
				screen.getByText( 'Label Purchase Tabs' )
			).toBeInTheDocument();
		} );
	} );

	describe( 'Address editor mode', () => {
		it( 'should render AddressStep component when mode is address-editor', () => {
			( getConfig as jest.Mock ).mockReturnValue( {
				mode: 'address-editor',
				order: {
					id: 123,
					shipping_address: {
						address_1: '123 Main St',
						city: 'Test City',
					},
				},
				accountSettings: {
					storeOptions: {
						origin_country: 'US',
					},
				},
			} );

			render( <WCShippingPlugin /> );

			expect( screen.getByTestId( 'address-step' ) ).toBeInTheDocument();
			expect( screen.getByText( 'Address Step' ) ).toBeInTheDocument();
		} );

		it( 'should pass correct address type to AddressStep', () => {
			( getConfig as jest.Mock ).mockReturnValue( {
				mode: 'address-editor',
				order: {
					id: 123,
					shipping_address: { address_1: '123 Main St' },
				},
				accountSettings: {
					storeOptions: {
						origin_country: 'US',
					},
				},
			} );

			render( <WCShippingPlugin /> );

			expect( screen.getByTestId( 'address-type' ) ).toHaveTextContent(
				ADDRESS_TYPES.DESTINATION
			);
		} );

		it( 'should use address from config.address when order.shipping_address is not available', () => {
			const mockAddress = { address: '456 Test Ave', city: 'Test Town' };
			( getConfig as jest.Mock ).mockReturnValue( {
				mode: 'address-editor',
				address: mockAddress,
				order: {
					id: 123,
				},
				accountSettings: {
					storeOptions: {
						origin_country: 'GB',
					},
				},
			} );

			render( <WCShippingPlugin /> );

			expect( screen.getByTestId( 'address-step' ) ).toBeInTheDocument();
		} );

		it( 'should default to US when origin country is not provided', () => {
			( getConfig as jest.Mock ).mockReturnValue( {
				mode: 'address-editor',
				order: {
					id: 123,
					shipping_address: { address_1: '123 Main St' },
				},
			} );

			render( <WCShippingPlugin /> );

			expect( screen.getByTestId( 'address-step' ) ).toBeInTheDocument();
		} );
	} );

	describe( 'Callback invocation', () => {
		it( 'should invoke onAddressComplete callback when address is completed', () => {
			const mockOnAddressComplete = jest.fn();
			( getConfig as jest.Mock ).mockReturnValue( {
				mode: 'address-editor',
				order: {
					id: 123,
					shipping_address: { address_1: '123 Main St' },
				},
				accountSettings: {
					storeOptions: {
						origin_country: 'US',
					},
				},
				onAddressComplete: mockOnAddressComplete,
			} );

			render( <WCShippingPlugin /> );

			const completeButton = screen.getByText( 'Complete' );
			completeButton.click();

			expect( mockOnAddressComplete ).toHaveBeenCalledWith( {
				test: 'address',
			} );
		} );

		it( 'should invoke onAddressCancel callback when address is cancelled', () => {
			const mockOnAddressCancel = jest.fn();
			( getConfig as jest.Mock ).mockReturnValue( {
				mode: 'address-editor',
				order: {
					id: 123,
					shipping_address: { address_1: '123 Main St' },
				},
				accountSettings: {
					storeOptions: {
						origin_country: 'US',
					},
				},
				onAddressCancel: mockOnAddressCancel,
			} );

			render( <WCShippingPlugin /> );

			const cancelButton = screen.getByText( 'Cancel' );
			cancelButton.click();

			expect( mockOnAddressCancel ).toHaveBeenCalled();
		} );

		it( 'should not throw error when callbacks are not provided', () => {
			( getConfig as jest.Mock ).mockReturnValue( {
				mode: 'address-editor',
				order: {
					id: 123,
					shipping_address: { address_1: '123 Main St' },
				},
				accountSettings: {
					storeOptions: {
						origin_country: 'US',
					},
				},
			} );

			expect( () => render( <WCShippingPlugin /> ) ).not.toThrow();
		} );
	} );
} );
