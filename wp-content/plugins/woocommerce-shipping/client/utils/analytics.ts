import {
	getDateParamsFromQuery,
	appendTimestamp,
	getCurrentDates,
} from '@woocommerce/date';
import { addQueryArgs } from '@wordpress/url';
import { getLabelsReportPath } from 'data/routes';
import { ReportQuery } from 'types';

const defaultFields = [
	'created_date',
	'order_id',
	'rate',
	'service_name',
	'refund',
	'tracking',
	'carrier_id',
];

export const createDateQuery = ( query: Record< string, string > ) => {
	const { period, compare, before, after } = getDateParamsFromQuery( query );
	const { primary, secondary } = getCurrentDates( query );
	return {
		period,
		compare,
		before,
		after,
		primary,
		secondary,
	};
};

export const getQueryParameters = (
	dateQuery: ReturnType< typeof createDateQuery >,
	pagination: {
		perPage: string;
		offset: string;
	}
) => {
	const afterDate = encodeURIComponent(
		appendTimestamp( dateQuery.primary.after, 'start' )
	);
	const beforeDate = encodeURIComponent(
		appendTimestamp( dateQuery.primary.before, 'end' )
	);

	return {
		after: afterDate,
		before: beforeDate,
		per_page: pagination.perPage,
		offset: pagination.offset,
		fields: defaultFields,
	};
};

export const createReportQueryPath = ( query: ReportQuery ) =>
	addQueryArgs(
		getLabelsReportPath(),
		getQueryParameters( createDateQuery( query ), {
			perPage: query.perPage,
			offset: query.offset,
		} )
	);
