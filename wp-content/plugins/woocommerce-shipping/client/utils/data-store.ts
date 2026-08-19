import { Action } from 'types';

export const createReducer = < S >( defaultState: S ) => {
	const handlers: Record<
		string,
		< Actions extends Action >( s: S, action: Actions ) => S
	> = {};

	const bind = < A extends Action >() => {
		return ( s = defaultState, action: A ) => {
			const handler = handlers[ action.type ];
			return typeof handler === 'function'
				? handler( s, action as A )
				: s;
		};
	};
	const on = ( type: string, handler: ( s: S, action: Action ) => S ) => {
		handlers[ type ] = handler;
		return {
			on,
			bind,
		};
	};
	return {
		on,
		bind,
	};
};
