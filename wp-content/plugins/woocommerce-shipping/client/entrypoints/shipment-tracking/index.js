import { createRoot } from 'react-dom/client';
import { ShipmentTracking } from 'components/shipment-tracking';
import { getPurchasedLabels, initSentry, renderWhenDOMReady } from 'utils';

initSentry();
const renderShipmentTracking = () => {
	const domNode = document.getElementById(
		'woocommerce-shipping-shipping-label-shipment_tracking'
	);
	if ( ! domNode ) {
		return;
	}
	const root = createRoot( domNode );

	root.render(
		<ShipmentTracking
			labels={ Object.values( getPurchasedLabels() ).flat() }
		/>
	);
};

renderWhenDOMReady( renderShipmentTracking );
