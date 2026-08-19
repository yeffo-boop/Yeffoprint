import {
	__experimentalText as Text,
	__experimentalDivider as Divider,
	Flex,
	Icon,
} from '@wordpress/components';
import { Badge } from '@wordpress/ui';
import { __, _n, sprintf } from '@wordpress/i18n';
import { TAB_NAMES } from 'components/label-purchase/packages';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { Destination, OriginAddress, Rate, RateWithParent } from 'types';
import {
	addressToString,
	applyPromo,
	getCurrentOrder,
	isDeliveryDateValid,
} from 'utils';
import { dateI18n } from '@wordpress/date';
import { subline } from 'components/icons';
import { customerPaidBannerStyles } from 'components/label-purchase/constants';

interface ShippingSummaryProps {
	destinationAddress: OriginAddress | Destination;
}

const RateExtraLine = ( {
	label,
	value,
	isSubLine = false,
}: {
	label: string;
	value: string | number | undefined;
	isSubLine?: boolean;
} ) => (
	<Flex direction={ 'row' } align="center" justify="space-between">
		<Flex direction={ 'row' } align="center" justify="flex-start" gap={ 1 }>
			{ isSubLine && <Icon icon={ subline } size={ 16 } color="#666" /> }
			<Text>{ label }</Text>
		</Flex>
		<Text variant="muted">{ value ?? '-' }</Text>
	</Flex>
);

const SummaryItem = ( {
	label,
	children,
}: {
	label: string;
	children: React.ReactNode;
} ) => (
	<Flex direction={ 'column' } align="flex-start" justify="center" gap={ 1 }>
		<Text variant="muted" size={ 11 } weight={ 500 } upperCase>
			{ label }
		</Text>
		{ children }
	</Flex>
);

