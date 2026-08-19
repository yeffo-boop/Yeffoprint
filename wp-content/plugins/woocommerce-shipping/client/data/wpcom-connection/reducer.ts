import { createReducer } from 'utils';
import { CREATE_CONNECTION, CREATING_CONNECTION_FAILED } from './action-types';
import {
	WPCOMConnectionState,
	WPCOMConnectionActions,
	WPCOMConnectionCreationAction,
	WPCOMConnectionActionError,
} from 'types';

const defaultState: WPCOMConnectionState = {
	error: false,
	redirectUrl: false,
};

export const WPCOMConnectionReducer = createReducer( defaultState )
	.on(
		CREATE_CONNECTION,
		( state, { payload }: WPCOMConnectionCreationAction ) => ( {
			...state,
			error: false,
			redirectUrl: payload.redirectUrl,
		} )
	)
	.on(
		CREATING_CONNECTION_FAILED,
		( state, { payload }: WPCOMConnectionActionError ) => ( {
			...state,
			error: payload.error.message,
		} )
	)
	.bind< WPCOMConnectionActions >();
