import React from 'react';
import { __experimentalText as Text, Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

interface SelectionHeaderProps {
	selectionsCount: number;
	selectablesCount: number;
	selectAll: ( isSelected: boolean ) => void;
	isDisabled: boolean;
}

export const SelectionHeader = ( {
	selectionsCount,
	selectablesCount,
	selectAll,
	isDisabled,
}: SelectionHeaderProps ) => (
	<section>
		{ selectionsCount > 0 && (
			<Text>
				{ selectionsCount } { __( 'selected', 'woocommerce-shipping' ) }
			</Text>
		) }
		{ selectionsCount < selectablesCount && (
			<Button
				variant="tertiary"
				onClick={ () => selectAll( true ) }
				disabled={ isDisabled }
				aria-disabled={ isDisabled }
			>
				{ sprintf(
					// translators: %d: number of items
					__( 'Select all (%d)', 'woocommerce-shipping' ),
					selectablesCount
				) }
			</Button>
		) }
		{ selectionsCount > 0 && (
			<Button
				variant="tertiary"
				onClick={ () => selectAll( false ) }
				disabled={ isDisabled }
				aria-disabled={ isDisabled }
			>
				{ __( 'Clear selection', 'woocommerce-shipping' ) }
			</Button>
		) }
	</section>
);
