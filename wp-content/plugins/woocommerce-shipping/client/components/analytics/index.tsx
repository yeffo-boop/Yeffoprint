import CurrencyFactory from '@woocommerce/currency';
import { registerAnalyticsStore } from 'data/analytics';
import { LabelReportProps } from './types';
import { Labels } from './labels';

const storeCurrency = CurrencyFactory();

registerAnalyticsStore();

type AnalyticsProps = LabelReportProps;

const Analytics = ( props: AnalyticsProps ) => {
	return (
		<div className="wcshipping-label-analytics">
			<Labels { ...props } currency={ storeCurrency } />
		</div>
	);
};

export default Analytics;
