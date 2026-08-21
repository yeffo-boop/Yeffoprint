/**
 * ScanForm History List Component
 */

import { TableCard } from '@woocommerce/components';
import {
	Flex,
	__experimentalHeading as Heading,
	__experimentalSpacer as Spacer,
	__experimentalText as Text,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState, useCallback } from '@wordpress/element';
import { useScanFormHistory } from './use-scan-form-history';
import { tableHeaders } from './constants';
import { mapScanFormToTableRow } from './utils';
import { ScanFormLabelsModal } from './scan-form-labels-modal';
import type { ScanFormHistoryItem } from 'types';

export const ScanFormHistoryList = () => {
	const {
		scanForms,
		isLoading,
		error,
		page,
		total,
		perPage,
		setPage,
		setPerPage,
	} = useScanFormHistory();

	const [ selectedScanForm, setSelectedScanForm ] =
		useState< ScanFormHistoryItem | null >( null );
	const [ isModalOpen, setIsModalOpen ] = useState( false );

	const handleLabelCountClick = useCallback(
		( scanForm: ScanFormHistoryItem ) => {
			setSelectedScanForm( scanForm );
			setIsModalOpen( true );
		},
		[]
	);

	const handleCloseModal = useCallback( () => {
		setIsModalOpen( false );
		setSelectedScanForm( null );
	}, [] );

	const tableData = {
		rows: ( scanForms || [] ).map( ( scanForm ) =>
			mapScanFormToTableRow( scanForm, handleLabelCountClick )
		),
	};

	const onQueryChange =
		( key: 'page_size' | 'paged' | string ) => ( value: string ) => {
			if ( key === 'page_size' ) {
				setPerPage( parseInt( value, 10 ) );
			} else if ( key === 'paged' ) {
				setPage( parseInt( value, 10 ) );
			}
		};

	return (
		<Flex
			align="flex-start"
			gap={ 6 }
			justify="flex-start"
			className="wcshipping-settings"
		>
			<Flex direction="column">
				<Spacer marginTop={ 6 } marginBottom={ 0 } />
				<Heading level={ 4 }>
					{ __( 'SCAN Form History', 'woocommerce-shipping' ) }
				</Heading>

				<Text>
					{ __(
						'Create SCAN Forms from the orders page, then return here to view and download them.',
						'woocommerce-shipping'
					) }
				</Text>
			</Flex>

			<TableCard
				className="wcshipping-settings__card scan-form-history__card"
				title={ __( 'SCAN Forms', 'woocommerce-shipping' ) }
				rows={ tableData.rows }
				headers={ tableHeaders }
				rowsPerPage={ perPage }
				totalRows={ total }
				isLoading={ isLoading }
				query={ { paged: page, per_page: perPage } }
				onQueryChange={ onQueryChange }
				showMenu={ false }
				emptyMessage={
					error ?? __( 'No SCAN Forms yet.', 'woocommerce-shipping' )
				}
			/>

			{ isModalOpen && selectedScanForm && (
				<ScanFormLabelsModal
					scanForm={ selectedScanForm }
					onClose={ handleCloseModal }
				/>
			) }
		</Flex>
	);
};
