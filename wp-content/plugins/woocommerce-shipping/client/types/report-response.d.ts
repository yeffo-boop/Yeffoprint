import { ReportLabel } from './report-label.d';

export interface ReportResponse {
	rows: ReportLabel[];
	meta: {
		totalCount: number;
		totalCost: number;
		totalRefunds: number;
		pages: number;
	};
}
