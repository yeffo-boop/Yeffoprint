import { Button, ToggleControl } from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

interface BatchSummaryProps {
	readyCount: number;
	needsFixCount: number;
	subtotal: number;
	discount: number;
	total: number;
	onPurchase: () => void;
}

const formatCurrency = ( value: number ): string => `$${ value.toFixed( 2 ) }`;

const BatchSummary = ( {
	readyCount,
	needsFixCount,
	subtotal,
	discount,
	total,
	onPurchase,
}: BatchSummaryProps ) => {
	return (
		<section className="bulk-purchase-modal__sidebar-card">
			<h3 className="bulk-purchase-modal__sidebar-heading">
				{ __( 'Batch summary', 'woocommerce-shipping' ) }
			</h3>
			<div className="bulk-purchase-modal__summary-counts">
				<div className="bulk-purchase-modal__count-pill bulk-purchase-modal__count-pill--ready">
					<div className="bulk-purchase-modal__count-pill-value">
						{ readyCount }
					</div>
					<div className="bulk-purchase-modal__count-pill-label">
						{ __( 'Ready to buy', 'woocommerce-shipping' ) }
					</div>
				</div>
				<div className="bulk-purchase-modal__count-pill bulk-purchase-modal__count-pill--needs-fix">
					<div className="bulk-purchase-modal__count-pill-value">
						{ needsFixCount }
					</div>
					<div className="bulk-purchase-modal__count-pill-label">
						{ __( 'Needs fix', 'woocommerce-shipping' ) }
					</div>
				</div>
			</div>
			<dl className="bulk-purchase-modal__summary-totals">
				<div className="bulk-purchase-modal__summary-row">
					<dt>{ __( 'Labels subtotal', 'woocommerce-shipping' ) }</dt>
					<dd>{ formatCurrency( subtotal ) }</dd>
				</div>
				{ discount > 0 && (
					<div className="bulk-purchase-modal__summary-row bulk-purchase-modal__summary-row--discount">
						<dt>
							{ __(
								'WooCommerce Shipping discount',
								'woocommerce-shipping'
							) }
						</dt>
						<dd>{ `−${ formatCurrency( discount ) }` }</dd>
					</div>
				) }
				<div className="bulk-purchase-modal__summary-row bulk-purchase-modal__summary-row--total">
					<dt>{ __( 'Total', 'woocommerce-shipping' ) }</dt>
					<dd>{ formatCurrency( total ) }</dd>
				</div>
			</dl>
			<div className="bulk-purchase-modal__charging">
				{ /* Placeholder card brand + last-four. Real payment data lands in WOOSHIP-2133. Left untranslated so dummy strings don't enter the translation memory. */ }
				💳 Charging Visa ·· 4242
				<a
					href="#change-card"
					className="bulk-purchase-modal__charging-link"
					onClick={ ( e ) => e.preventDefault() }
				>
					{ __( 'Change in settings.', 'woocommerce-shipping' ) }
				</a>
			</div>
			<Button
				variant="primary"
				className="bulk-purchase-modal__purchase-button"
				onClick={ onPurchase }
				disabled={ readyCount === 0 }
			>
				{ sprintf(
					/* translators: 1: number of ready labels, 2: total cost */
					_n(
						'Purchase %1$d label · %2$s',
						'Purchase %1$d labels · %2$s',
						readyCount,
						'woocommerce-shipping'
					),
					readyCount,
					formatCurrency( total )
				) }
			</Button>
			<p className="bulk-purchase-modal__purchase-footnote">
				{ __(
					"After purchase, we'll merge all labels into a single PDF for printing.",
					'woocommerce-shipping'
				) }
			</p>
		</section>
	);
};

const AfterPurchase = () => {
	const [ autoDownload, setAutoDownload ] = useState( true );
	const [ markCompleted, setMarkCompleted ] = useState( true );
	const [ emailTracking, setEmailTracking ] = useState( true );
	const [ scanForm, setScanForm ] = useState( false );

	return (
		<section className="bulk-purchase-modal__sidebar-card">
			<h3 className="bulk-purchase-modal__sidebar-heading">
				{ __( 'After purchase', 'woocommerce-shipping' ) }
			</h3>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Auto-download combined PDF',
					'woocommerce-shipping'
				) }
				checked={ autoDownload }
				onChange={ setAutoDownload }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Mark orders as completed',
					'woocommerce-shipping'
				) }
				checked={ markCompleted }
				onChange={ setMarkCompleted }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Email tracking numbers to customers',
					'woocommerce-shipping'
				) }
				checked={ emailTracking }
				onChange={ setEmailTracking }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Add USPS SCAN form to batch',
					'woocommerce-shipping'
				) }
				checked={ scanForm }
				onChange={ setScanForm }
			/>
		</section>
	);
};

const ShipFrom = () => (
	<section className="bulk-purchase-modal__sidebar-card">
		<div className="bulk-purchase-modal__ship-from-header">
			<h3 className="bulk-purchase-modal__sidebar-heading">
				{ __( 'Ship from', 'woocommerce-shipping' ) }
			</h3>
			<Button variant="secondary">
				{ __( 'Change', 'woocommerce-shipping' ) }
			</Button>
		</div>
		<div className="bulk-purchase-modal__ship-from-body">
			{ /* Placeholder ship-from. Wired to the real origin address in WOOSHIP-2133. Left untranslated so dummy strings don't enter the translation memory. */ }
			<strong>Escargot HQ</strong>
			<div>88 29th St PMB 343, San Francisco, CA 94110</div>
		</div>
	</section>
);

export const Sidebar = ( props: BatchSummaryProps ) => (
	<aside className="bulk-purchase-modal__sidebar">
		<BatchSummary { ...props } />
		<AfterPurchase />
		<ShipFrom />
	</aside>
);
