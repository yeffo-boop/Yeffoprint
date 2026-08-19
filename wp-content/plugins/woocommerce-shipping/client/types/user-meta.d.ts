import { Carrier } from './carrier.d';

export interface UserMeta extends Record< string, unknown > {
	last_box_id: string;
	last_carrier_id: Carrier;
	last_service_id: string;
	last_order_completed: boolean;
	last_shipping_date: string;
}
