import * as Sentry from '@sentry/react';
import './style.scss';
import { Button, Dropdown, MenuGroup, MenuItem } from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { chevronDown } from '@wordpress/icons';
import {
	forwardRef,
	useCallback,
	useEffect,
	useImperativeHandle,
	useState,
} from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import type { LabelPrintFulfillmentRef, PaperSize } from 'types';
import { recordEvent } from 'utils';
import { BulkLabelPrintError, useBulkLabelPrint } from './use-bulk-label-print';

/** Return value from the imperative print handle. */
export type BulkPrintDialogPrintResult =
	| { ok: true }
	| { ok: false; messages: string[] };

export interface BulkPrintDialogHandle {
	/**
	 * Trigger the print flow programmatically. Always resolves; failures are
	 * returned as `{ ok: false, messages }` so headless consumers (e.g. the
	 * WOOSHIP-2134 batch-results footer) can decide whether to surface them.
	 */
	print: () => Promise< BulkPrintDialogPrintResult >;
}

export interface BulkPrintDialogProps {
	labelRefs: LabelPrintFulfillmentRef[];

	autoPrint?: boolean;

	/** Optional override for consumers that need shorter button copy. */
	buttonLabel?: string;

	/** WordPress button style for the print and chevron buttons. */
	buttonVariant?: 'primary' | 'secondary' | 'tertiary' | 'link';

	/** Optional extra class name for layout-specific styling. */
	className?: string;

	/** When true, consumer renders its own controls and drives printing via the ref. */
	hideButton?: boolean;

	/** Called after a print attempt completes, including handled failures. */
	onPrintResult?: ( result: BulkPrintDialogPrintResult ) => void;
}

export const BulkPrintDialog = forwardRef<
	BulkPrintDialogHandle,
	BulkPrintDialogProps
