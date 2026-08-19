import { CREATE_CONNECTION, CREATING_CONNECTION_FAILED } from './action-types';
import { WPErrorRESTResponse } from 'types';

export interface WPCOMConnectionState {
	redirectUrl: string | false;
	error: string | false;
}

export interface WPCOMConnectionCreationPayload {
	payload: {
		returnUrl: string;
		source: string;
	};
}

export interface WPCOMConnectionCreationAction {
	type: typeof CREATE_CONNECTION;
	payload: {
		redirectUrl: string;
	};
}

export interface WPCOMConnectionActionError {
	type: typeof CREATING_CONNECTION_FAILED;
	payload: {
		error: WPErrorRESTResponse;
	};
}

export type WPCOMConnectionActions =
	| WPCOMConnectionCreationAction
	| WPCOMConnectionActionError;
