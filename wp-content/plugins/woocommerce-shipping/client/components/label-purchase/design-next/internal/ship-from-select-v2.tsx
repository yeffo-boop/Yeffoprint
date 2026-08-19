import { __ } from '@wordpress/i18n';
import {
	Button,
	Dropdown,
	Flex,
	MenuItem,
	Modal,
	__experimentalText as Text,
} from '@wordpress/components';
import { Badge } from '@wordpress/ui';
import {
	createInterpolateElement,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { Link } from 'components/wc';
import { check, chevronDown, chevronUp } from '@wordpress/icons';
import { useSelect } from '@wordpress/data';
import {
	addressToString,
	camelCaseKeys,
	formatAddressFields,
	getConfig,
	snakeCaseKeys,
} from 'utils';
import { OriginAddress } from 'types';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { AddressStep } from 'components/address-step';
import { addressStore } from 'data/address';
import { settingsPageUrl } from 'components/label-purchase/constants';
import { SHIPPING_OPERATIONS_PATH } from 'next/data';

interface ShipFromSelectProps {
	disabled: boolean;
}

const AddressPopoverStyle = () => {
	return (
		<>
			<style>{ `
			.address-selector__dropdown .components-popover__content {
				padding: 0 !important;
				width: 100%;
				height: auto;
				max-height: 50vh !important;
				overflow: visible !important;
			}
			.address-selector__dropdown .components-menu-item__button:hover {
				background-color: #f8f8f8 !important;
			}
			.address-selector__dropdown .components-button:focus:not(:disabled) {
				box-shadow: none;
				outline: none;
			}
		` }</style>
		</>
	);
};

const AddressLine = ( {
	origin,
	getShipmentOrigin,
	setShipmentOrigin,
	onClose,
}: {
	origin: OriginAddress;
	getShipmentOrigin: () => OriginAddress | null;
	setShipmentOrigin: ( id: string ) => void;
	onClose: () => void;
} ) => {
	const isSelected = getShipmentOrigin()?.id === origin.id;
	const checkIcon = (
		<div style={ { width: '24px', height: '40px', marginRight: '4px' } }>
			{ check }
		</div>
	);
	const empty = (
		<div
			style={ { width: '24px', height: '40px', marginRight: '4px' } }
		></div>
	);
	return (
		<MenuItem
			key={ origin.id }
			onClick={ () => {
				setShipmentOrigin( origin.id );
				onClose();
			} }
			role="menuitemradio"
			isSelected={ false }
			icon={ isSelected ? checkIcon : empty }
			iconPosition="left"
			style={ {
				padding: '20px 12px',
				height: 'auto',
				borderTop: '1px solid #f0f0f0',
			} }
		>
			<Flex direction="column" align="flex-start" gap={ 0 }>
				<Text size={ 13 } lineHeight={ '20px' }>
					{ origin.company ?? origin.name ?? '' }
				</Text>
				<Text size={ 12 } lineHeight={ '16px' } variant="muted">
					{ addressToString( origin ) }
				</Text>
			</Flex>
			{ ! origin.isVerified && (
				<span
					style={ {
						marginBottom: '-16px',
						marginLeft: '4px',
						display: 'block',
					} }
				>
					<Badge intent="low">
						{ __( 'Not validated', 'woocommerce-shipping' ) }
					</Badge>
				</span>
			) }
		</MenuItem>
	);
};

/**
 * TODO: Replace with the final Select control component when ready
 * TODO: on Design System when it's ready, and exposed to other plugins.
 */
export const ShipFromSelectV2 = ( { disabled }: ShipFromSelectProps ) => {
	const origins = useSelect(
		( select ) => select( addressStore ).getOriginAddresses(),
		[]
	);
	const {
		shipment: { getShipmentOrigin, setShipmentOrigin },
		rates: { updateRates },
	} = useLabelPurchaseContext();
	const prevOrigin = useRef( getShipmentOrigin() );

	useEffect( () => {
		if ( prevOrigin.current?.id !== getShipmentOrigin()?.id ) {
			updateRates();
		}
		prevOrigin.current = getShipmentOrigin();
	}, [ getShipmentOrigin, updateRates, prevOrigin ] );

	const [ addressForEdit, openAddressForEdit ] = useState<
		OriginAddress | false
	>( false );

	const onCompleteCallback = useCallback(
		( address: OriginAddress ) => {
			setShipmentOrigin( address.id );
			openAddressForEdit( false );
			updateRates();
		},
		[ setShipmentOrigin, updateRates ]
	);

	if ( origins.length < 1 ) {
		return createInterpolateElement(
			__(
				'You have no verified origin address, <a>visit settings</a> to add one',
				'woocommerce-shipping'
			),
			{
				a: (
					<Link href={ settingsPageUrl } type="wp-admin">
						{ __( 'visit settings', 'woocommerce-shipping' ) }
					</Link>
				),
			}
		);
	}

	const noValidAddress = origins.every( ( address ) => ! address.isVerified );

	return (
		<>
			<AddressPopoverStyle />
			<Flex
				direction="column"
				gap={ 0 }
				justify="flex-start"
				align="flex-start"
			>
				<Dropdown
					popoverProps={ {
						placement: 'bottom-start',
						resize: true,
						inline: true,
						noArrow: true,
						style: { width: 'calc(100% - 48px' },
					} }
					className="address-selector__dropdown"
					style={ { width: '100%', padding: 0 } }
					disabled={ disabled || noValidAddress }
					renderToggle={ ( { isOpen, onToggle } ) => {
						return (
							<Button
								className="shipping-rates__sort"
								onClick={ onToggle }
								aria-expanded={ isOpen }
								icon={ isOpen ? chevronUp : chevronDown }
								iconPosition="right"
								style={ {
									width: '100%',
									justifyContent: 'space-between',
									boxShadow: isOpen
										? '0 0 0 1px inset #000'
										: '0 0 0 1px inset #949494',
									color: isOpen ? '#000' : '#555',
									paddingRight: '4px',
								} }
							>
								<Flex direction="row" align="center" gap={ 4 }>
									<Text size={ 13 } lineHeight={ '20px' }>
										{ getShipmentOrigin()
											? getShipmentOrigin().company ??
											  getShipmentOrigin().name
											: __(
													'Choose a ship from address',
													'woocommerce-shipping'
											  ) }
									</Text>
									{ ! getShipmentOrigin()?.isVerified && (
										<Badge intent="low">
											{ __(
												'Not validated',
												'woocommerce-shipping'
											) }
										</Badge>
									) }
								</Flex>
							</Button>
						);
					} }
					renderContent={ ( { onClose } ) => {
						return (
							<>
								{ Object.keys( origins ).length > 0 &&
									origins.map( ( origin: OriginAddress ) => (
										<AddressLine
											key={ origin.id }
											origin={ origin }
											getShipmentOrigin={
												getShipmentOrigin
											}
											setShipmentOrigin={
												setShipmentOrigin
											}
											onClose={ onClose }
										/>
									) ) }
								<Flex
									justify="flex-end"
									align="center"
									style={ {
										padding: '8px 16px',
										borderTop: '1px solid #dddddd',
										marginTop: '32px',
									} }
								>
									<Button
										variant="link"
										onClick={ () => {
											onClose();
											if (
												window.WCShipping_Config
													?.navigate
											) {
												window.WCShipping_Config.navigate(
													{
														to: SHIPPING_OPERATIONS_PATH,
													}
												);
											}
										} }
										style={ {
											textDecoration: 'none',
											color: '#3858E9',
											lineHeight: '24px',
											fontSize: '13px',
										} }
									>
										{ __(
											'Manage Address',
											'woocommerce-shipping'
										) }
									</Button>
								</Flex>
							</>
						);
					} }
				/>
			</Flex>
			{ addressForEdit && (
				<Modal
					className="edit-address-modal"
					onRequestClose={ () => openAddressForEdit( false ) }
					focusOnMount
					shouldCloseOnClickOutside={ false }
					title={ __( 'Edit address', 'woocommerce-shipping' ) }
				>
					<AddressStep
						type={ 'origin' }
						address={ camelCaseKeys(
							formatAddressFields(
								snakeCaseKeys( addressForEdit )
							)
						) }
						onCompleteCallback={ onCompleteCallback }
						onCancelCallback={ () => openAddressForEdit( false ) }
						orderId={ `${ getConfig().order.id }` }
						isAdd={ false }
					/>
				</Modal>
			) }
		</>
	);
};