export const ShippingSummary = ( {
	destinationAddress,
}: ShippingSummaryProps ) => {
	const {
		storeCurrency,
		packages: { currentPackageTab, getCustomPackage, getSelectedPackage },
		rates: { getSelectedRate, getSelectedRateOptions, availableRates },
		shipment: { getCurrentShipmentDate },
		weight: { getShipmentTotalWeight },
	} = useLabelPurchaseContext();

	const selectedRate = getSelectedRate() as RateWithParent | null;
	const selectedRateOptions = getSelectedRateOptions();
	const selectedPackage = getSelectedPackage();
	const customPackage = getCustomPackage();

	const discount = selectedRate
		? selectedRate.rate.retailRate - selectedRate.rate.rate
		: 0;

	const currentOrder = getCurrentOrder();
	const customerPaidShipping = parseFloat(
		currentOrder?.total_shipping ?? '0'
	);

	const effectiveRate = selectedRate
		? applyPromo( selectedRate.rate.rate, selectedRate.rate.promoId )
		: 0;
	const totalLabelCost = selectedRate
		? Object.values( selectedRateOptions ?? {} ).reduce(
				( acc, option ) => acc + ( option.surcharge ?? 0 ),
				effectiveRate
		  )
		: 0;

	/* translators: Text explaining the rate discount. %s is the discount amount, e.g. "$1.00". */
	const rateDiscountText = __(
		"You're saving %s with carrier discounts",
		'woocommerce-shipping'
	);

	let deliveryDateMessage = '-';
	const shipDate = getCurrentShipmentDate()?.shippingDate;

	if ( availableRates && selectedRate ) {
		// Use parent rate if it exists (e.g., when signature options are selected),
		// otherwise use the selected rate itself
		const rateToLookup = selectedRate.parent ?? selectedRate.rate;

		const { deliveryDateGuaranteed, deliveryDate, deliveryDays } =
			( availableRates![ rateToLookup.carrierId ].find(
				( rate ) => rate.rateId === rateToLookup.rateId
			) ?? {} ) as Rate & {
				deliveryDateGuaranteed?: boolean;
				deliveryDate?: string;
				deliveryDays?: number;
			};

		if (
			deliveryDateGuaranteed &&
			deliveryDate &&
			isDeliveryDateValid( deliveryDate, shipDate )
		) {
			deliveryDateMessage = dateI18n( 'F d', deliveryDate );
		} else if ( deliveryDays ) {
			deliveryDateMessage = sprintf(
				// translators: %s: number of days
				_n(
					'%s business day',
					'%s business days',
					deliveryDays,
					'woocommerce-shipping'
				),
				String( deliveryDays )
			);
		}
	}

	return (
		selectedRate &&
		( ( currentPackageTab === TAB_NAMES.CUSTOM_PACKAGE && customPackage ) ||
			( currentPackageTab === TAB_NAMES.CARRIER_PACKAGE &&
				selectedPackage ) ) && (
			<Flex direction={ 'column' } gap={ 4 }>
				<SummaryItem label={ __( 'Ship to', 'woocommerce-shipping' ) }>
					<Text>
						{ destinationAddress.name ??
							`${ destinationAddress.firstName } ${ destinationAddress.lastName }` }
					</Text>
					<Text display="flex">
						{ addressToString( destinationAddress ) }
					</Text>
				</SummaryItem>
				<SummaryItem label={ __( 'Package', 'woocommerce-shipping' ) }>
					<Text>
						{ currentPackageTab === TAB_NAMES.CUSTOM_PACKAGE &&
							`${ customPackage.width }in × ${
								customPackage.length
							}in × ${
								customPackage.height
							}in, ${ getShipmentTotalWeight() } lb` }
						{ currentPackageTab === TAB_NAMES.CARRIER_PACKAGE &&
							selectedPackage &&
							[
								`${ selectedPackage.width }in × ${ selectedPackage.length }in × ${ selectedPackage.height }in`,
								selectedPackage?.name,
								getShipmentTotalWeight() + ' lb',
							]
								.filter( Boolean )
								.join( ', ' ) }
					</Text>
				</SummaryItem>
				<SummaryItem label={ __( 'Rates', 'woocommerce-shipping' ) }>
					<Flex
						direction={ 'column' }
						gap={ 2 }
						align="stretch"
						justify="center"
						style={ { width: '100%' } }
					>
						<RateExtraLine
							label={
								selectedRate.rate.title +
								' (' +
								deliveryDateMessage +
								')'
							}
							value={
								selectedRate
									? storeCurrency.formatAmount(
											selectedRate.rate.rate ??
												selectedRate.parent?.rate
									  )
									: '-'
							}
						/>
						{ selectedRateOptions?.signature && (
							<RateExtraLine
								isSubLine
								label={
									selectedRateOptions.signature.value.toString() ===
									'adult'
										? __(
												'Adult signature required',
												'woocommerce-shipping'
										  )
										: __(
												'Signature required',
												'woocommerce-shipping'
										  )
								}
								value={
									selectedRateOptions.signature.surcharge
										? storeCurrency.formatAmount(
												selectedRateOptions.signature.surcharge.toFixed(
													2
												)
										  )
										: '-'
								}
							/>
						) }
						{ selectedRateOptions?.carbon_neutral && (
							<RateExtraLine
								isSubLine
								label={ __(
									'Carbon neutral',
									'woocommerce-shipping'
								) }
								value={
									selectedRateOptions.carbon_neutral
										? storeCurrency.formatAmount(
												selectedRateOptions
													.carbon_neutral.surcharge
										  )
										: '-'
								}
							/>
						) }
						{ selectedRateOptions?.additional_handling && (
							<RateExtraLine
								isSubLine
								label={ __(
									'Additional handling',
									'woocommerce-shipping'
								) }
								value={
									selectedRateOptions.additional_handling
										? storeCurrency.formatAmount(
												selectedRateOptions
													.additional_handling
													.surcharge
										  )
										: '-'
								}
							/>
						) }
						{ selectedRateOptions?.saturday_delivery && (
							<RateExtraLine
								isSubLine
								label={ __(
									'Saturday delivery',
									'woocommerce-shipping'
								) }
								value={
									selectedRateOptions.saturday_delivery
										? storeCurrency.formatAmount(
												selectedRateOptions
													.saturday_delivery.surcharge
										  )
										: '-'
								}
							/>
						) }
						<Divider style={ { borderColor: '#f0f0f0' } } />
						<Flex
							direction={ 'row' }
							align="center"
							justify="space-between"
						>
							<Text weight={ 500 }>Total</Text>
							<Text weight={ 500 }>
								{ selectedRate
									? storeCurrency.formatAmount(
											totalLabelCost
									  )
									: '-' }
							</Text>
						</Flex>
					</Flex>
				</SummaryItem>
				{ Boolean( selectedRate ) && Boolean( discount ) && (
					<Flex
						direction={ 'row' }
						align="flex-start"
						justify="flex-start"
					>
						<Badge>
							{ sprintf(
								rateDiscountText,
								storeCurrency.formatAmount( discount )
							) }
						</Badge>
					</Flex>
				) }
				{ ! isNaN( customerPaidShipping ) &&
					customerPaidShipping > 0 && (
						<div
							className="customer-paid-shipping-banner"
							style={ customerPaidBannerStyles.container }
						>
							<Text
								size={ 13 }
								style={ customerPaidBannerStyles.text }
							>
								{ currentOrder?.shipping_methods
									? sprintf(
											// translators: %1$s: the amount the customer paid for shipping, %2$s: the shipping method name
											__(
												'Customer paid: %1$s for shipping (%2$s)',
												'woocommerce-shipping'
											),
											storeCurrency.formatAmount(
												customerPaidShipping
											),
											currentOrder.shipping_methods
									  )
									: sprintf(
											// translators: %s: the amount the customer paid for shipping
											__(
												'Customer paid: %s for shipping',
												'woocommerce-shipping'
											),
											storeCurrency.formatAmount(
												customerPaidShipping
											)
									  ) }
							</Text>
						</div>
					) }
				<Text variant="muted">
					{ __(
						'This order will be fulfilled after you buy the shipping label.',
						'woocommerce-shipping'
					) }
				</Text>
			</Flex>
		)
	);
};