>(
	(
		{
			labelRefs,
			autoPrint = false,
			buttonLabel,
			buttonVariant = 'primary',
			className,
			hideButton = false,
			onPrintResult,
		},
		ref
	) => {
		const {
			paperSizes,
			selectedPaperSize,
			selectPaperSize,
			printMergedLabels,
			isPrinting,
			isPersisting,
		} = useBulkLabelPrint( labelRefs );

		const [ hasAutoPrinted, setHasAutoPrinted ] = useState( false );

		// Surface failures through the WP admin notice store so they look the
		// same as the single-order print errors (`print-label-button.tsx`).
		// Returning `{ ok: false, messages }` keeps the imperative handle
		// contract intact for any consumer that wants to react beyond the
		// notice (e.g. a parent disabling its own retry button).
		const handlePrint = useCallback(
			async (
				size: PaperSize = selectedPaperSize
			): Promise< BulkPrintDialogPrintResult > => {
				recordEvent( 'bulk_label_print_button_clicked', {
					selected_label_size: size.key,
					default_label_size: selectedPaperSize.key,
					label_count: labelRefs.length,
				} );
				try {
					await printMergedLabels( size );
					const result = { ok: true } as const;
					onPrintResult?.( result );
					return result;
				} catch ( e ) {
					let messages: string[];
					if ( e instanceof BulkLabelPrintError ) {
						messages = [ ...e.messages ];
					} else {
						// Only capture unexpected errors here. A
						// `BulkLabelPrintError` is a typed signal (popup blocker,
						// no labels) thrown by the hook so we already know the
						// cause and have a user-facing message — sending it to
						// Sentry would just add noise.
						Sentry.captureException( e );
						// eslint-disable-next-line no-console
						console.error(
							'[wcshipping] Unexpected bulk-print error',
							e
						);
						messages = [
							__(
								'Error printing labels, try to print later.',
								'woocommerce-shipping'
							),
						];
					}
					(
						dispatch( 'core/notices' ) as {
							createErrorNotice: (
								message: string,
								options?: { isDismissible?: boolean }
							) => void;
						}
					 ).createErrorNotice( messages[ 0 ], {
						isDismissible: true,
					} );
					const result = { ok: false, messages } as const;
					onPrintResult?.( result );
					return result;
				}
			},
			[
				printMergedLabels,
				selectedPaperSize,
				labelRefs.length,
				onPrintResult,
			]
		);

		useImperativeHandle(
			ref,
			() => ( {
				print: () => handlePrint(),
			} ),
			[ handlePrint ]
		);

		// Auto-trigger once per mount when requested. The hasAutoPrinted guard
		// prevents re-fires if labelRefs changes after the initial print.
		useEffect( () => {
			if ( autoPrint && ! hasAutoPrinted && labelRefs.length > 0 ) {
				setHasAutoPrinted( true );
				handlePrint();
			}
		}, [ autoPrint, hasAutoPrinted, handlePrint, labelRefs.length ] );

		const handleSizeSelect = useCallback(
			async ( size: PaperSize, onClose: () => void ) => {
				recordEvent( 'bulk_label_print_size_dropdown_selected', {
					selected_label_size: size.key,
					default_label_size: selectedPaperSize.key,
				} );
				await selectPaperSize( size );
				const result = await handlePrint( size );
				if ( result.ok ) {
					onClose();
				}
			},
			[ handlePrint, selectedPaperSize, selectPaperSize ]
		);

		// Disable the main Print button (and chevron) while either printing is
		// in flight or the picked size is being persisted. Without the persist
		// gate, a click on Print during the persist-and-print window from the
		// dropdown would fire a second `handlePrint` of the same size.
		const isBusy = isPrinting || isPersisting;

		const handleChevronClick = useCallback( ( onToggle: () => void ) => {
			recordEvent( 'bulk_label_print_size_dropdown_clicked' );
			onToggle();
		}, [] );

		if ( labelRefs.length === 0 || hideButton ) {
			return null;
		}

		const defaultButtonLabel = sprintf(
			/* translators: 1: number of labels in the batch, 2: paper size (e.g. "4"x6"") */
			_n(
				'Print %1$d label (%2$s)',
				'Print %1$d labels (%2$s)',
				labelRefs.length,
				'woocommerce-shipping'
			),
			labelRefs.length,
			selectedPaperSize.size
		);

		return (
			<Dropdown
				className={
					className
						? `bulk-print-dialog ${ className }`
						: 'bulk-print-dialog'
				}
				popoverProps={ {
					placement: 'bottom-end',
					noArrow: false,
					resize: true,
					shift: true,
					inline: true,
				} }
				renderToggle={ ( { isOpen, onToggle } ) => (
					<div className="bulk-print-dialog__toggle">
						<Button
							className="bulk-print-dialog__print-button"
							onClick={ () => handlePrint() }
							isBusy={ isBusy }
							disabled={ isBusy }
							variant={ buttonVariant }
							style={ {
								borderTopRightRadius: 0,
								borderBottomRightRadius: 0,
								borderRight:
									'1px solid rgba(255, 255, 255, 0.4)',
							} }
						>
							{ buttonLabel ?? defaultButtonLabel }
						</Button>
						<Button
							className="bulk-print-dialog__chevron-button"
							disabled={ isBusy }
							onClick={ () => handleChevronClick( onToggle ) }
							icon={ chevronDown }
							variant={ buttonVariant }
							aria-expanded={ isOpen }
							aria-label={ __(
								'Select label size',
								'woocommerce-shipping'
							) }
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
						label={ __(
							'Select Label Size',
							'woocommerce-shipping'
						) }
					>
						{ paperSizes.map( ( size ) => (
							<MenuItem
								key={ size.key }
								isSelected={
									selectedPaperSize.key === size.key
								}
								onClick={ () =>
									handleSizeSelect( size, onClose )
								}
							>
								{ size.name }
							</MenuItem>
						) ) }
					</MenuGroup>
				) }
			/>
		);
	}
);
