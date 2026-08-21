import React from 'react';
import {
	__experimentalHeading as Heading,
	__experimentalSpacer as Spacer,
	__experimentalText as Text,
	Button,
	Card,
	CardBody,
	Flex,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export const LiveRatesSettings = () => {
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
					{ __( 'Live rates', 'woocommerce-shipping' ) }
				</Heading>
				<Text>
					{ __(
						'Configure the packages that shipping should be calculated for when offering shipping rates on the cart and checkout pages.',
						'woocommerce-shipping'
					) }
				</Text>
			</Flex>
			<Flex direction="column">
				<Card className="wcshipping-settings__card" size="large">
					<CardBody>
						<Button
							variant="secondary"
							href="admin.php?page=wc-settings&tab=shipping&section=woocommerce-services-settings"
						>
							{ __( 'Select packages', 'woocommerce-shipping' ) }
						</Button>
					</CardBody>
				</Card>
			</Flex>
		</Flex>
	);
};
