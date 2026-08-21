import { ReportFilters, TableCard } from '@woocommerce/components';
import { isoDateFormat } from '@woocommerce/date';
import {
	Button,
	__experimentalSpacer as Spacer,
	Notice,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	downloadCSVFile,
	generateCSVDataFromTable,
	generateCSVFileName,
	type Rows,
} from '@woocommerce/csv-export';
import { LabelReportProps } from './types';
import { DownloadIcon } from 'components/icons';
import { AnalyticsStore } from 'data/analytics';
import { camelCase } from 'lodash';
import {
	camelCaseKeys,
	createReportQueryPath,
	getAnalyticsConfig,
} from 'utils';
import { ReportResponse } from 'types';
import { mapRowToTableData } from './utils';
import { tableHeaders } from './contants';

export const Labels = ( { query, path, currency }: LabelReportProps ) => {
	const [ pagination, setPagination ] = useState( {
		perPage: 25, // Minimum value for PER_PAGE_OPTIONS in Pagination component
		paged: 1,
	} );

	const [ preparingExport, setPreparingExport ] = useState( false );
	const [ errors, setErrors ] = useState< string[] >( [] );

	const tableData: {
		rows: Rows;
		summary: {
			key: string;
			label: string;
			value: number | string | JSX.Element;
		}[];
	} = {
		rows: [],
		summary: [],
	};

	const data = useSelect(
		( select ) => {
			setErrors( [] );
			return select( AnalyticsStore ).getLabelsReport( {
				...query,
				perPage: pagination.perPage.toString(),
				offset: `${ ( pagination.paged - 1 ) * pagination.perPage }`,
			} );
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps -- query, path, pagination are dependencies
		[ query, path, pagination ]
	);

	if ( data?.rows && data.meta ) {
		tableData.rows = data.rows.map( mapRowToTableData( currency ) );
		tableData.summary = [
			{
				key: 'total_count',
				label: __(
					'Labels purchased in this period',
					'woocommerce-shipping'
				),
				value: data.meta.totalCount,
			},
			{
				key: 'total_cost',
				label: __(
					'Total label cost in this period',
					'woocommerce-shipping'
				),
				value: currency?.render( data.meta.totalCost ),
			},
			{
				key: 'total_refunds',
				label: __( 'Refund in this period', 'woocommerce-shipping' ),
				value: data.meta.totalRefunds,
			},
		];
	}

	const isLoading = data === undefined;

	const onQueryChange =
		( key: 'page_size' | 'paged' | 'sort' | string ) =>
		( value: string ) => {
			if ( key === 'page_size' || key === 'paged' ) {
				setPagination( {
					...pagination,
					[ camelCase( key ) ]: parseInt( value, 10 ),
				} );
			}
		};

	const onClickDownload = async () => {
		let { rows } = tableData;

		setErrors( [] );

		if (
			data?.meta.totalCount &&
			data.meta.totalCount > pagination.perPage
		) {
			setPreparingExport( true );

			try {
				const result = await apiFetch< ReportResponse >( {
					path: createReportQueryPath( {
						...query,
						perPage: '-1',
						offset: '0',
					} ),
					method: 'GET',
				} );
				rows = result.rows
					.map( camelCaseKeys )
					.map( mapRowToTableData( currency ) );
			} catch {
				setErrors( [
					...errors,
					__(
						'Failed to fetch data for export.',
						'woocommerce-shipping'
					),
				] );
				return;
			} finally {
				setPreparingExport( false );
			}
		}

		try {
			const params = { ...query }; // shallow copy
			downloadCSVFile(
				generateCSVFileName(
					__( 'Shipping Labels', 'woocommerce-shipping' ),
					params as Record< string, string >
				),
				generateCSVDataFromTable( tableHeaders, rows )
			);
		} catch {
			setErrors( [
				...errors,
				__(
					'Failed to request download of CSV file.',
					'woocommerce-shipping'
				),
			] );
		}
	};

	return (
		<>
			<ReportFilters
				query={ query }
				path={ path }
				isoDateFormat={ isoDateFormat }
			/>
			<Notice status="info" isDismissible={ false }>
				{ sprintf(
					// translators: %d is the cache expiration in minutes
					__(
						'To improve performance, the data shown here may be up to %d minutes old.',
						'woocommerce-shipping'
					),
					getAnalyticsConfig().cacheExpirationInSeconds / 60
				) }
			</Notice>
			<Spacer marginBottom={ 3 } />

			{ errors.length > 0 && (
				<>
					<Notice status="error">
						{ errors.map( ( error, index ) => (
							<p key={ index }>{ error }</p>
						) ) }
					</Notice>
					<Spacer marginBottom={ 3 } />
				</>
			) }

			<TableCard
				title={ __( 'Labels in this period', 'woocommerce-shipping' ) }
				rows={ tableData.rows }
				headers={ tableHeaders }
				rowsPerPage={ pagination.perPage }
				totalRows={ data?.meta.totalCount ?? 0 }
				summary={ tableData.summary }
				isLoading={ isLoading }
				query={ pagination }
				actions={ [
					<Button
						key="download"
						disabled={
							isLoading ||
							tableData.rows.length === 0 ||
							preparingExport
						}
						onClick={ onClickDownload }
						title={ __( 'Export CSV', 'woocommerce-shipping' ) }
						isBusy={ preparingExport }
					>
						<DownloadIcon />
						{ __( 'Download', 'woocommerce-shipping' ) }
					</Button>,
				] }
				onQueryChange={ onQueryChange }
			/>
		</>
	);
};
