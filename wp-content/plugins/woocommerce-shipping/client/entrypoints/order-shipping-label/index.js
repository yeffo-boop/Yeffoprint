import { createRoot } from 'react-dom/client';
import { OrderLabelPurchase } from 'components/label-purchase';
import { registerLabelPurchaseStore } from 'data/label-purchase';
import { registerAddressStore } from 'data/address';
import { getConfig, initSentry, renderWhenDOMReady } from 'utils';

initSentry();

const renderLabelPurchase = () => {
	const domNode = document.getElementById(
		'woocommerce-shipping-shipping-label-shipping_label'
	);
	if ( ! domNode ) {
		return;
	}
	const root = createRoot( domNode );
	registerAddressStore( true );
	registerLabelPurchaseStore();
	root.render( <OrderLabelPurchase orderId={ getConfig().order.id } /> );
};

renderWhenDOMReady( renderLabelPurchase );
