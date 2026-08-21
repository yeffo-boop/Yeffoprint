import { ANALYTICS_FETCH_LABELS_SUCCESS } from './action-types';
import { AnalyticsFetchLabelsAction } from './types.d';

export const fetchLabelsSuccess = (
	payload: AnalyticsFetchLabelsAction[ 'payload' ]
): AnalyticsFetchLabelsAction => ( {
	type: ANALYTICS_FETCH_LABELS_SUCCESS,
	payload,
} );
