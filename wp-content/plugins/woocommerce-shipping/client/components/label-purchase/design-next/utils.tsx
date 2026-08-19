declare global {
	interface Window {
		wp: any; // eslint-disable-line @typescript-eslint/no-explicit-any
		wcSettings?: {
			currency: Currency;
			shippingMethodTitles?: Record< string, string >;
		};
	}
}

interface Currency {
	code: string;
	decimalSeparator: string;
	precision: number;
	priceFormat: string;
	symbol: string;
	symbolPosition: string;
	thousandSeparator: string;
}

const isCurrency = ( value: unknown ): value is Currency => {
	/* eslint-disable @typescript-eslint/no-explicit-any */
	return (
		typeof value === 'object' &&
		value !== null &&
		'code' in value &&
		'decimalSeparator' in value &&
		'precision' in value &&
		'priceFormat' in value &&
		'symbol' in value &&
		'symbolPosition' in value &&
		'thousandSeparator' in value &&
		typeof ( value as any ).code === 'string' &&
		typeof ( value as any ).decimalSeparator === 'string' &&
		typeof ( value as any ).precision === 'number' &&
		typeof ( value as any ).priceFormat === 'string' &&
		typeof ( value as any ).symbol === 'string' &&
		typeof ( value as any ).symbolPosition === 'string' &&
		typeof ( value as any ).thousandSeparator === 'string'
	);
	/* eslint-enable @typescript-eslint/no-explicit-any */
};

export const getCurrencyObject = (): Currency => {
	const currency = window.wcSettings?.currency;

	if ( ! isCurrency( currency ) ) {
		// Fallback to USD if the currency is not valid.
		return {
			code: 'USD',
			decimalSeparator: '.',
			precision: 2,
			priceFormat: '%1$s %2$s',
			symbol: '$',
			symbolPosition: 'left',
			thousandSeparator: ',',
		};
	}

	return {
		code: currency?.code,
		decimalSeparator: currency?.decimalSeparator,
		precision: currency?.precision,
		priceFormat: currency?.priceFormat,
		symbol: currency?.symbol,
		symbolPosition: currency?.symbolPosition,
		thousandSeparator: currency?.thousandSeparator,
	};
};

export function formatCurrency( number: number, currency: string ) {
	return new Intl.NumberFormat( 'en-US', {
		style: 'currency',
		currency,
	} ).format( number );
}
