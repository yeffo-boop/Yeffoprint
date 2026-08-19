import { apiFetch } from '@wordpress/data-controls';
import { fetchLabelsSuccess } from './actions';
import { AnalyticsFetchLabelsAction } from './types.d';
import { camelCaseKeys, createReportQueryPath } from 'utils';
import { ReportQuery, ReportResponse } from 'types';

export function* getLabelsReport(
	query: ReportQuery
): Generator<
	ReturnType< typeof apiFetch >,
	AnalyticsFetchLabelsAction,
	ReportResponse
> {
	const { rows, meta } = yield apiFetch( {
		path: createReportQueryPath( query ),
		method: 'GET',
	} );

	return fetchLabelsSuccess( {
		query,
		result: {
			rows: rows.map( camelCaseKeys ),
			meta: camelCaseKeys( meta ),
		},
	} );
}
