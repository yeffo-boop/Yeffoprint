import { createReducer } from 'utils';
import { ANALYTICS_FETCH_LABELS_SUCCESS } from './action-types';
import {
	AnalyticsActions,
	AnalyticsFetchLabelsAction,
	AnalyticsState,
} from './types.d';

const defaultState: AnalyticsState = {
	data: undefined,
};

export const AnalyticsReducer = createReducer( defaultState )
	.on(
		ANALYTICS_FETCH_LABELS_SUCCESS,
		(
			state,
			{ payload: { query, result } }: AnalyticsFetchLabelsAction
		) => ( {
			...state,
			data: {
				...( state.data ?? {} ),
				[ JSON.stringify( query ) ]: {
					rows: result.rows,
					meta: result.meta,
				},
			},
		} )
	)
	.bind< AnalyticsActions >();
