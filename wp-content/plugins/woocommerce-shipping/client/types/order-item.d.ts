export interface OrderItem {
	id: number;
	name: string;
	quantity: number;
	weight: string;
	price: string;
	product_id: number;
	meta: {
		customs_info?: {
			description: string;
			hs_tariff_number: string;
			origin_country: string;
		};
	};
	tax_class: string;
	image: string;
	sku: string;
	subtotal: string;
	subtotal_tax: string;
	total: string;
	total_tax: string;
	variation?: {
		display_key: number;
		display_value: string;
		key: string;
		value: string;
	}[];
}
