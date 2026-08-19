export interface SuccessfulRefund {
	success: true;
	label: {
		id: number;
		postage: string;
	};
	refund: {
		status: string;
		amount: string;
	};
}

export interface PendingRefund {
	success: true;
	label: {
		id: number;
		postage: string;
	};
	refund: {
		status: string;
	};
}

export interface AlreadyRefunded {
	success: false;
	error: {
		message: 'Label refund already requested';
	};
	refund: {
		request_date: number;
		status: 'pending';
	};
}

export interface FailedRefund {
	success: false;
	error: {
		code: 'SHIPMENT.REFUND.UNAVAILABLE';
		message: 'Unable to request refund. The parcel has been shipped';
	};
}

export type RefundResponse =
	| SuccessfulRefund
	| PendingRefund
	| AlreadyRefunded
	| FailedRefund;
