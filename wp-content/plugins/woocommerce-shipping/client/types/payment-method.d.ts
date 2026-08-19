export interface PaymentMethod {
	payment_method_id: number;
	name: string;
	card_type: string;
	card_digits: string;
	expiry: string; // Assuming this will always be in the format 'YYYY-MM-DD'
}
