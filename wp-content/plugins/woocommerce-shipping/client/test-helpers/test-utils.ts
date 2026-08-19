export const orderTestData = {
	id: 176,
	order_number: '176',
	order_key: 'wc_order_pbXPTcqWCJSnD',
	created_at: 1706745649,
	updated_at: '',
	completed_at: '',
	status: 'processing',
	currency: 'USD',
	total: '22.13',
	subtotal: '18.00',
	total_line_items_quantity: 1,
	total_tax: '0.00',
	total_shipping: '4.13',
	cart_tax: '0.00',
	shipping_tax: '0.00',
	total_discount: '0.00',
	shipping_methods: 'USPS - Media Mail Parcel',
	payment_details: {
		method_id: 'cod',
		method_title: 'Cash on delivery',
		paid: false,
	},
	billing_address: {
		first_name: 'Harris',
		last_name: 'W',
		company: '',
		address_1: '1600 Amphitheatre Parkway',
		address_2: '',
		city: 'Como',
		state: 'MS',
		postcode: '38619',
		country: 'US',
		email: 'harris.wong@automattic.com',
		phone: '78012345678',
	},
	shipping_address: {
		first_name: 'Harris',
		last_name: 'W',
		company: '',
		address_1: '1600 Amphitheatre Parkway',
		address_2: '',
		city: 'Como',
		state: 'MS',
		postcode: '38619',
		country: 'US',
		email: 'harris.wong@automattic.com',
		phone: '78012345678',
	},
	note: '',
	customer_ip: '123.456.78.9',
	customer_user_agent:
		'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
	customer_id: 1,
	view_order_url: 'http://localhost/my-account/view-order/176/',
	line_items: [
		{
			id: 173,
			subtotal: '18.00',
			subtotal_tax: '0.00',
			total: '18.00',
			total_tax: '0.00',
			price: '18.00',
			quantity: 1,
			tax_class: '',
			name: 'Beanie with Logo',
			product_id: 32,
			sku: 'Woo-beanie-logo',
			meta: [],
			image: 'http://localhost/wp-content/uploads/2023/01/beanie-with-logo-1.jpg',
			weight: '6',
			variation: [],
		},
	],
	shipping_lines: [
		{
			id: 174,
			method_id: 'wc_services_usps',
			method_title: 'USPS - Media Mail Parcel',
			total: '4.13',
		},
	],
	tax_lines: [],
	fee_lines: [],
	coupon_lines: [],
};

export const address = {
	id: 123,
	name: 'John',
	address: '123 Main St',
	address_2: 'Apt 1',
	city: 'San Francisco',
	state: 'CA',
	postcode: '94105',
	country: 'PR',
	phone: '1234567890',
	isVerified: true,
};

export const destinationAddress = {
	id: 124,
	name: 'John',
	address: '124 Main St - destination',
	address_2: 'Apt 1',
	city: 'San Francisco - dest',
	state: 'CA',
	postcode: '94105',
	country: 'PR',
	phone: '1234567890',
	isVerified: true,
};

export const mockUtils = ( overrides?: object ) => {
	const { packagesSettings } = jest.requireActual(
		'utils/__tests__/fixtures/package-settings'
	);

	const {
		accountSettings, // eslint-disable-next-line @typescript-eslint/no-var-requires
	} = jest.requireActual( 'utils/__tests__/fixtures/account-settings' );

	const {
		getCarrierPackages,
		getAvailableCarrierPackages,
		camelCaseKeys,
		getPackageDimensions,
		camelCasePackageResponse,
		toUTCMidnightISOString,
		...restOfUtils
	} = jest.requireActual( 'utils' );

	return {
		__esModule: true,
		...restOfUtils,
		camelCasePackageResponse,
		toUTCMidnightISOString,
		getWeightUnit: () => 'lbs',
		getDimensionsUnit: () => 'cm',
		getCurrencySymbol: () => '$',
		getCustomPackages: () => packagesSettings.packages.custom,
		getConfig: () => ( {
			order: orderTestData,
			is_origin_verified: false,
			is_destination_verified: true,
			packagesSettings,
			accountSettings,
			shippingLabelData: {
				storedData: {
					selectedDestination: {
						city: 'San Francisco',
						country: 'US',
						state: 'CA',
						zip: '94103',
						company: 'WooCommerce',
						email: 'some@email.com',
					},
				},
			},
		} ),
		getCarrierPackages: () =>
			getCarrierPackages(
				{
					usps: [ 'medium_flat_box_top', 'small_tube' ],
				},
				{ packagesSettings }
			),
		getAvailableCarrierPackages: () =>
			getAvailableCarrierPackages( { packagesSettings } ),
		getIsDestinationVerified: () => true,
		getCurrentOrderShipTo: () => ( {} ),
		camelCaseKeys,
		getPackageDimensions,
		getStoreOrigin: () => ( {
			country: 'US',
			state: 'CA',
		} ),
		getPurchasedLabels: () => ( {
			0: null,
		} ),
		getSelectedRates: () => ( {
			'0': { rate: { isReturn: false } },
			'1': { rate: { isReturn: true } },
		} ),
		getSelectedHazmat: () => null,
		getOriginAddresses: jest.fn().mockReturnValue( [ address ] ),
		getFirstSelectableOriginAddress: () => ( {} ),
		getCustomsInformation: () => '',
		getCurrentOrder: () => ( {
			id: 1,
			shipping_methods: 'Flat Rate',
		} ),
		getAccountSettings: () => ( {
			...accountSettings,
			purchaseSettings: {
				use_last_service: false,
			},
		} ),
		groupRatesByCarrier: () => ( {} ),
		getLabelDestinations: jest
			.fn()
			.mockReturnValue( [ destinationAddress ] ),
		getLabelOrigins: jest.fn().mockReturnValue( [] ),
		getCurrentOrderItems: jest.fn().mockReturnValue( [] ),
		getSelectedRateOptions: jest.fn().mockReturnValue( [] ),
		getCustomFulfillmentSummary: jest.fn().mockReturnValue( '' ),
		getCurrentOrderShipments: jest.fn().mockReturnValue( [] ),
		getShipmentDefaultDates: jest.fn().mockReturnValue( {
			shippingDate: new Date( '2025-02-26' ),
			estimatedDeliveryDate: new Date( '2025-02-30' ),
		} ),
		getPromotion: jest.fn(),
		getPromoDiscount: jest.fn(),
		applyPromo: jest.fn( ( rate: number ) => rate ),
		...( overrides ?? {} ), // this overrides should remain at the end
	};
};
