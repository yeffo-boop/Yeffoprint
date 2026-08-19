import { Button, MenuGroup, MenuItem, Dropdown } from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { chevronDown } from '@wordpress/icons';
import { useLabelPurchaseContext } from 'context/label-purchase';
import { useCallback, forwardRef, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { PaperSize } from 'types';
import { recordEvent } from 'utils';
import { persistPaperSize } from 'utils/label/persist-paper-size';

export const PrintLabelButton = forwardRef( ( _props, ref ) => {
	const {
		labels: {
			selectedLabelSize,
			paperSizes,
			printLabel,
			isPurchasing,
			isUpdatingStatus,
			isPrinting,
		},
	} = useLabelPurchaseContext();

	const [ labelSize, setLabelSize ] =
		useState< PaperSize >( selectedLabelSize );
	const hasNotifiedPersistFailureRef = useRef( false );

	// `printLabel` rejects with a plain `{ cause, message }` payload on error,
	// not an Error instance, so this helper is shared by both the Print button
	// and the size-picker dropdown to surface a notice and stop the failure
	// from bubbling as an unhandled rejection. Accepts both `string` and
	// `string[]` shapes so a future caller that resolves with a single
	// message string (or any code that hands us an `Error` instance with a
	// plain `.message`) still produces a useful notice instead of the
	// generic fallback.
	const notifyPrintFailure = useCallback( ( e: unknown ) => {
		const rawMessage = ( e as { message?: unknown } )?.message;
		let firstMessage: string | undefined;
		if ( Array.isArray( rawMessage ) ) {
			firstMessage = rawMessage.find(
				( line ): line is string =>
					typeof line === 'string' && line.length > 0
			);
		} else if ( typeof rawMessage === 'string' && rawMessage.length > 0 ) {
			firstMessage = rawMessage;
		}
		const fallback = __(
			'Error printing label, try to print later.',
			'woocommerce-shipping'
		);
		(
			dispatch( 'core/notices' ) as {
				createErrorNotice: (
					message: string,
					options?: { isDismissible?: boolean }
				) => void;
			}
		 ).createErrorNotice( firstMessage ?? fallback, {
			isDismissible: true,
		} );
	}, [] );

	const onPrintClick = async () => {
		const tracksProperties = {
			selected_label_size: labelSize.key,
			default_label_size: selectedLabelSize.key,
		};
		recordEvent( 'label_print_button_clicked', tracksProperties );
		try {
			await printLabel( true, labelSize );
		} catch ( e ) {
			notifyPrintFailure( e );
		}
	};

	const handleSizeSelect = useCallback(
		async ( size: PaperSize, onClose: () => void ) => {
			const tracksProperties = {
				selected_label_size: size.key,
				default_label_size: selectedLabelSize.key,
			};
			recordEvent(
				'label_print_size_dropdown_selected',
				tracksProperties
			);
			setLabelSize( size );
			try {
				await persistPaperSize( size.key );
			} catch ( e ) {
				// Don't block the print on a persist failure; the user has
				// already paid and just wants the PDF. Log so we surface
				// drift in Sentry without halting the flow.
				// eslint-disable-next-line no-console
				console.warn( '[wcshipping] Failed to persist paper size', e );
				if ( ! hasNotifiedPersistFailureRef.current ) {
					hasNotifiedPersistFailureRef.current = true;
					(
						dispatch( 'core/notices' ) as {
							createWarningNotice: (
								message: string,
								options?: { isDismissible?: boolean }
							) => void;
						}
					 ).createWarningNotice(
						__(
							"We couldn't save your paper-size preference. Your next print will still use the size you picked.",
							'woocommerce-shipping'
						),
						{ isDismissible: true }
					);
				}
			}
			try {
				await printLabel( true, size );
				// Only close the dropdown on success so the merchant can
				// retry with another size if the print failed.
				onClose();
			} catch ( e ) {
				notifyPrintFailure( e );
			}
		},
		[ printLabel, selectedLabelSize, notifyPrintFailure ]
	);

	const handleChevronClick = useCallback( ( onToggle: () => void ) => {
		recordEvent( 'label_print_size_dropdown_clicked' );
		onToggle();
	}, [] );

	return (
		<Dropdown
			ref={ ref }
			className="print-label-button"
			popoverProps={ {
				placement: 'bottom-end',
				noArrow: true,
				resize: true,
				shift: true,
				inline: false,
			} }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<div style={ { display: 'flex' } }>
					<Button
						onClick={ onPrintClick }
						isBusy={ isPrinting }
						disabled={
							isPurchasing || isUpdatingStatus || isPrinting
						}
						variant="primary"
						className="print-label-button__primary"
						style={ {
							borderTopRightRadius: 0,
							borderBottomRightRadius: 0,
							borderRight: '1px solid rgba(255, 255, 255, 0.4)',
						} }
					>
						Print label ({ labelSize.size })
					</Button>
					<Button
						disabled={
							isPurchasing || isUpdatingStatus || isPrinting
						}
						onClick={ () => handleChevronClick( onToggle ) }
						icon={ chevronDown }
						variant="primary"
						aria-expanded={ isOpen }
						aria-label={ __(
							'Select label size',
							'woocommerce-shipping'
						) }
						className="print-label-button__toggle"
						style={ {
							borderTopLeftRadius: 0,
							borderBottomLeftRadius: 0,
							padding: '0 6px',
						} }
					/>
				</div>
			) }
			renderContent={ ( { onClose } ) => (
				<MenuGroup
					label={ __( 'Select label size', 'woocommerce-shipping' ) }
				>
					{ paperSizes.map( ( size ) => (
						<MenuItem
							key={ size.key }
							isSelected={ labelSize.key === size.key }
							onClick={ () => handleSizeSelect( size, onClose ) }
						>
							{ size.name }
						</MenuItem>
					) ) }
				</MenuGroup>
			) }
		/>
	);
} );
