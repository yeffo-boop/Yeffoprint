export const ACCEPTED_USPS_ORIGIN_COUNTRIES = [
	'US', // United States
	'PR', // Puerto Rico
	'VI', // Virgin Islands
	'GU', // Guam
	'AS', // American Samoa
	'UM', // United States Minor Outlying Islands
	'MH', // Marshall Islands
	'FM', // Micronesia
	'MP', // Northern Mariana Islands
];
// Packages shipping to or from the US, Puerto Rico and Virgin Islands don't need a Customs form
export const DOMESTIC_US_TERRITORIES = [ 'US', 'PR', 'VI' ];

// These US states are a special case because they represent military bases. They're considered "domestic",
// but they require a Customs form to ship from/to them.
export const US_MILITARY_STATES = [ 'AA', 'AE', 'AP' ];

// US territories can be addressed either as their own country code or, when the
// merchant declines address normalization, as country "US" with the territory
// in the state field. USPS Ship API builds a commercial invoice for these, so
// the customs form must be shown regardless of which encoding is used.
export const US_TERRITORY_STATES = [ 'AS', 'GU', 'MP', 'PR', 'VI', 'UM' ];

// These destination countries require an ITN regardless of shipment value
export const USPS_ITN_REQUIRED_DESTINATIONS = [ 'IR', 'SY', 'KP', 'CU', 'SD' ];
