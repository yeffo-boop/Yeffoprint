import { ReportQuery } from 'types';
import { AnalyticsState } from './types.d';

export const getState = ( state: AnalyticsState ) => {
	return state;
};

export const getLabelsReport = (
	state: AnalyticsState,
	query: ReportQuery
) => {
	return state.data?.[ JSON.stringify( query ) ];
};
