import {
	__experimentalText as Text,
	Flex,
	FlexBlock,
	FlexItem,
	Card,
	CardBody,
} from '@wordpress/components';
import { Badge as NextBadge } from '@wordpress/ui';
import { dateI18n } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';
import { withBoundary } from 'components/HOC';
import { CarrierIcon } from 'components/carrier-icon';
import { Badge } from 'components/wp';
import { PromoTooltip } from 'components/label-purchase/promo/promo-tooltip';
import { createInterpolateElement } from '@wordpress/element';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { RowExtras } from './row-extras';
import { applyPromo, isDeliveryDateValid } from 'utils';
import clsx from 'clsx';

export const RateRow = withBoundary(
	( {
		rate,
		selected,
		setSelected,
		signatureRequiredRate,
		adultSignatureRequiredRate,
		carbonNeutralRate,
		additionalHandlingRate,
		saturdayDeliveryRate,
		isCheapest,
		isFastest,
	} ) => {
		const {
			rateId,
			carrierId,
			title,
			tracking,
			insurance,
			freePickup,
			deliveryDateGuaranteed,
			deliveryDate,
			deliveryDays,
			caveats,
		} = rate;
		const {
			storeCurrency: { formatAmount },
			rates: { getSelectedRateOptions, selectRateOption },
			shipment: { getCurrentShipmentDate },
			nextDesign,
		} = useLabelPurchaseContext();
		const extrasText = [
			tracking && __( 'tracking', 'woocommerce-shipping' ),
			insurance > 0 &&
				sprintf(
					// translators: %s: insurance amount
					__( 'insurance (up to %s)', 'woocommerce-shipping' ),
					formatAmount( insurance )
				),
			freePickup && __( 'free pickup', 'woocommerce-shipping' ),
		].filter( Boolean );
		const extrasProps = {
			extrasText,
			signatureRequiredRate,
			adultSignatureRequiredRate,
			carbonNeutralRate,
			additionalHandlingRate,
			saturdayDeliveryRate,
			formatAmount,
			setSelected,
			selected,
			rate,
			selectedRateOptions: getSelectedRateOptions(),
			selectRateOption,
			nextDesign,
		};

		const isSelected =
			selected?.rate?.rateId === rateId ||
			selected?.parent?.rateId === rateId;

		const promoRate = applyPromo( rate.rate, rate.promoId );
		const shipDate = getCurrentShipmentDate()?.shippingDate;

		let deliveryDateMessage;
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
				deliveryDays
			);
		}

		const rateCaveat = [];

		if (
			typeof caveats === 'object' &&
			caveats.includes( 'book-media-only' )
		) {
			if ( carrierId === 'usps' ) {
				rateCaveat.push(
					__(
						'Books and <auspsmedia>other media</auspsmedia> only',
						'woocommerce-shipping'
					)
				);
			} else {
				rateCaveat.push(
					__( 'Books and other media only', 'woocommerce-shipping' )
				);
			}
		}

		if (
			typeof caveats === 'object' &&
			caveats.includes( 'non-refundable' )
		) {
			rateCaveat.push( __( 'Non-refundable', 'woocommerce-shipping' ) );
		}

		if (
			typeof caveats === 'object' &&
			caveats.includes( 'tracking-unavailable' )
		) {
			rateCaveat.push(
				__( 'Tracking is not available', 'woocommerce-shipping' )
			);
		}

		const rateCaveatText =
			rateCaveat.length > 0
				? createInterpolateElement( rateCaveat.join( ', ' ), {
						auspsmedia: (
							// eslint-disable-next-line jsx-a11y/anchor-has-content
							<a
								target="__blank"
								href="https://pe.usps.com/text/DMM300/273.htm#a_3_0"
							/>
						),
				  } )
				: '';

		return nextDesign ? (
			<Card
				style={ {
					borderRadius: '4px',
					border: isSelected
						? '2px solid var(--wpds-color-private-primary-10, #3858E9)'
						: '1px solid var(--wpds-color-private-neutral-fixed-6, #CCC)',
					background: isSelected
						? 'var(--wpds-color-private-primary-2, rgba(56, 88, 233, 0.04))'
						: 'var(--wpds-color-private-neutral-fixed-contrast, #FFF)',
				} }
			>
				<CardBody style={ { padding: 0 } }>
					<input
						type="radio"
						name="shipping-rate"
						id={ rateId }
						onChange={ setSelected( rate ) }
						hidden
					/>
					<Flex
						direction="row"
						align="flex-start"
						gap={ 4 }
						as="label"
						htmlFor={ rateId }
						style={ {
							padding: '24px 16px',
							width: '100%',
							boxSizing: 'border-box',
							cursor: 'pointer',
						} }
						className={ clsx(
							[ isSelected && 'selected' ],
							[ rateCaveatText && 'has-rate-caveat' ]
						) }
					>
						<CarrierIcon
							carrier={ carrierId }
							size={ nextDesign ? 'small' : 'xLarge' }
						/>

						<FlexBlock>
							<Flex direction="column" gap={ 1 }>
								<Flex
									direction="row"
									align="center"
									justify="flex-start"
									gap={ 2 }
								>
									<Text size={ 15 } weight={ 500 }>
										{ title }
									</Text>
									{ isCheapest && (
										<NextBadge intent="informational">
											{ __(
												'Lowest price',
												'woocommerce-shipping'
											) }
										</NextBadge>
									) }
									{ isFastest && (
										<NextBadge intent="informational">
											{ __(
												'Fastest delivery',
												'woocommerce-shipping'
											) }
										</NextBadge>
									) }
								</Flex>
								{ rateCaveatText && (
									<Text
										size={ 13 }
										weight={ 400 }
										variant="muted"
									>
										{ rateCaveatText }
									</Text>
								) }
								{ ! isSelected && extrasText.length > 0 && (
									<Text
										size={ 13 }
										weight={ 400 }
										variant="muted"
									>
										{ sprintf(
											// translators: %s: list of extras
											__(
												'Includes %s',
												'woocommerce-shipping'
											),
											extrasText.join( ', ' )
										) }
									</Text>
								) }

								{ isSelected && (
									<RowExtras { ...extrasProps } />
								) }
							</Flex>
						</FlexBlock>
						<FlexItem>
							<Flex
								direction="column"
								justify="flex-start"
								align="flex-end"
								gap={ 1 }
							>
								{ promoRate !== rate.rate ? (
									<>
										<data
											value={ promoRate }
											aria-label="rate-price"
										>
											<s>{ formatAmount( rate.rate ) }</s>{ ' ' }
											{ formatAmount( promoRate ) }
										</data>
										<Flex gap={ 1 }>
											<Badge intent="success">
												{ __(
													'Promo applied',
													'woocommerce-shipping'
												) }
											</Badge>
											<PromoTooltip rate={ rate.rate } />
										</Flex>
									</>
								) : (
									<Text
										value={ rate.rate }
										size={ 15 }
										weight={ 500 }
									>
										<data
											value={ rate.rate }
											aria-label="rate-price"
										>
											{ formatAmount( rate.rate ) }
										</data>
									</Text>
								) }
								{ deliveryDateMessage && (
									<Text
										size={ 13 }
										weight={ 400 }
										variant="muted"
									>
										<time>{ deliveryDateMessage }</time>
									</Text>
								) }
							</Flex>
						</FlexItem>
					</Flex>
				</CardBody>
			</Card>
		) : (
			<>
				<input
					type="radio"
					name="shipping-rate"
					id={ rateId }
					onChange={ setSelected( rate ) }
				/>
				<Flex
					direction="row"
					align="flex-start"
					gap={ 4 }
					as="label"
					htmlFor={ rateId }
					className={ clsx(
						[ isSelected && 'selected' ],
						[ rateCaveatText && 'has-rate-caveat' ]
					) }
				>
					<CarrierIcon carrier={ carrierId } size="xLarge" />

					<FlexBlock>
						<Flex direction="column" gap={ 2 }>
							<Text size={ 14 } weight={ 400 }>
								{ title }
							</Text>
							{ rateCaveatText && (
								<Text className="rate-caveat">
									{ rateCaveatText }
								</Text>
							) }
							{ ! isSelected && extrasText.length > 0 && (
								<Text className="rate-extras">
									{ sprintf(
										// translators: %s: list of extras
										__(
											'includes %s',
											'woocommerce-shipping'
										),
										extrasText.join( ', ' )
									) }
								</Text>
							) }

							{ isSelected && <RowExtras { ...extrasProps } /> }
						</Flex>
					</FlexBlock>
					<FlexItem>
						<Flex
							direction="column"
							justify="flex-start"
							align="flex-end"
							gap={ 1 }
						>
							{ promoRate !== rate.rate ? (
								<>
									<data
										value={ promoRate }
										aria-label="rate-price"
									>
										<s>{ formatAmount( rate.rate ) }</s>{ ' ' }
										{ formatAmount( promoRate ) }
									</data>
									<Flex gap={ 1 }>
										<Badge intent="success">
											{ __(
												'Promo applied',
												'woocommerce-shipping'
											) }
										</Badge>
										<PromoTooltip rate={ rate.rate } />
									</Flex>
								</>
							) : (
								<data
									value={ rate.rate }
									aria-label="rate-price"
								>
									{ formatAmount( rate.rate ) }
								</data>
							) }
							{ deliveryDateMessage && (
								<time>{ deliveryDateMessage }</time>
							) }
						</Flex>
					</FlexItem>
				</Flex>
			</>
		);
	}
)( 'RateRow' );
