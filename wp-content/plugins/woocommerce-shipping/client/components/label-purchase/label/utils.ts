import { __ } from '@wordpress/i18n';
import { PaperSize } from 'types';

export const getPaperSizes = ( country: string ): PaperSize[] => [
	...( [ 'US', 'CA', 'MX', 'DO' ].includes( country.toUpperCase() )
		? []
		: [
				{
					key: 'a4' as const,
					name: __( 'A4', 'woocommerce-shipping' ),
					size: __( '210x297mm', 'woocommerce-shipping' ),
				},
		  ] ),
	{
		key: 'label' as const,
		name: __( 'Label (4"x6")', 'woocommerce-shipping' ),
		size: __( '4"x6"', 'woocommerce-shipping' ),
	},
	{
		key: 'letter' as const,
		name: __( 'Letter (8.5"x11")', 'woocommerce-shipping' ),
		size: __( '8.5"x11"', 'woocommerce-shipping' ),
	},
];

export const getPaperSizeWithKey = (
	paperSize: string,
	country = 'US'
): PaperSize | undefined =>
	getPaperSizes( country ).find( ( { key } ) => key === paperSize );
