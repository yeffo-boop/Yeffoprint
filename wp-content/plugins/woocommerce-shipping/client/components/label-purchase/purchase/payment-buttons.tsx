import { MouseEventHandler } from 'react';
import {
	__experimentalSpacer as Spacer,
	__experimentalText as Text,
	Animate,
	Button,
	CheckboxControl,
	ExternalLink,
	Flex,
	FlexBlock,
	Notice as LegacyNotice,
} from '@wordpress/components';
import {
	createInterpolateElement,
	createPortal,
	useCallback,
	useEffect,
	useLayoutEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { Link } from 'components/wc';
import { __, sprintf } from '@wordpress/i18n';
import { uniq } from 'lodash';
import {
	canManagePayments as canManagePaymentsUtil,
	getAddPaymentMethodURL,
	getParentIdFromSubItemId,
	getUPSDAPTosApprovedVersionsFromError,
	hasPaymentMethod,
	isFedExTosError,
	isFedExRate,
	hasSelectedPaymentMethod,
	mapAddressForRequest,
} from 'utils';
import { CreditCardButton } from './credit-card-button';
import { requestDestinationAddressModal, settingsPageUrl } from '../constants';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { getShipmentTitle } from '../utils';
import {
	CoreDataInvalidateDispatch,
	Label,
	LabelPurchaseError,
	Order,
	RateWithParent,
	WPErrorRESTResponse,
} from 'types';
import { dispatch, select, useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { labelPurchaseStore } from 'data/label-purchase';
import { EssentialDetails } from '../essential-details';
import { recordEvent } from 'utils/tracks';
import { LABEL_PURCHASE_STATUS } from 'data/constants';
import { UPSDAPTos } from 'components/carrier/upsdap/upsdap-tos';
import { FedExTos } from 'components/carrier/fedex/fedex-tos';
import apiFetch from '@wordpress/api-fetch';
import { getCarrierStrategyPath } from 'data/routes';
import { getChangePaymentMethodUrl } from 'components/shipping-settings/constants';
import { usePaymentMethodPolling } from './use-payment-method-polling';
import { Notice } from '@wordpress/ui';

interface PaymentButtonsProps {
	order: Order;
}

const getPackageDimensionForTracks = ( dimension?: string | number | null ) => {
	if ( dimension === undefined || dimension === null || dimension === '' ) {
		return undefined;
	}

	const numericDimension = Number( dimension );

	return Number.isFinite( numericDimension ) ? numericDimension : undefined;
};

export const PaymentButtons = ( { order }: PaymentButtonsProps ) => {
	const {
		shipment: {
			shipments,
			setShipments,
			selections,
			currentShipmentId,
			getShipmentOrigin,
			getShipmentDestination,
			isExtraLabelPurchaseValid,
			getShipmentType,
			getCurrentShipmentIsReturn,
		},
		labels: {
			selectedLabelSize,
			requestLabelPurchase,
			isPurchasing,
			isUpdatingStatus,
			hasPurchasedLabel,
			getCurrentShipmentLabel,
			hasMissingPurchase,
		},
		packages: { getPackageForRequest },
		rates: {
			isFetching,
			userRateFetchNonce,
			getSelectedRate,
			fetchRates,
			matchAndSelectRate,
		},
		account: {
			accountSettings,
			canPurchase,
			setAccountCompleteOrder,
			getAccountCompleteOrder,
			getNextPaymentMethod,
			getSubscriptionId,
		},
		nextDesign,
	} = useLabelPurchaseContext();
	const nextPaymentMethod = getNextPaymentMethod();
	const subscriptionId = getSubscriptionId();
	const changePaymentMethodUrl = getChangePaymentMethodUrl( subscriptionId );

	const lastOrderCompleted = getAccountCompleteOrder();
	const [ errors, setErrors ] = useState< LabelPurchaseError | null >( null );
	const [ purchaseStartTime, setPurchaseStartTime ] = useState<
		number | null
	>( null );
	const [ markOrderAsCompleted, setMarkOrderAsCompleted ] =
		useState( lastOrderCompleted );
	const orderStatus = select( labelPurchaseStore ).getOrderStatus();

	const shipmentOrigin = getShipmentOrigin();

	const selectedRate = getSelectedRate();
	const getFedExPhoneError = ( rate: RateWithParent | null | undefined ) => {
		const destinationPhone = String(
			getShipmentDestination()?.phone ?? ''
		).trim();

		if ( isFedExRate( rate ) && ! destinationPhone ) {
			return sprintf(
				/* translators: %s is the selected FedEx service name, e.g. FedEx Ground Economy. */
				__(
					'%s requires a recipient phone number.',
					'woocommerce-shipping'
				),
				rate?.rate?.title ?? __( 'FedEx', 'woocommerce-shipping' )
			);
		}

		return null;
	};
	const fedExPhoneErrorMessage = getFedExPhoneError( selectedRate );
	const fedExPhoneNotice = fedExPhoneErrorMessage ? (
		<Notice.Root
			className="purchase-label-fedex-phone-notice"
			intent="error"
		>
			<Notice.Description>
				<Text>{ fedExPhoneErrorMessage }</Text>
			</Notice.Description>
			<Notice.Actions>
				<Notice.ActionButton
					onClick={ () => requestDestinationAddressModal() }
				>
					{ __( 'Add phone number', 'woocommerce-shipping' ) }
				</Notice.ActionButton>
			</Notice.Actions>
		</Notice.Root>
	) : null;
	const [ UPSDAPTosError, setUPSDAPTosError ] =
		useState< LabelPurchaseError | null >( null );
	const [ fedExTosError, setFedExTosError ] =
		useState< LabelPurchaseError | null >( null );
	const fedExTosSelectedRateRef = useRef< RateWithParent | null >( null );
	const [ fedExTosConfirmError, setFedExTosConfirmError ] =
		useState< LabelPurchaseError | null >( null );
	const [ isTOSConfirming, setIsTOSConfirming ] = useState( false );
	const canManagePayments = canManagePaymentsUtil( { accountSettings } );

	const isOrderCompleted = useMemo( () => {
		return order.status === 'completed' || orderStatus === 'completed';
	}, [ order, orderStatus ] );

	const resetErrors = () => {
		setErrors( null );
	};

	const invalidateSiteSettingsRecord = useCallback( () => {
		const coreDataDispatch = dispatch(
			coreStore
		) as unknown as CoreDataInvalidateDispatch;

		void coreDataDispatch.invalidateResolution( 'getEntityRecord', [
			'root',
			'site',
			undefined,
		] );
	}, [] );

	const {
		isPolling: isPaymentMethodPolling,
		startPolling: startPaymentMethodPolling,
	} = usePaymentMethodPolling( {
		isResolved: !! nextPaymentMethod,
		onPoll: invalidateSiteSettingsRecord,
	} );

	const purchaseAPIErrors = useSelect(
		( s ) =>
			s( labelPurchaseStore ).getPurchaseAPIError( currentShipmentId ),
		[ currentShipmentId ]
	);

	useEffect( () => {
		if ( purchaseAPIErrors ) {
			setErrors( purchaseAPIErrors );
		}
	}, [ purchaseAPIErrors ] );

	useLayoutEffect( () => {
		if ( isPurchasing ) {
			document
				.querySelector(
					'.label-purchase-modal .components-modal__content'
				)
				?.scrollTo( {
					left: 0,
					top: 0,
					behavior: 'smooth',
				} );
		}
	}, [ isPurchasing ] );

	const shipmentsCount = Object.keys( shipments ).length;
	const purchaseButtonLabelDefault =
		shipmentsCount > 1
			? sprintf(
					// translators: %s is the shipment title as Shipment 1/2, Shipment 2/2, etc.
					__( 'Purchase %s', 'woocommerce-shipping' ),
					getShipmentTitle(
						currentShipmentId,
						shipmentsCount,
						getShipmentType( currentShipmentId )
					)
			  )
			: __( 'Purchase label', 'woocommerce-shipping' );
	const purchaseButtonLabel = nextDesign
		? __( 'Buy shipping label', 'woocommerce-shipping' )
		: purchaseButtonLabelDefault;

	const addCardButtonDescription = (
		onAddCard: MouseEventHandler< HTMLAnchorElement >
	) =>
		createInterpolateElement(
			__(
				'To print this shipping label, <a>add a credit card to your account</a>.',
				'woocommerce-shipping'
			),
			{
				a: (
					<ExternalLink onClick={ onAddCard } href="#" role="button">
						{ ' ' }
					</ExternalLink>
				),
			}
		);
	const chooseCardButtonDescription = (
		onChooseCard: MouseEventHandler< HTMLAnchorElement >
	) =>
		createInterpolateElement(
			__(
				'To print this shipping label, <a>choose a credit card to add to your account</a>.',
				'woocommerce-shipping'
			),
			{
				a: (
					<Link
						onClick={ onChooseCard }
						type="wc-admin"
						href="#"
						role="button"
					>
						{ ' ' }
					</Link>
				),
			}
		);

	const updateOrderStatus = useCallback(
		async ( label?: Label ) => {
			if (
				markOrderAsCompleted &&
				label?.status === LABEL_PURCHASE_STATUS.PURCHASED
			) {
				await dispatch( labelPurchaseStore ).updateOrderStatus( {
					orderId: `${ order.id }`,
					status: 'completed',
				} );
			}
		},
		[ markOrderAsCompleted, order.id ]
	);

	useEffect( () => {
		if ( ! isPurchasing && ! isUpdatingStatus && ! errors ) {
			( async () => {
				await updateOrderStatus( getCurrentShipmentLabel() );
			} )();
		}
	}, [
		isPurchasing,
		isUpdatingStatus,
		errors,
		getCurrentShipmentLabel,
		updateOrderStatus,
	] );

	useEffect( () => {
		if ( orderStatus === 'completed' ) {
			if ( window.parent?.document ) {
				const orderStatusSelect = window.parent.document.querySelector(
					'#order_data #order_status'
				)!;
				if ( orderStatusSelect ) {
					( orderStatusSelect as HTMLSelectElement ).value =
						'wc-completed';
					orderStatusSelect.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);
				}
			}
		}
	}, [ orderStatus ] );

	const maybeUpdateShipmentsFromSelection = () => {
		// Only proceed if there are selections for the current shipment
		if ( selections[ currentShipmentId ]?.length > 0 ) {
			const selection = selections[ currentShipmentId ];
			const currentShipment = shipments[ currentShipmentId ];

			// Map through current shipment items and update based on selection
			const updatedShipment = currentShipment
				.map( ( item ) => {
					// Handle items without sub-items
					if ( ! item.subItems || item.subItems.length === 0 ) {
						return (
							selection.find( ( s ) => s.id === item.id ) ?? null
						);
					}

					// Find selected sub-items for current item
					const selectedSubItems = item.subItems.filter(
						( subItem ) =>
							selection.some(
								( s ) =>
									s.id ===
										getParentIdFromSubItemId(
											subItem.id
										) || s.id === subItem.id
							)
					);

					// An item with all subitems selected
					const selectedItem = selection.find(
						( s ) => s.id === item.id
					);

					// If no sub-items selected, exclude this item
					if ( ! selectedItem && selectedSubItems.length === 0 ) {
						return null;
					}

					// Return updated item with selected sub-items
					return {
						...item,
						subItems: selectedSubItems,
						quantity:
							selectedSubItems.length > 0
								? selectedSubItems.length
								: item.quantity ?? 1,
					};
				} )
				// Remove null items from the shipment
				.filter(
					( item ): item is ( typeof currentShipment )[ 0 ] =>
						item !== null
				);

			// Create new shipments object with updated shipment
			const updatedShipments = {
				...shipments,
				[ currentShipmentId ]: updatedShipment,
			};

			// Simplifying the shipment to remove unnecessary data into database.
			// This is the continuation process from `createShipmentForExtraLabel()` function.
			const simplifiedShipments = Object.entries(
				updatedShipments
			).reduce(
				( acc, [ key, shipment ] ) => ( {
					...acc,
					[ key ]: shipment.map( ( { id, subItems } ) => ( {
						id,
						subItems: subItems.map(
							( { id: subItemId } ) => subItemId
						),
					} ) ),
				} ),
				{}
			);

			// Update local state and dispatch to store
			setShipments( updatedShipments );
			dispatch( labelPurchaseStore ).updateShipments( {
				shipments: simplifiedShipments,
				orderId: `${ order?.id }`,
				shipmentIdsToUpdate: {},
			} );
		}
	};

	const purchaseLabel = async ( rate: RateWithParent ) => {
		resetErrors();
		if ( hasPurchasedLabel( false ) ) {
			return;
		}

		const fedExError = getFedExPhoneError( rate );
		if ( fedExError ) {
			setErrors( {
				cause: 'carrier_error',
				message: [ fedExError ],
			} );
			return;
		}

		maybeUpdateShipmentsFromSelection();

		const selectedPackageForTracks = getPackageForRequest();
		const tracksProperties = {
			order_product_count: order.line_items.length,
			shipment_product_count: shipments[ currentShipmentId ].length,
			order_shipping_total: order.total_shipping,
			order_total: order.total,
			order_shipping_method: order.shipping_methods,
			mark_order_as_completed: markOrderAsCompleted,
			selected_label_size: selectedLabelSize.key,
			order_destination_country: order.shipping_address.country,
			carrier_id: getSelectedRate()?.rate.carrierId,
			rate: getSelectedRate()?.rate.rate,
			retail_rate: getSelectedRate()?.rate.retailRate,
			service_id: getSelectedRate()?.rate.serviceId,
			package_id: selectedPackageForTracks?.id,
			is_letter: selectedPackageForTracks?.isLetter,
			width: getPackageDimensionForTracks(
				selectedPackageForTracks?.width
			),
			height: getPackageDimensionForTracks(
				selectedPackageForTracks?.height
			),
			length: getPackageDimensionForTracks(
				selectedPackageForTracks?.length
			),
		};

		if ( nextDesign ) {
			setPurchaseStartTime( Date.now() );
			recordEvent( 'shipping_label_button_click', {
				button_label: 'buy_shipping_label',
				...tracksProperties,
			} );
		} else {
			recordEvent(
				'label_purchase_purchase_shipping_label_clicked',
				tracksProperties
			);
		}

		resetErrors();
		try {
			await requestLabelPurchase( order.id, rate );
			const currentShipmentLabel =
				select( labelPurchaseStore ).getPurchasedLabel(
					currentShipmentId
				);
			if ( ! errors ) {
				await updateOrderStatus( currentShipmentLabel );
			}
		} catch ( e ) {
			const error = e as unknown as WPErrorRESTResponse &
				LabelPurchaseError;

			if ( error.code === 'missing_upsdap_terms_of_service_acceptance' ) {
				setPurchaseStartTime( null );
				setUPSDAPTosError( error );
				return;
			}

			if ( isFedExTosError( error ) ) {
				setPurchaseStartTime( null );
				fedExTosSelectedRateRef.current = rate;
				setFedExTosError( error );
				return;
			}

			if ( nextDesign ) {
				setPurchaseStartTime( null );
				const errorCode =
					( error as LabelPurchaseError ).code ??
					( error as LabelPurchaseError ).cause ??
					( error as WPErrorRESTResponse ).data?.status ??
					'unknown';
				recordEvent( 'shipping_label_purchase_error', {
					error_code: String( errorCode ),
				} );
			}

			// If it's not the UPS DAP TOS error, treat it as a standard LabelPurchaseError.
			setErrors( {
				cause: 'purchase_error',
				message: error.message,
				actions: error.actions,
			} );
		}
	};

	// Add handler for UPS DAP TOS.
	const handleUPSDAPTos = {
		close: () => {
			setUPSDAPTosError( null );
			setErrors( {
				cause: 'carrier_error',
				message: [
					__(
						'You must agree to the UPS® Terms and Conditions to purchase a UPS® label.',
						'woocommerce-shipping'
					),
				],
			} );
		},
		confirm: async ( confirmed: boolean ) => {
			resetErrors();

			try {
				const response: { success: boolean } = await apiFetch( {
					path: getCarrierStrategyPath( 'upsdap' ),
					method: 'POST',
					data: {
						origin: mapAddressForRequest( shipmentOrigin ),
						confirmed,
					},
				} );

				if ( ! response.success ) {
					// Skip to the `catch` clause to display the error.
					throw new Error(
						__(
							'We were unable to update your acceptance of the UPS® Terms and Conditions. Please try again later or contact WooCommerce support if the issue persists.',
							'woocommerce-shipping'
						)
					);
				}

				// Fetch rates again after successful TOS acceptance.
				// We do this to re-create shipments using the newly created carrier account.
				await fetchRates( getPackageForRequest() );

				setUPSDAPTosError( null );

				/**
				 * Now that we've refetched rates, we need to reselect the rate that was previously selected.
				 * We can't purchase the UPS rate that was previously selected because it was created without TOS acceptance.
				 */
				if ( selectedRate ) {
					const switchedRate = matchAndSelectRate( selectedRate );
					if ( switchedRate ) {
						purchaseLabel( switchedRate );
					} else {
						setErrors( {
							cause: 'rate_mismatch',
							message: [
								__(
									'The shipping rate has changed significantly. Please review the available rates and select one to continue.',
									'woocommerce-shipping'
								),
							],
						} );
					}
				}
			} catch {
				setErrors( {
					cause: 'carrier_error',
					message: [
						__(
							'We were unable to update your acceptance of the UPS® Terms and Conditions. Please try again later or contact WooCommerce support if the issue persists.',
							'woocommerce-shipping'
						),
					],
				} );
			} finally {
				setIsTOSConfirming( false );
			}
		},
	};

	const handleFedExTos = {
		close: () => {
			setFedExTosError( null );
			setFedExTosConfirmError( null );
			setErrors( {
				cause: 'carrier_error',
				message: [
					__(
						'You must agree to the FedEx Terms of Service to purchase a FedEx label.',
						'woocommerce-shipping'
					),
				],
			} );
		},
		confirm: async () => {
			resetErrors();
			setFedExTosConfirmError( null );

			try {
				const response: { success: boolean } = await apiFetch( {
					path: getCarrierStrategyPath( 'fedex' ),
					method: 'POST',
					data: {
						confirmed: true,
					},
				} );

				if ( ! response.success ) {
					throw new Error(
						__(
							'We were unable to record your acceptance of the FedEx Terms of Service. Please try again later or contact WooCommerce support if the issue persists.',
							'woocommerce-shipping'
						)
					);
				}
			} catch {
				setFedExTosConfirmError( {
					cause: 'carrier_error',
					message: [
						__(
							'We were unable to record your acceptance of the FedEx Terms of Service. Please try again later or contact WooCommerce support if the issue persists.',
							'woocommerce-shipping'
						),
					],
				} );
				setIsTOSConfirming( false );
				return;
			}

			// TOS accepted successfully. Close the modal and retry the purchase.
			setFedExTosError( null );
			setIsTOSConfirming( false );

			await fetchRates( getPackageForRequest() );

			const rateBeforeTos = fedExTosSelectedRateRef.current;
			fedExTosSelectedRateRef.current = null;

			if ( rateBeforeTos ) {
				const switchedRate = matchAndSelectRate( rateBeforeTos );
				if ( switchedRate ) {
					purchaseLabel( switchedRate );
				} else {
					setErrors( {
						cause: 'rate_mismatch',
						message: [
							__(
								'The shipping rate has changed significantly. Please review the available rates and select one to continue.',
								'woocommerce-shipping'
							),
						],
					} );
				}
			}
		},
	};

	const markAsCompletedCheckboxHandler = () => {
		// If the checkbox is checked, set the markOrderAsCompleted state to true
		// and set the accountCompleteOrder state to true.
		setMarkOrderAsCompleted( ! markOrderAsCompleted );
		setAccountCompleteOrder( ! lastOrderCompleted );
	};

	// Reset errors when shipment origin changes
	useEffect( resetErrors, [ shipmentOrigin ] );

	// Reset purchase errors only when the merchant explicitly triggers a rate
	// fetch (e.g. clicking "Get Rates" or editing shipment inputs). Keying off
	// `userRateFetchNonce` instead of `isFetching` keeps the error visible
	// through system-driven refetches (such as the auto-fetch that runs after a
	// failed purchase resets rates).
	useEffect( () => {
		resetErrors();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ userRateFetchNonce ] );

	// Fire the success event only after the full purchase cycle completes,
	// including any async status polling (PURCHASE_IN_PROGRESS → PURCHASED).
	// purchaseStartTime is set on button click and cleared on error, so this
	// effect is a no-op on mount and after failed purchases.
	useEffect( () => {
		if (
			nextDesign &&
			purchaseStartTime !== null &&
			! isPurchasing &&
			! isUpdatingStatus &&
			hasPurchasedLabel()
		) {
			recordEvent( 'shipping_label_purchase_success', {
				response_time_ms: Date.now() - purchaseStartTime,
			} );
			setPurchaseStartTime( null );
		}
	}, [
		nextDesign,
		purchaseStartTime,
		isPurchasing,
		isUpdatingStatus,
		hasPurchasedLabel,
	] );

	const isExtraLabelPurchase = () => {
		return ! hasMissingPurchase();
	};

	return nextDesign ? (
		<>
			{ UPSDAPTosError && (
				<UPSDAPTos
					close={ handleUPSDAPTos.close }
					confirm={ handleUPSDAPTos.confirm }
					shipmentOrigin={ shipmentOrigin }
					acceptedVersions={ getUPSDAPTosApprovedVersionsFromError(
						UPSDAPTosError
					) }
					isConfirming={ isTOSConfirming }
					setIsConfirming={ setIsTOSConfirming }
				/>
			) }
			{ fedExTosError && (
				<FedExTos
					close={ handleFedExTos.close }
					confirm={ handleFedExTos.confirm }
					error={ fedExTosConfirmError }
					isConfirming={ isTOSConfirming }
					setIsConfirming={ setIsTOSConfirming }
				/>
			) }
			{ nextPaymentMethod && (
				<Button
					variant="primary"
					disabled={
						! selectedRate ||
						isPurchasing ||
						isFetching ||
						isUpdatingStatus ||
						hasPurchasedLabel( false ) ||
						! shipmentOrigin.isApproved ||
						!! fedExPhoneErrorMessage ||
						( isExtraLabelPurchase() &&
							! isExtraLabelPurchaseValid() )
					}
					onClick={ () => {
						const rate = getSelectedRate();
						if ( rate ) {
							purchaseLabel( rate );
						}
					} }
					isBusy={ isPurchasing }
					aria-disabled={
						! selectedRate ||
						isPurchasing ||
						isFetching ||
						isUpdatingStatus ||
						hasPurchasedLabel( false ) ||
						! shipmentOrigin.isApproved ||
						!! fedExPhoneErrorMessage ||
						( isExtraLabelPurchase() &&
							! isExtraLabelPurchaseValid() )
					}
					size="compact"
				>
					{ purchaseButtonLabel }
				</Button>
			) }
			{ ! nextPaymentMethod &&
				document.getElementById( 'label-purchase-status-notices' ) &&
				createPortal(
					<Animate
						type={ isPaymentMethodPolling ? 'loading' : undefined }
					>
						{ ( { className } ) => (
							<div
								className={ className }
								style={ { marginBottom: '16px' } }
							>
								<Notice.Root intent="error">
									<Notice.Description>
										{ __(
											'A payment method is required to purchase shipping labels.',
											'woocommerce-shipping'
										) }
									</Notice.Description>
									<Notice.Actions>
										<Notice.ActionButton
											onClick={ () => {
												startPaymentMethodPolling();
												window.open(
													changePaymentMethodUrl,
													'_blank',
													'noreferrer'
												);
											} }
										>
											{ __(
												'Add a payment method',
												'woocommerce-shipping'
											) }
										</Notice.ActionButton>
									</Notice.Actions>
								</Notice.Root>
							</div>
						) }
					</Animate>,
					document.getElementById( 'label-purchase-status-notices' )!
				) }
			{ errors &&
				Object.keys( errors ).length > 0 &&
				createPortal(
					<Flex className="purchase-label-errors" direction="column">
						{ errors && Object.keys( errors ).length > 0 && (
							<Notice.Root intent="error">
								<Notice.Description>
									{ uniq( errors.message ).map(
										( m, index ) => (
											<Text key={ index }>{ m }</Text>
										)
									) }
								</Notice.Description>
							</Notice.Root>
						) }
					</Flex>,
					document.getElementById( 'label-purchase-status-notices' )!
				) }
			{ fedExPhoneErrorMessage &&
				document.getElementById( 'label-purchase-status-notices' ) &&
				createPortal(
					<Flex className="purchase-label-errors" direction="column">
						{ fedExPhoneNotice }
					</Flex>,
					document.getElementById( 'label-purchase-status-notices' )!
				) }
		</>
	) : (
		<>
			{ UPSDAPTosError && (
				<UPSDAPTos
					close={ handleUPSDAPTos.close }
					confirm={ handleUPSDAPTos.confirm }
					shipmentOrigin={ shipmentOrigin }
					acceptedVersions={ getUPSDAPTosApprovedVersionsFromError(
						UPSDAPTosError
					) }
					isConfirming={ isTOSConfirming }
					setIsConfirming={ setIsTOSConfirming }
				/>
			) }
			{ fedExTosError && (
				<FedExTos
					close={ handleFedExTos.close }
					confirm={ handleFedExTos.confirm }
					error={ fedExTosConfirmError }
					isConfirming={ isTOSConfirming }
					setIsConfirming={ setIsTOSConfirming }
				/>
			) }
			<Flex className="purchase-label-buttons" direction="column">
				{ fedExPhoneNotice }
				{ canPurchase() && (
					<>
						<Flex>
							<Button
								variant="primary"
								disabled={
									! selectedRate ||
									isPurchasing ||
									isFetching ||
									isUpdatingStatus ||
									hasPurchasedLabel( false ) ||
									! shipmentOrigin.isVerified ||
									!! fedExPhoneErrorMessage ||
									( isExtraLabelPurchase() &&
										! isExtraLabelPurchaseValid() )
								}
								onClick={ () => {
									const rate = getSelectedRate();
									if ( rate ) {
										purchaseLabel( rate );
									}
								} }
								isBusy={ isPurchasing }
								aria-disabled={
									! selectedRate ||
									isPurchasing ||
									isFetching ||
									isUpdatingStatus ||
									hasPurchasedLabel( false ) ||
									! shipmentOrigin.isVerified ||
									!! fedExPhoneErrorMessage ||
									( isExtraLabelPurchase() &&
										! isExtraLabelPurchaseValid() )
								}
								__next40pxDefaultSize={ nextDesign }
							>
								{ purchaseButtonLabel }
							</Button>
						</Flex>
						<FlexBlock>
							<EssentialDetails />
						</FlexBlock>
						{ ! hasPurchasedLabel( false ) &&
							! isOrderCompleted &&
							! getCurrentShipmentIsReturn() && (
								<Flex>
									<CheckboxControl
										label={ __(
											'After purchasing a label, mark this order as complete and notify the customer',
											'woocommerce-shipping'
										) }
										onChange={
											markAsCompletedCheckboxHandler
										}
										checked={ lastOrderCompleted }
										disabled={ isPurchasing }
										aria-disabled={ isPurchasing }
										className="purchase-label-mark-order-complete"
										__nextHasNoMarginBottom={ true }
									/>
								</Flex>
							) }
					</>
				) }
				{ ! hasPaymentMethod( { accountSettings } ) && (
					<>
						{ canManagePayments ? (
							<CreditCardButton
								url={ getAddPaymentMethodURL() }
								buttonLabel={ __(
									'Add credit card',
									'woocommerce-shipping'
								) }
								buttonDescription={ addCardButtonDescription }
							/>
						) : (
							<LegacyNotice
								status="warning"
								isDismissible={ false }
							>
								{ __(
									'Please contact your site administrator to add a payment method.',
									'woocommerce-shipping'
								) }
							</LegacyNotice>
						) }
					</>
				) }
				{ hasPaymentMethod( { accountSettings } ) &&
					! hasSelectedPaymentMethod( { accountSettings } ) && (
						<>
							{ canManagePayments ? (
								<CreditCardButton
									url={ settingsPageUrl }
									buttonLabel={ __(
										'Choose credit card',
										'woocommerce-shipping'
									) }
									buttonDescription={
										chooseCardButtonDescription
									}
								/>
							) : (
								<LegacyNotice
									status="warning"
									isDismissible={ false }
								>
									{ __(
										'Please contact your site administrator to set a default payment method.',
										'woocommerce-shipping'
									) }
								</LegacyNotice>
							) }
						</>
					) }
			</Flex>

			<Spacer />
			{ errors && Object.keys( errors ).length > 0 && (
				<LegacyNotice
					status="error"
					actions={ uniq( errors.actions ) }
					onDismiss={ resetErrors }
				>
					{ uniq( errors.message ).map( ( m, index ) => (
						<p key={ index }>{ m }</p>
					) ) }
				</LegacyNotice>
			) }
		</>
	);
};
