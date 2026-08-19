import { Flex, Icon, Notice } from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { error as warningIcon } from '@wordpress/icons';

interface AddressGroupingSuggestionProps {
	customerName: string;
	cityState: string;
	orderIds: number[];
	savings: number;
}

/**
 * "X orders ship to the same address. Combine into one package?"
 * suggestion. Wired to local state for now: the buttons just dismiss
 * the banner. Real grouping lives downstream.
 */
export const AddressGroupingSuggestion = ( {
	customerName,
	cityState,
	orderIds,
	savings,
}: AddressGroupingSuggestionProps ) => {
	const [ dismissed, setDismissed ] = useState( false );
	if ( dismissed ) {
		return null;
	}

	const dismiss = () => setDismissed( true );

	return (
		<Notice
			status="info"
			isDismissible={ false }
			actions={ [
				{
					label: __( 'Keep separate', 'woocommerce-shipping' ),
					onClick: dismiss,
					variant: 'secondary',
				},
				{
					label: __( 'Combine into 1 label', 'woocommerce-shipping' ),
					onClick: dismiss,
					variant: 'primary',
				},
			] }
		>
			<strong>
				{ sprintf(
					/* translators: %d: number of orders shipping to the same address */
					_n(
						'%d order ships to the same address. Combine into one package?',
						'%d orders ship to the same address. Combine into one package?',
						orderIds.length,
						'woocommerce-shipping'
					),
					orderIds.length
				) }
			</strong>
			<div>
				{ sprintf(
					/* translators: 1: list of order numbers, 2: customer name, 3: city, state, 4: number of orders, 5: dollar savings */
					__(
						'Orders %1$s all ship to %2$s, %3$s. One label instead of %4$d saves %5$s.',
						'woocommerce-shipping'
					),
					orderIds.map( ( id ) => `#${ id }` ).join( ', ' ),
					customerName,
					cityState,
					orderIds.length,
					`$${ savings.toFixed( 2 ) }`
				) }
			</div>
		</Notice>
	);
};

interface NeedsFixNoticeProps {
	needsFixCount: number;
	readyCount: number;
	onShowOnlyIssues: () => void;
}

/**
 * "X orders need a quick fix" warning + Show only issues button.
 */
export const NeedsFixNotice = ( {
	needsFixCount,
	readyCount,
	onShowOnlyIssues,
}: NeedsFixNoticeProps ) => {
	if ( needsFixCount === 0 ) {
		return null;
	}

	return (
		<Notice
			status="warning"
			isDismissible={ false }
			actions={ [
				{
					label: __( 'Show only issues', 'woocommerce-shipping' ),
					onClick: onShowOnlyIssues,
					variant: 'secondary',
				},
			] }
		>
			<Flex gap={ 2 } justify="flex-start">
				<Icon icon={ warningIcon } size={ 16 } />
				<strong>
					{ sprintf(
						/* translators: %d: number of orders needing attention */
						_n(
							'%d order needs a quick fix',
							'%d orders need a quick fix',
							needsFixCount,
							'woocommerce-shipping'
						),
						needsFixCount
					) }
				</strong>{ ' ' }
				{ readyCount > 0
					? sprintf(
							/* translators: %d: number of ready orders */
							_n(
								'before we can label them. You can still purchase the %d ready order and come back.',
								'before we can label them. You can still purchase the %d ready orders and come back.',
								readyCount,
								'woocommerce-shipping'
							),
							readyCount
					  )
					: __(
							'before we can label them.',
							'woocommerce-shipping'
					  ) }
			</Flex>
		</Notice>
	);
};
