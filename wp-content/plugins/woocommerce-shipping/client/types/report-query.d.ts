export interface ReportQuery extends Record< string, string > {
	perPage: string;
	paged: string;
	page: string; // "wc-admin"
	path: string; // '/analytics/shipping';
}
