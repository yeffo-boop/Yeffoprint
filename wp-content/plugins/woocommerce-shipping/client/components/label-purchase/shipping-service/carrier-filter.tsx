import {
	Button,
	CheckboxControl,
	Dropdown,
	__experimentalSpacer as Spacer,
	__experimentalDivider as Divider,
} from '@wordpress/components';
import { chevronDown, chevronUp } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Carrier } from 'types';
import { CarrierIcon } from 'components/carrier-icon';

interface CarrierFilterProps {
	carriers: {
		id: Carrier;
		label: string;
	}[];
	selectedCarriers: Carrier[];
	filterToCarriers: ( carriers: Carrier[] ) => void;
	nextDesign?: boolean;
}

export const CarrierFilter = ( {
	carriers,
	selectedCarriers,
	filterToCarriers,
	nextDesign = false,
}: CarrierFilterProps ) => {
	const [ selections, setSelections ] = useState( selectedCarriers );
	const toggle = ( carrier: Carrier ) => ( select: boolean ) => {
		const newSelections = select
			? [ ...selections, carrier ]
			: selections.filter( ( c ) => c !== carrier );
		setSelections( newSelections );
		if ( nextDesign ) {
			filterToCarriers( newSelections );
		}
	};

	const areAllSelected = () => selections.length === carriers.length;
	const toggleAll = ( select: boolean ) => {
		const newSelections = select
			? carriers.map( ( carrier ) => carrier.id )
			: [];
		setSelections( newSelections );
		filterToCarriers( newSelections );
	};

	return nextDesign ? (
		<Dropdown
			popoverProps={ {
				placement: 'bottom-start',
				resize: true,
				shift: false,
				inline: true,
				noArrow: true,
			} }
			renderToggle={ ( { isOpen, onToggle } ) => (
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
					{ selections.length === 0 ||
					selections.length === carriers.length
						? __( 'All carriers', 'woocommerce-shipping' )
						: selections
								.map(
									( carrierId ) =>
										carriers.find(
											( carrier ) =>
												carrier.id === carrierId
										)?.label
								)
								.join( ', ' ) }
				</Button>
			) }
			renderContent={ () => (
				<>
					<Spacer
						paddingX={ 3 }
						paddingY={ 2 }
						style={ { minWidth: '132px' } }
					>
						<CheckboxControl
							id="all-carriers"
							onChange={ toggleAll }
							checked={ areAllSelected() }
							label={ __(
								'All carriers',
								'woocommerce-shipping'
							) }
							__nextHasNoMarginBottom={ true }
						/>
					</Spacer>
					<Spacer paddingY={ 2 }>
						<Divider style={ { color: '#f0f0f0' } } />
					</Spacer>
					{ carriers.map( ( { id, label } ) => (
						<Spacer
							key={ id }
							paddingX={ 3 }
							paddingY={ 2 }
							style={ { minWidth: '132px' } }
						>
							<CheckboxControl
								id={ id }
								onChange={ toggle( id ) }
								checked={ selections.includes( id ) }
								// @ts-ignore
								label={ label }
								__nextHasNoMarginBottom={ true }
							/>
						</Spacer>
					) ) }
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
				noArrow: false,
			} }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					variant="tertiary"
					className="shipping-filter_carrier"
					onClick={ onToggle }
					aria-expanded={ isOpen }
					icon={ isOpen ? chevronUp : chevronDown }
				>
					{ __( 'Carriers', 'woocommerce-shipping' ) }
				</Button>
			) }
			renderContent={ ( { onClose } ) => (
				<>
					{ carriers.map( ( { id, label } ) => (
						<CheckboxControl
							key={ id }
							id={ id }
							onChange={ toggle( id ) }
							checked={ selections.includes( id ) }
							// @ts-ignore
							label={
								<>
									<CarrierIcon
										carrier={ id }
										key={ id }
										size="small"
										positionX={ 'center' }
										positionY={ 'center' }
									/>
									{ label }
								</>
							}
							__nextHasNoMarginBottom={ true }
						/>
					) ) }
					<Button
						variant="secondary"
						onClick={ () => {
							filterToCarriers( selections );
							onClose();
						} }
					>
						{ __( 'Apply', 'woocommerce-shipping' ) }
					</Button>
				</>
			) }
		/>
	);
};
