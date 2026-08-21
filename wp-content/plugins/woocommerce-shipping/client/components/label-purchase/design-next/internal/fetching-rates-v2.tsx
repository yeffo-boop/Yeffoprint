import { __ } from '@wordpress/i18n';
import { Flex } from '@wordpress/components';
import clsx from 'clsx';
import { FetchingRatesIcon } from './fetching-rates-icon';

export const FetchingRatesV2 = ( {
	className,
}: {
	className: string | undefined;
} ) => (
	<Flex
		className={ clsx( 'label-purchase-rates__placeholder', className ) }
		justify="center"
		align="center"
		direction="column"
	>
		<FetchingRatesIcon />
		<p>
			{ __(
				'Checking available shipping rates. Usually just a moment.',
				'woocommerce-shipping'
			) }
		</p>
	</Flex>
);
