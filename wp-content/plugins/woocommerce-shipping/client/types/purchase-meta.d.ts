import { PaymentMethod } from './payment-method';

export interface PurchaseMeta {
	can_edit_settings: boolean;
	can_manage_payments: boolean;
	master_user_email: string;
	master_user_login: string;
	master_user_name: string;
	master_user_wpcom_login: string;
	add_payment_method_url: string;
	payment_methods: Array< PaymentMethod >;
}
