import { __ } from '@wordpress/i18n';
import { Flex } from '@wordpress/components';
import clsx from 'clsx';
import { NoRatesIcon } from './icons/no-rates';

export const NoRatesAvailable = ( { className } ) => (
	<Flex
		className={ clsx( 'label-purchase-rates__placeholder', className ) }
		justify="center"
		align="center"
		direction="column"
	>
		<NoRatesIcon />
		<p>
			{ __(
				'Add or select your package to get started.',
				'woocommerce-shipping'
			) }
		</p>
	</Flex>
);
