type Type = 'success' | 'error' | 'info' | 'warning';

export interface StoreNotice {
	message: string;
	data?: string;
	type: Type;
}
