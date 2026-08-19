import {
	createInterpolateElement,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import {
	__experimentalDivider as Divider,
	__experimentalHeading as Heading,
	__experimentalText as Text,
	BaseControl,
	Button,
	CheckboxControl,
	Modal,
	Notice,
} from '@wordpress/components';
import { pencil as edit, help } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { dispatch, useSelect } from '@wordpress/data';
import {
	addressToString,
	formatAddressFields,
	getCurrentOrder,
	areAddressesClose,
	returnPurchasedLabel,
	getPromoDiscount,
} from 'utils';
import { addressStore } from 'data/address';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { AddressStep } from 'components/address-step';
import { ADDRESS_TYPES } from 'data/constants';
import { AddressVerifiedIcon } from 'components/address-verified-icon';
import { ControlledPopover } from 'components/controlled-popover';
import { withBoundary } from 'components/HOC';
import { ShipFromSelect } from './ship-from-select';
import { ShipmentCosts } from './shipment-costs';
import { ShippingDate } from './shipping-date';
import { PackageDetails } from './package-details';
import { ShippingSummary } from '../design-next/internal/shipping-summary';
import { useDestinationAddressModal } from '../hooks/use-destination-address-modal';

export const ShipmentDetails = withBoundary(
	( { order, destinationAddress } ) => {
		const [ isAddressModalOpen, setIsAddressModalOpen ] = useState( false );
		const shippingType = getCurrentOrder().shipping_methods;
		const isDestinationAddressVerified = useSelect(
			( select ) =>
				select( addressStore ).getIsAddressVerified(
					ADDRESS_TYPES.DESTINATION
				),
			[]
		);
		const normalisedDestinationAddress = useSelect(
			( select ) =>
				select( addressStore ).getNormalizedAddress(
					ADDRESS_TYPES.DESTINATION
				),
			[]
		);
		const {
			storeCurrency,
			rates: { getSelectedRate, updateRates, getSelectedRateOptions },
			labels: {
				hasPurchasedLabel,
				getCurrentShipmentLabel,
				isPurchasing,
			},
			shipment: {
				getShipmentOrigin,
				getShipmentDestination,
				getShipmentPurchaseOrigin,
				getCurrentShipmentDate,
				setCurrentShipmentDate,
				getCurrentShipmentIsReturn,
				returnShipments,
				currentShipmentId,
			},
			nextDesign,
		} = useLabelPurchaseContext();

		const showPackageDetails =
			hasPurchasedLabel( false ) || getCurrentShipmentIsReturn();
		const returnShipmentInfo =
			getCurrentShipmentIsReturn() && returnShipments && currentShipmentId
				? returnShipments[ currentShipmentId ]
				: undefined;

		/**
		 * 1) We need to run the auto verification process only once but the useEffect runs on every render. So we use a ref
		 * to keep track of it, but if `normalisedDestinationAddress` is not defined yet, we want to allow it to run again
		 * that's why passing an empty dependency array wouldn't work, and we need to use a ref to keep track of the
		 * effective runs
		 *
		 * 2) We should also not run the auto verification process if the address modal is open.
		 *
		 */
		const hasAutoVerificationRunOnce = useRef( false );

		useDestinationAddressModal( isAddressModalOpen, setIsAddressModalOpen );

		useEffect(
			() => {
				if (
					hasAutoVerificationRunOnce.current ||
					isAddressModalOpen
				) {
					return;
				}

				// Check if the destination address is verified, if not, run it through the normalization process and then through areAddressesClose to determine if it's close enough to auto verify the address.
				const verifyShippingAddress = async () => {
					if ( isDestinationAddressVerified ) {
						return Promise.resolve();
					}

					await dispatch( addressStore ).verifyOrderShippingAddress( {
						orderId: order.id,
					} );

					// If destination address is not verified, lets normalize it and check if it's close to the verified address and then auto verify it.
					if ( ! isDestinationAddressVerified ) {
						if ( ! normalisedDestinationAddress ) {
							return Promise.resolve();
						}

						// Set the flag to true so that the auto verification process runs only once.
						hasAutoVerificationRunOnce.current = true;

						const transformedNormalisedAddress = {
							...normalisedDestinationAddress,
							address1: normalisedDestinationAddress.address_1,
							address2: normalisedDestinationAddress.address_2,
						};

						const shouldAutoVerify = areAddressesClose(
							transformedNormalisedAddress,
							destinationAddress
						);

						if ( ! shouldAutoVerify ) {
							return Promise.resolve();
						}

						// If made it till here, verify the address.
						await dispatch( addressStore ).updateShipmentAddress(
							{
								orderId: order.id ?? '',
								address: transformedNormalisedAddress,
								isVerified: true, // Either the address is verified or the normalized address is selected
							},
							ADDRESS_TYPES.DESTINATION
						);
					}

					return Promise.resolve();
				};

				verifyShippingAddress();
			},
			// eslint-disable-next-line react-hooks/exhaustive-deps -- isAddressModalOpen is not a dependency
			[
				order,
				destinationAddress,
				isDestinationAddressVerified,
				normalisedDestinationAddress,
			]
		);

		const selectedRate = getSelectedRate();

		let discount = selectedRate?.rate
			? selectedRate.rate.retailRate - selectedRate.rate.rate
			: 0;

		const onCompleteCallback = () => {
			setIsAddressModalOpen( false );
			void updateRates( { preserveSelection: true } );
		};

		const currentLabel = getCurrentShipmentLabel();

		const selectedRateOptions = getSelectedRateOptions() ?? {};

		const rateDiscountText = hasPurchasedLabel( false )
			? // translators: %s is the discount amount
			  __(
					'You saved %s with WooCommerce Shipping. <i/>',
					'woocommerce-shipping'
			  )
			: // translators: %s is the discount amount
			  __(
					'You save %s with WooCommerce Shipping. <i/>',
					'woocommerce-shipping'
			  );

		const purchasedLabel = returnPurchasedLabel( currentLabel );

		if ( discount ) {
			const promoDiscount = purchasedLabel
				? purchasedLabel.promoDiscount
				: getPromoDiscount(
						selectedRate.rate.rate,
						selectedRate.rate.promoId
				  );

			if ( promoDiscount ) {
				discount += promoDiscount;
			}
		}

		const ShipFromControl = ( { label } ) => (
			<BaseControl
				id="ship-from"
				label={ label }
				// Opting into the new styles for margin bottom
				__nextHasNoMarginBottom={ true }
			>
				{ ! hasPurchasedLabel( false ) && (
					<ShipFromSelect disabled={ hasPurchasedLabel( false ) } />
				) }
				{ hasPurchasedLabel( false ) && getShipmentPurchaseOrigin() && (
					<Text>
						{ addressToString( getShipmentPurchaseOrigin() ) }
					</Text>
				) }

				{ currentLabel?.isLegacy && ( // Inaccurate ship from address
					<Text>**************************</Text>
				) }
			</BaseControl>
		);

		const isDestinationResidential =
			destinationAddress?.residential ?? ! destinationAddress?.company;

		const purchasedDestination = hasPurchasedLabel( false )
			? getShipmentDestination()
			: null;
		const isPurchasedDestinationResidential =
			purchasedDestination?.residential ??
			! purchasedDestination?.company;

		const ShipToControl = ( { label } ) => (
			<BaseControl
				id="ship-to"
				label={ label }
				className="purchase-label__ship-to"
				// Opting into the new styles for margin bottom
				__nextHasNoMarginBottom={ true }
			>
				{ ! hasPurchasedLabel( false ) && (
					<div className="purchase-label__ship-to-value">
						<Text display="flex">
							<Button
								onClick={ () => setIsAddressModalOpen( true ) }
								icon={ edit }
								className="ship-to-edit-icon"
								title={ __(
									'Click to change address',
									'woocommerce-shipping'
								) }
							/>
							{ addressToString( destinationAddress ) }
							<AddressVerifiedIcon
								isVerified={ isDestinationAddressVerified }
								onClick={ () => setIsAddressModalOpen( true ) }
								addressType={ ADDRESS_TYPES.DESTINATION }
							></AddressVerifiedIcon>
						</Text>
						{ ! getCurrentShipmentIsReturn() && (
							<div className="purchase-label__residential-toggle-row">
								<CheckboxControl
									__nextHasNoMarginBottom
									className="purchase-label__residential-toggle"
									label={ __(
										'Residential address',
										'woocommerce-shipping'
									) }
									checked={ isDestinationResidential }
									onChange={ ( checked ) => {
										dispatch(
											addressStore
										).setDestinationResidential( checked );
										updateRates();
									} }
								/>
								<span className="purchase-label__residential-toggle-help">
									<ControlledPopover
										icon="info-outline"
										withArrow={ false }
										popoverOptions={ {
											placement: 'top-end',
											offset: 16,
											className:
												'purchase-label__residential-toggle-popover',
										} }
									>
										{ __(
											'Applies residential FedEx rates and services. Uncheck for commercial destinations.',
											'woocommerce-shipping'
										) }
									</ControlledPopover>
								</span>
							</div>
						) }
					</div>
				) }
				{ purchasedDestination && (
					<div className="purchase-label__ship-to-value">
						{ addressToString( purchasedDestination ) }
						{ ! getCurrentShipmentIsReturn() && (
							<span className="purchase-label__ship-to-residential">
								{ isPurchasedDestinationResidential
									? __(
											'Residential',
											'woocommerce-shipping'
									  )
									: __(
											'Commercial',
											'woocommerce-shipping'
									  ) }
							</span>
						) }
					</div>
				) }
			</BaseControl>
		);

		return nextDesign ? (
			<ShippingSummary
				order={ order }
				destinationAddress={ destinationAddress }
			/>
		) : (
			<div className="shipment-details">
				<Heading level={ 3 }>
					{ __( 'Order details', 'woocommerce-shipping' ) }
				</Heading>

				{ getCurrentShipmentIsReturn() ? (
					<>
						<ShipToControl
							label={ __( 'Ship from', 'woocommerce-shipping' ) }
						/>
						<ShipFromControl
							label={ __( 'Return to', 'woocommerce-shipping' ) }
						/>
					</>
				) : (
					<>
						<ShipFromControl
							label={ __( 'Ship from', 'woocommerce-shipping' ) }
						/>
						<ShipToControl
							label={ __( 'Ship to', 'woocommerce-shipping' ) }
						/>
					</>
				) }

				<BaseControl
					id="no-of-items"
					label={ __( 'Number of items', 'woocommerce-shipping' ) }
					// Opting into the new styles for margin bottom
					__nextHasNoMarginBottom={ true }
				>
					<Text>{ order.total_line_items_quantity }</Text>
				</BaseControl>

				<BaseControl
					id="order-value"
					label={ __( 'Order value', 'woocommerce-shipping' ) }
					// Opting into the new styles for margin bottom
					__nextHasNoMarginBottom={ true }
				>
					<Text>{ storeCurrency.formatAmount( order.total ) }</Text>
				</BaseControl>

				<BaseControl
					id="shipping-type"
					label={ __( 'Shipping type', 'woocommerce-shipping' ) }
					// Opting into the new styles for margin bottom
					__nextHasNoMarginBottom={ true }
				>
					<Text>{ shippingType }</Text>
				</BaseControl>

				<BaseControl
					id="shipping-costs"
					label={ __( 'Shipping costs', 'woocommerce-shipping' ) }
					// Opting into the new styles for margin bottom
					__nextHasNoMarginBottom={ true }
				>
					<Text>
						{ storeCurrency.formatAmount( order.total_shipping ) }
					</Text>
				</BaseControl>

				{ showPackageDetails && (
					<>
						<Divider margin="4" />
						<Heading level={ 3 }>
							{ __( 'Package details', 'woocommerce-shipping' ) }
						</Heading>
						<PackageDetails
							label={ returnPurchasedLabel( currentLabel ) }
							returnShipmentInfo={ returnShipmentInfo }
							currentShipmentId={ currentShipmentId }
						/>
					</>
				) }

				<section
					className={ `shipment-details__costs${
						selectedRate ?? currentLabel?.rate ? ' has-rates' : ''
					}` }
				>
					<Divider margin="4" />
					<Heading level={ 3 }>
						{ __( 'Shipment details', 'woocommerce-shipping' ) }
					</Heading>

					{ ! getCurrentShipmentIsReturn() &&
						( ! hasPurchasedLabel( false ) ||
							Boolean(
								getCurrentShipmentDate()?.shippingDate
							) ) && (
							<>
								<ShippingDate
									canSelectDate={
										! hasPurchasedLabel( false ) &&
										! isPurchasing
									}
									shippingDate={
										getCurrentShipmentDate()?.shippingDate
									}
									setShippingDate={ setCurrentShipmentDate }
								/>
								<Divider margin="4" />
							</>
						) }
					<ShipmentCosts
						selectedRate={ selectedRate }
						label={ purchasedLabel }
						rateOptions={ selectedRateOptions }
					/>

					{ Boolean( selectedRate ) && Boolean( discount ) && (
						<>
							<Notice
								className="rate-discount"
								isDismissible={ false }
								status={
									hasPurchasedLabel( false )
										? 'success'
										: null
								}
							>
								{ createInterpolateElement(
									sprintf(
										rateDiscountText,
										storeCurrency.formatAmount( discount )
									),
									{
										i: (
											<ControlledPopover
												icon={ help }
												withArrow={ false }
												trigger="hover"
											>
												{ __(
													'WooCommerce Shipping gives you access to commercial pricing, which is discounted over retail rates.',
													'woocommerce-shipping'
												) }
											</ControlledPopover>
										),
									}
								) }
							</Notice>
						</>
					) }
				</section>

				{ isAddressModalOpen && (
					<Modal
						className="edit-address-modal"
						onRequestClose={ () => setIsAddressModalOpen( false ) }
						focusOnMount
						shouldCloseOnClickOutside={ false }
						title={ __(
							'Edit destination address',
							'woocommerce-shipping'
						) }
					>
						<AddressStep
							type={ ADDRESS_TYPES.DESTINATION }
							address={ formatAddressFields(
								destinationAddress
							) }
							onCompleteCallback={ onCompleteCallback }
							onCancelCallback={ () =>
								setIsAddressModalOpen( false )
							}
							orderId={ `${ order.id }` }
							originCountry={ getShipmentOrigin()?.country }
						/>
					</Modal>
				) }
			</div>
		);
	}
)( 'ShipmentDetails' );
