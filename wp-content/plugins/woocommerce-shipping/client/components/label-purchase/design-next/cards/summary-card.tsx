import {
	__experimentalText as Text,
	Card,
	CardHeader,
	CardBody,
	Flex,
} from '@wordpress/components';
import { ShipmentDetails } from 'components/label-purchase/details';
import { Destination, Order } from 'types';
import { __ } from '@wordpress/i18n';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { withBoundaryNext } from 'components/HOC';

const SummaryCardComponent = ( {
	order,
	destinationAddress,
}: {
	order: Order;
	destinationAddress: Destination;
} ) => {
	const {
		rates: { getSelectedRate },
		labels: { hasPurchasedLabel },
	} = useLabelPurchaseContext();
	return (
		! hasPurchasedLabel() && (
			<Card>
				<CardHeader isBorderless>
					<Flex direction={ 'row' } align="space-between">
						<Text weight={ 500 } size={ 15 }>
							{ __( 'Review', 'woocommerce-shipping' ) }
						</Text>
					</Flex>
				</CardHeader>
				<CardBody style={ { paddingTop: '0' } }>
					{ getSelectedRate() ? (
						<ShipmentDetails
							order={ order }
							destinationAddress={ destinationAddress }
						/>
					) : (
						<Flex
							direction="column"
							align="center"
							justify="center"
							gap={ 2 }
							style={ { padding: '8px 16px' } }
						>
							<Text
								variant="muted"
								style={ { width: 360, textAlign: 'center' } }
							>
								{ __(
									"Your shipping details and final costs will appear here once you've configured your package above.",
									'woocommerce-shipping'
								) }
							</Text>
						</Flex>
					) }
				</CardBody>
			</Card>
		)
	);
};

export const SummaryCard = withBoundaryNext( SummaryCardComponent )();
