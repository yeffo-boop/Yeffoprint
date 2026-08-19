import { OrderItem } from './order-item';
import { Destination } from './destination';

export interface Order {
	id: number;
	order_number: string;
	order_key: string;
	created_at: number;
	updated_at: string;
	completed_at: string;
	status: string;
	currency: string;
	total: string;
	subtotal: string;
	total_line_items_quantity: number;
	total_tax: string;
	total_shipping: string;
	cart_tax: string;
	shipping_tax: string;
	total_discount: string;
	shipping_methods: string;
	payment_details: {
		method_id: string;
		method_title: string;
		paid: boolean;
	};
	billing_address: Destination;
	shipping_address: {
		first_name: string;
		last_name: string;
		company: string;
		address_1: string;
		address_2: string;
		city: string;
		state: string;
		postcode: string;
		country: string;
		email: string;
		phone: string;
	};
	note: string;
	customer_ip: string;
	customer_user_agent: string;
	customer_id: number;
	view_order_url: string;
	line_items: OrderItem[];
	shipping_lines: unknown;
	tax_lines: unknown;
	fee_lines: unknown;
	coupon_lines: unknown;
}
