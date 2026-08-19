import { Package } from '../package';

export interface RequestPackage
	extends Omit<
		Package,
		'isLetter',
		'outerDimensions',
		'innerDimensions',
		'dimension'
	> {
	id: string;
	box_id: string;
	weight: number;
	is_letter: boolean;
}

export interface LabelRequestPackages {
	id: string;
	box_id: string;
	carrier_id: string;
	height: number;
	width: number;
	weight: number;
	length: number;
	service_id: string;
	service_name: string;
	shipment_id: string;
	rate_id: string;
	is_letter: boolean;
	products: number[];
	selected_promo_id?: string;
}

export type RequestPackageWithCustoms<
	T = RequestPackage | LabelRequestPackages,
> = T & {
	contents_type: string;
	contents_explanation?: string;
	restriction_type: string;
	restriction_comments?: string;
	itn: string;
	non_delivery_option: 'return' | 'abandon';
	items: {
		description: string;
		quantity: number;
		weight: number;
		hs_tariff_number: string;
		origin_country: string;
		product_id: number;
		value: number;
	}[];
};
