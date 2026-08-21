import {
	Button,
	Dropdown,
	MenuItem,
	__experimentalText as Text,
} from '@wordpress/components';
import { check, chevronDown, chevronUp } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { SORT_BY } from './constants';

export const RatesSorter = ( {
	setSortBy,
	sortingBy,
	canSortByDelivery,
	nextDesign = false,
} ) =>
	nextDesign ? (
		<Dropdown
			popoverProps={ {
				placement: 'bottom-start',
				resize: true,
				shift: false,
				inline: true,
				noArrow: true,
			} }
			renderToggle={ ( { isOpen, onToggle } ) => {
				return (
					<Button
						variant="tertiary"
						onClick={ onToggle }
						aria-expanded={ isOpen }
						icon={ isOpen ? chevronUp : chevronDown }
						iconPosition="right"
						style={ {
							boxShadow: isOpen
								? '0 0 0 1px inset #000'
								: '0 0 0 1px inset #949494',
							color: isOpen ? '#000' : '#555',
							paddingRight: '4px',
						} }
					>
						<Text variant="muted">
							{ __( 'Sort by', 'woocommerce-shipping' ) }
						</Text>
						<Text>
							{ sortingBy === SORT_BY.CHEAPEST
								? __( 'Low to high', 'woocommerce-shipping' )
								: __( 'Fastest', 'woocommerce-shipping' ) }
						</Text>
					</Button>
				);
			} }
			renderContent={ () => (
				<>
					<MenuItem
						onClick={ () => {
							setSortBy( SORT_BY.CHEAPEST );
						} }
						isSelected={ sortingBy === SORT_BY.CHEAPEST }
						icon={
							sortingBy === SORT_BY.CHEAPEST ? check : undefined
						}
						iconPosition={ 'right' }
						role="menuitemradio"
					>
						{ __( 'Low to high', 'woocommerce-shipping' ) }
					</MenuItem>
					{ canSortByDelivery && (
						<MenuItem
							onClick={ () => {
								setSortBy( SORT_BY.FASTEST );
							} }
							isSelected={ sortingBy === SORT_BY.FASTEST }
							icon={
								sortingBy === SORT_BY.FASTEST
									? check
									: undefined
							}
							iconPosition={ 'right' }
							role="menuitemradio"
						>
							{ __( 'Fastest', 'woocommerce-shipping' ) }
						</MenuItem>
					) }
				</>
			) }
		/>
	) : (
		<Dropdown
			popoverProps={ {
				placement: 'bottom-end',
				resize: true,
				shift: true,
				inline: true,
			} }
			renderToggle={ ( { isOpen, onToggle } ) => {
				return (
					<Button
						isTertiary
						className="shipping-rates__sort"
						onClick={ onToggle }
						aria-expanded={ isOpen }
						icon={ isOpen ? chevronUp : chevronDown }
					>
						{ __( 'Sort by', 'woocommerce-shipping' ) }
					</Button>
				);
			} }
			renderContent={ ( { onClose } ) => (
				<>
					<MenuItem
						onClick={ () => {
							setSortBy( SORT_BY.CHEAPEST );
							onClose();
						} }
						role="menuitemradio"
						isSelected={ sortingBy === SORT_BY.CHEAPEST }
					>
						{ __( 'Cheapest', 'woocommerce-shipping' ) }
					</MenuItem>

					{ canSortByDelivery && (
						<MenuItem
							onClick={ () => {
								setSortBy( SORT_BY.FASTEST );
								onClose();
							} }
							role="menuitemradio"
							isSelected={ sortingBy === SORT_BY.FASTEST }
						>
							{ __( 'Fastest', 'woocommerce-shipping' ) }
						</MenuItem>
					) }
				</>
			) }
		/>
	);
