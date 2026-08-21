import type CurrencyFactory from '@woocommerce/currency';
import type { ReportQuery } from 'types';

export interface LabelReportProps {
	query: ReportQuery;
	path: string;
	currency: ReturnType< typeof CurrencyFactory >;
}
