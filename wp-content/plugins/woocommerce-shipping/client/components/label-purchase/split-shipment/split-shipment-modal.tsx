import {
	__experimentalConfirmDialog as ConfirmDialog,
	__experimentalSpacer as Spacer,
	Button,
	Flex,
	FlexBlock,
	FlexItem,
	Modal,
	Notice,
} from '@wordpress/components';
import { usePrevious } from '@wordpress/compose';
import { __, sprintf } from '@wordpress/i18n';
import {
	createInterpolateElement,
	forwardRef,
	useCallback,
	useState,
	useEffect,
} from '@wordpress/element';
import { dispatch } from '@wordpress/data';
import {
	getCurrentOrder,
	getNoneSelectedShipmentItems,
	getSelectablesCount,
	getSubItems,
	hasSubItems,
	normalizeShipments,
} from 'utils';
import { SelectableItems } from './selectable-items';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { StaticHeader } from './header';
import { MoveTo } from './move-to';
import { SHOW_SPLIT_SHIPMENT_NOTICE } from './constants';
import { cloneDeep } from 'lodash';
import { labelPurchaseStore } from 'data/label-purchase';
import { recordEvent } from 'utils/tracks';
import { ShipmentItem, ShipmentSubItem } from 'types';

interface SplitShipmentModalProps {
	setStartSplitShipment: ( startSplitShipment: boolean ) => void;
}

export const SplitShipmentModal = forwardRef<
	HTMLBaseElement,
	SplitShipmentModalProps
