import { OrderItem } from './order-item';

export interface CustomsItem extends Omit< OrderItem, 'name' | 'id' > {
	id: string | number;
	description: string;
	hsTariffNumber: string;
	originCountry: string;
}
