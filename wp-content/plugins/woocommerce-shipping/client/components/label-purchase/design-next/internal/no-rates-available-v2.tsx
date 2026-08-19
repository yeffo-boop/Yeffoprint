import { __ } from '@wordpress/i18n';
import { Flex, Icon, __experimentalText as Text } from '@wordpress/components';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { curvedInfo } from 'components/icons';

export const NoRatesAvailableV2 = ( {
	className,
}: {
	className: string | undefined;
} ) => {
	const {
		hazmat: { getShipmentHazmat },
		packages: { isCustomPackageTab },
	} = useLabelPurchaseContext();
	return (
		<Flex
			direction="column"
			align="center"
			justify="center"
			gap={ 4 }
			className={ className }
		>
			<Icon icon={ curvedInfo } size={ 64 } />

			<Text
				align="center"
				variant="muted"
				style={ { maxWidth: '450px' } }
			>
				{ getShipmentHazmat().isHazmat && (
					<>
						{ __(
							'No rates available for this HAZMAT shipment. Check your shipment details and try again.',
							'woocommerce-shipping'
						) }
					</>
				) }
				{ ! getShipmentHazmat().isHazmat && (
					<>
						{ isCustomPackageTab()
							? __(
									'No rates available for this package. Check the details, weight, and destination.',
									'woocommerce-shipping'
							  )
							: __(
									'No rates available for this package. Check the weight, destination, or try another.',
									'woocommerce-shipping'
							  ) }
					</>
				) }
			</Text>
		</Flex>
	);
};
