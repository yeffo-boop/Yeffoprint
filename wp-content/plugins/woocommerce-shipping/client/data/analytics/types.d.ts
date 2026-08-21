import { Action } from 'types';
import { ANALYTICS_FETCH_LABELS_SUCCESS } from './action-types';
import { Label } from 'types';

export interface AnalyticsState {
	data:
		| Record<
				string,
				{
					rows: ReportLabel[];
					meta: {
						totalCount: number;
						totalCost: number;
						totalRefunds: number;
						pages: number;
					};
				}
		  >
		| undefined;
}

export interface AnalyticsFetchLabelsAction extends Action {
	type: ANALYTICS_FETCH_LABELS_SUCCESS;
	payload: {
		query: Record< string, string >;
		result: {
			rows: ReportLabel[];
			meta: {
				totalCount: number;
				totalCost: number;
				totalRefunds: number;
				pages: number;
			};
		};
	};
}

export type AnalyticsActions = AnalyticsFetchLabelsAction;
