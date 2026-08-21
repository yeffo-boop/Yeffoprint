export interface ServiceStatusNotice {
	id: string;
	type: 'info' | 'warning' | 'error';
	message: string;
	dismissible: boolean;
	provider: string;
	action?: string;
}

export interface ServiceStatusResponse {
	notices: ServiceStatusNotice[];
}
