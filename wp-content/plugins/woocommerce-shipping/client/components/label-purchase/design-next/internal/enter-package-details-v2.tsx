import { __ } from '@wordpress/i18n';
import { Flex } from '@wordpress/components';
import clsx from 'clsx';
import { NoRatesAvailableIcon } from './no-rates-available-icon';

export const EnterPackageDetailsV2 = ( {
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
		<NoRatesAvailableIcon />
		<p>
			{ __(
				'Add package details to see available shipping rates.',
				'woocommerce-shipping'
			) }
		</p>
	</Flex>
);