>( ( { setStartSplitShipment }, ref ) => {
	const [ showCreationNotice, setCreationNotice ] = useState(
		! [ 'false', false ].includes(
			localStorage.getItem( SHOW_SPLIT_SHIPMENT_NOTICE ) ?? false
		)
	);
	const [ updateError, setUpdateError ] = useState< unknown | boolean >(
		false
	);

	const [ isLoading, setIsLoading ] = useState( false );
	const [ confirmClose, setConfirmClose ] = useState( false );

	const {
		shipment: {
			shipments,
			setShipments,
			selections,
			setSelection,
			resetSelections,
			currentShipmentId,
			revertLabelShipmentIdsToUpdate,
			labelShipmentIdsToUpdate,
			hasVariations,
			hasMultipleShipments,
			isReturnShipment,
		},
		labels: { hasPurchasedLabel },
		customs: { updateCustomsItems },
	} = useLabelPurchaseContext();

	const selectPreviousTab = () => {
		if ( ref && 'current' in ref && ref.current ) {
			const previousTab = ref.current.querySelector< HTMLButtonElement >(
				`.shipment-tab-${ currentShipmentId }`
			);
			previousTab?.click();
		}
	};

	const closeOrCancelShipmentEdit = () => {
		selectPreviousTab();
		setStartSplitShipment( false );
	};

	const previousShipmentsState = usePrevious( shipments );

	const selectedItemsAndSubitems = () => {
		const firstSelection = Object.values( selections )[ 0 ] ?? [];
		return firstSelection.flatMap( ( selection ) => selection.subItems );
	};
	/**
	 * Store the initial state of shipments to enable resetting the state when
	 * the modal is closed.
	 */
	// eslint-disable-next-line react-hooks/exhaustive-deps
	const getInitialState = useCallback( () => cloneDeep( shipments ), [] );

	const createShipment = () => {
		const oldShipments = getNoneSelectedShipmentItems(
			shipments,
			selections
		);

		const newShipment = Object.values( selections ).flat();

		const newShipments = {
			...oldShipments,
			[ Object.keys( oldShipments ).length ]: newShipment,
		};
		const { normalizedShipments } = normalizeShipments( newShipments );
		setShipments( normalizedShipments );

		resetSelections( Object.keys( normalizedShipments ) );
	};

	const addSelectionForShipment =
		( index: string | number ) =>
		( selection: ShipmentItem[] | ShipmentSubItem[] ) => {
			setSelection( { ...selections, [ index ]: selection } );
		};

	const shouldDisableCreateShipmentButton = () => {
		const orderItems = Object.values( shipments ).flat();
		const selectablesCount = orderItems.reduce( ( acc, item ) => {
			return acc + item.quantity;
		}, 0 );
		const selectedCount = Object.values( selections ).flat().length;
		return selectedCount === 0 || selectedCount === selectablesCount;
	};

	const selectAll = ( index: number | string ) => ( add: boolean ) => {
		if ( add ) {
			setSelection( {
				...selections,
				[ index ]: shipments[ index ]
					.map( ( item ) =>
						hasSubItems( item ) ? getSubItems( item ) : item
					)
					.flat() as ShipmentItem[],
			} );
		} else {
			setSelection( {
				[ currentShipmentId ]: [],
			} );
		}
	};

	const save = async () => {
		setIsLoading( true );
		setUpdateError( false );
		const simplifiedShipments = Object.entries( shipments ).reduce(
			( acc, [ key, shipment ] ) => ( {
				...acc,
				[ key ]: shipment.map( ( { id, subItems } ) => ( {
					id,
					subItems: subItems.map(
						( { id: subItemId } ) => subItemId
					),
				} ) ),
			} ),
			{}
		);

		const result = await dispatch( labelPurchaseStore ).updateShipments( {
			shipments: simplifiedShipments,
			orderId: `${ getCurrentOrder()?.id }`,
			shipmentIdsToUpdate: labelShipmentIdsToUpdate,
		} );

		setIsLoading( false );

		if ( 'error' in result ) {
			// @ts-ignore
			setUpdateError( result?.error?.message );
		} else {
			closeOrCancelShipmentEdit();
		}

		updateCustomsItems();
	};

	const shouldClose = () => {
		if ( previousShipmentsState ) {
			setConfirmClose( true );
		} else {
			recordEvent( 'split_shipment_modal_closed' );
			closeOrCancelShipmentEdit();
		}
	};
	const dismissNotice = () => {
		setCreationNotice( false );
		localStorage.setItem( SHOW_SPLIT_SHIPMENT_NOTICE, 'false' );
		recordEvent( 'split_shipment_modal_notice_dismissed' );
	};

	// Reset selections when modal opens
	// we need to do this because items can have been selected for "extra labels"
	// and we don't want to carry over those extra labels to the split shipment items table.
	useEffect( () => {
		resetSelections( Object.keys( shipments ) );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	return (
		<Modal
			title={ __( 'Split Shipment', 'woocommerce-shipping' ) }
			overlayClassName="split-shipment-modal-overlay"
			className="split-shipment-modal"
			onRequestClose={ shouldClose }
			focusOnMount
			shouldCloseOnClickOutside={ false }
		>
			{ showCreationNotice && (
				<Notice status="info" onDismiss={ dismissNotice }>
					{ createInterpolateElement(
						__(
							'To create a split shipment, please select the respective products and click on <strong>create new shipment.</strong>',
							'woocommerce-shipping'
						),
						{
							strong: <strong />,
						}
					) }
				</Notice>
			) }
			{ showCreationNotice && updateError !== false && (
				<Spacer marginTop={ 2 } />
			) }
			{ updateError !== false && (
				<Notice status="error">
					{ sprintf(
						// translators: %s: error message
						__(
							'There was an error while updating the shipments. %s',
							'woocommerce-shipping'
						),
						String( updateError )
					) }
				</Notice>
			) }

			<Flex className="selectable-items__header" direction="column">
				<Flex
					align="flex-start"
					justify="flex-end"
					className="split-shipment-actions"
				>
					{ hasMultipleShipments && (
						<MoveTo
							isDisabled={ shouldDisableCreateShipmentButton }
						/>
					) }
					<Button
						variant="secondary"
						onClick={ createShipment }
						disabled={ shouldDisableCreateShipmentButton() }
					>
						{ __( 'Create new shipment', 'woocommerce-shipping' ) }
					</Button>
				</Flex>
				<StaticHeader
					hasVariations={ hasVariations }
					selectAll={ selectAll( '0' ) }
					hasMultipleShipments={ hasMultipleShipments }
					selections={ selectedItemsAndSubitems() }
					selectablesCount={ getSelectablesCount(
						Object.values( shipments )[ 0 ]
					) }
				/>
			</Flex>
			<Flex
				className="label-purchase-list-items is-selectable"
				direction="column"
				expanded={ true }
			>
				{ Object.entries( shipments ).map( ( [ key, shipment ] ) => {
					const index = parseInt( key, 10 );
					const hasShipmentPurchasedLabel = hasPurchasedLabel(
						true,
						true,
						key
					);

					return (
						<SelectableItems
							key={ key }
							isSplit={ Object.values( shipments ).length > 1 }
							select={ addSelectionForShipment( index ) }
							selections={ selections[ index ] || [] }
							orderItems={ shipment }
							selectAll={ selectAll( key ) }
							shipmentIndex={ index }
							isDisabled={
								hasShipmentPurchasedLabel ||
								isReturnShipment( key )
							}
						/>
					);
				} ) }
			</Flex>
			<Flex as="footer">
				<FlexBlock></FlexBlock>
				<FlexItem>
					<Button variant="tertiary" onClick={ shouldClose }>
						{ __( 'Cancel', 'woocommerce-shipping' ) }
					</Button>
					<Button
						variant="primary"
						onClick={ save }
						isBusy={ isLoading }
						disabled={ ! previousShipmentsState }
					>
						{ __( 'Save', 'woocommerce-shipping' ) }
					</Button>
				</FlexItem>
			</Flex>
			<ConfirmDialog
				isOpen={ confirmClose }
				onConfirm={ () => {
					setShipments( getInitialState() );
					revertLabelShipmentIdsToUpdate();
					recordEvent( 'split_shipment_modal_close_confirm_clicked' );
					closeOrCancelShipmentEdit();
				} }
				onCancel={ () => {
					recordEvent( 'split_shipment_modal_close_cancel_clicked' );
					setConfirmClose( false );
				} }
				confirmButtonText={ __(
					'Close and revert the changes',
					'woocommerce-shipping'
				) }
				cancelButtonText={ __(
					'Continue editing the shipments',
					'woocommerce-shipping'
				) }
			>
				<h3>
					{ __(
						'There are unsaved changes',
						'woocommerce-shipping'
					) }
				</h3>
				{ __(
					'Are you sure you want to close the split shipment modal?',
					'woocommerce-shipping'
				) }
			</ConfirmDialog>
		</Modal>
	);
} );
