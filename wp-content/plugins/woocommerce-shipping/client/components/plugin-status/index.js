import React, { useEffect, useState } from 'react';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	FormToggle,
	TextareaControl,
	SelectControl,
	Spinner,
	Notice,
	__experimentalSpacer as Spacer,
} from '@wordpress/components';
import { StatusCard } from './status-card';
import { useSelect, dispatch } from '@wordpress/data';
import { __, _n, sprintf } from '@wordpress/i18n';
import { STORE_NAME } from 'wcshipping/data/constants';
import { getPaperSizes } from '../label-purchase/label/utils';
import { printDocument } from 'utils/label/print-document';
import { getPDFFileName } from 'utils/label/pdf';
import { getPreviewURL } from 'utils/label/routes';
import apiFetch from '@wordpress/api-fetch';

const PluginStatus = ( props ) => {
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ paperSize, setPaperSize ] = useState(
		getPaperSizes( 'US' )[ 0 ].key
	);
	const [ labelPreviewError, setLabelPreviewError ] = useState( false );

	const woocommerceHealthItem = useSelect( ( select ) => {
		return select( STORE_NAME ).getWoocommerceHealthItem();
	} );

	const wpComHealthItem = useSelect( ( select ) => {
		return select( STORE_NAME ).getWPComHealthItem();
	} );

	const wcShippingHealthHealthItem = useSelect( ( select ) => {
		// Return a new object every time so that it always refreshes the status text.
		return { ...select( STORE_NAME ).getWCShippingHealthItem() };
	} );

	const logs = useSelect( ( select ) => {
		return select( STORE_NAME ).getLogs();
	} );

	const loggingEnabled = useSelect( ( select ) => {
		return select( STORE_NAME ).getLoggingEnabled();
	} );

	const debugEnabled = useSelect( ( select ) => {
		return select( STORE_NAME ).getDebugEnabled();
	} );

	const paperSizeSelectHandler = ( value ) => {
		setPaperSize( value );
	};

	const printTestLabel = async () => {
		const testLabelId = 'test_1234';
		try {
			const path = getPreviewURL( paperSize, testLabelId );
			const pdfJson = await apiFetch( {
				path,
				method: 'GET',
			} );
			await printDocument(
				pdfJson,
				getPDFFileName( testLabelId, false )
			);
		} catch ( error ) {
			setLabelPreviewError( true );
			return Promise.reject( error );
		}
	};

	// Initialize
	useEffect( () => {
		const init = async () => {
			await dispatch( STORE_NAME ).init( {
				healthItems: props.healthItems,
				services: props.services,
				loggingEnabled: props.loggingEnabled,
				debugEnabled: props.debugEnabled,
				logs: props.logs,
			} );
			setIsLoading( false );
		};
		init();
	}, [
		props.healthItems,
		props.services,
		props.loggingEnabled,
		props.debugEnabled,
		props.logs,
	] );

	const saveAllDebugToggles = async ( isDebugChecked, isLoggingChecked ) => {
		await dispatch( STORE_NAME ).toggleDebug( {
			nonce: props.nonce,
			payload: {
				debugEnabled: isDebugChecked,
				loggingEnabled: isLoggingChecked,
			},
		} );
	};

	const debugToggleHandler = () => {
		saveAllDebugToggles( ! debugEnabled, loggingEnabled );
	};

	const loggingToggleHandler = () => {
		saveAllDebugToggles( debugEnabled, ! loggingEnabled );
	};

	const refreshConnectServerDataHandler = async () => {
		setIsBusy( true );
		await dispatch( STORE_NAME )
			.refreshServiceData( {
				nonce: props.nonce,
			} )
			.then( () => {
				setIsBusy( false );
			} );
	};

	const getWCShippingStatusMessage = ( wcShippingHealth ) => {
		let indicatorMessage = '';
		const currentTimestamp = Date.now() / 1000;

		if ( ! wcShippingHealth.has_service_schemas ) {
			indicatorMessage = __(
				'No service data available',
				'woocommerce-shipping'
			);
		} else if ( ! wcShippingHealth.timestamp ) {
			indicatorMessage = __(
				'Service data found, but may be out of date',
				'woocommerce-shipping'
			);
		} else if (
			wcShippingHealth.timestamp <
			currentTimestamp - wcShippingHealth.error_threshold
		) {
			indicatorMessage = __(
				'Service data was found, but is more than three days old',
				'woocommerce-shipping'
			);
		} else if (
			wcShippingHealth.timestamp <
			currentTimestamp - wcShippingHealth.warning_threshold
		) {
			indicatorMessage = __(
				'Service data was found, but is more than one day old',
				'woocommerce-shipping'
			);
		} else {
			indicatorMessage = __(
				'Service data is up-to-date',
				'woocommerce-shipping'
			);
		}
		return indicatorMessage;
	};

	const getLastUpdatedShippingStatus = () => {
		const isValidDate =
			wcShippingHealthHealthItem.timestamp > 0 ? true : false;

		if ( ! isValidDate ) {
			return false;
		}

		const lastUpdated = new Date(
			wcShippingHealthHealthItem.timestamp * 1000
		);
		return lastUpdated.toLocaleString();
	};

	const getLabelSelectOptions = () =>
		getPaperSizes( props.storeOptions.origin_country ).reduce(
			( accu, currentValue ) => {
				accu.push( {
					label: currentValue.name,
					value: currentValue.key,
				} );
				return accu;
			},
			[]
		);

	if ( isLoading ) {
		return <div>Loading...</div>;
	}

	const lastUpdateText =
		getLastUpdatedShippingStatus() !== false
			? sprintf(
					// translators: %s is last update time.
					__( 'Last update on %s', 'woocommerce-shipping' ),
					getLastUpdatedShippingStatus()
			  )
			: __(
					'Cannot retrieve the last update time.',
					'woocommerce-shipping'
			  );

	return (
		<div>
			<Card>
				<CardHeader>
					<h1>Health</h1>
				</CardHeader>
				<CardBody key={ 'woocommerce' }>
					<StatusCard
						name={ 'WooCommerce' }
						isSuccessful={
							woocommerceHealthItem.state === 'success'
						}
						message={ woocommerceHealthItem.message }
					/>
				</CardBody>
				<CardBody key={ 'wordpress.com' }>
					<StatusCard
						name={ 'WordPress.com Connection' }
						isSuccessful={ wpComHealthItem.state === 'success' }
						message={ wpComHealthItem.message }
					/>
				</CardBody>
				<CardBody key={ 'wcshipping' }>
					<StatusCard
						name={ 'WooCommerce Shipping' }
						isSuccessful={
							wcShippingHealthHealthItem.has_service_schemas
						}
						message={ getWCShippingStatusMessage(
							wcShippingHealthHealthItem
						) }
					/>
					<p>
						{ lastUpdateText }{ ' ' }
						<Button
							disabled={ isBusy }
							variant="link"
							onClick={ () => refreshConnectServerDataHandler() }
						>
							Refresh data
						</Button>
						{ isBusy && <Spinner /> }
					</p>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h1>Debug</h1>
				</CardHeader>
				<CardBody>
					<h3>Debug</h3>
					<FormToggle
						checked={ debugEnabled }
						onChange={ () => debugToggleHandler() }
					/>{ ' ' }
					<span>{ debugEnabled ? 'Enabled' : 'Disabled' }</span>
					<p>
						<em>
							Display troubleshooting information on the Cart and
							Checkout pages.
						</em>
					</p>
				</CardBody>

				<CardBody>
					<h3>Logging</h3>
					<FormToggle
						checked={ loggingEnabled }
						onChange={ () => loggingToggleHandler() }
					/>{ ' ' }
					<span>{ loggingEnabled ? 'Enabled' : 'Disabled' }</span>
					<p>
						<em>
							Write diagnostic messages to log files. Helpful when
							contacting support.
						</em>
					</p>
				</CardBody>

				<CardBody>
					<h3>Shipping Log</h3>
					<TextareaControl
						label={ 'Shipping Log' }
						help={ 'Last ' + logs.shipping.count + ' entries' }
						value={ logs.shipping.tail }
						rows={ 10 }
						__nextHasNoMarginBottom={ true }
					/>
				</CardBody>

				<CardBody>
					<h3>Other Log</h3>
					<TextareaControl
						label={ __( 'Other Log', 'woocommerce-shipping' ) }
						help={ sprintf(
							// translators: %d is the number of entries to show.
							_n(
								'Last %d entry',
								'Last %d entries',
								logs.other.count,
								'woocommerce-shipping'
							),
							logs.other.count
						) }
						value={ logs.other.tail }
						rows={ 10 }
						__nextHasNoMarginBottom={ true }
					/>
				</CardBody>

				<CardBody>
					<h3>{ __( 'Support', 'woocommerce-shipping' ) }</h3>
					<p>
						{ __(
							'Our team is here for you. View our',
							'woocommerce-shipping'
						) }{ ' ' }
						<a
							target="_blank"
							href="https://woocommerce.com/document/woocommerce-shipping/"
							rel="noreferrer"
						>
							{ __( 'support docs', 'woocommerce-shipping' ) }
						</a>{ ' ' }
						{ __( 'or', 'woocommerce-shipping' ) }{ ' ' }
						<a
							target="_blank"
							href="https://woocommerce.com/contact-us/"
							rel="noreferrer"
						>
							{ __( 'contact support', 'woocommerce-shipping' ) }
						</a>
						.
					</p>
				</CardBody>

				<CardBody>
					<h3>
						{ __( 'Test Label Printing', 'woocommerce-shipping' ) }
					</h3>

					{ labelPreviewError && (
						<Notice status="error" isDismissible={ false }>
							{ __(
								'Test label failed to print, please try again later.',
								'woocommerce-shipping'
							) }
						</Notice>
					) }

					<p>
						{ __(
							'Having trouble configuring your printer? You can run a test print for shipping labels by selecting the paper size, then print.',
							'woocommerce-shipping'
						) }{ ' ' }
					</p>

					<SelectControl
						label={ __( 'Label size', 'woocommerce-shipping' ) }
						options={ getLabelSelectOptions() }
						onChange={ ( value ) =>
							paperSizeSelectHandler( value )
						}
						// Opting into the new styles for height
						__next40pxDefaultSize={ true }
						// Opting into the new styles for margin bottom
						__nextHasNoMarginBottom={ true }
					/>
					<Spacer marginTop={ 0 } marginBottom={ 3 } />
					<Button
						variant="primary"
						onClick={ () => printTestLabel() }
					>
						{ __( 'Print', 'woocommerce-shipping' ) }
					</Button>
				</CardBody>
			</Card>
		</div>
	);
};

export default PluginStatus;
