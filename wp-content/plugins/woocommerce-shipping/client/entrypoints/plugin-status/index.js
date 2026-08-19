import { createRoot } from 'react-dom/client';
import PluginStatus from '../../components/plugin-status';
import { initAndRegisterStore } from 'wcshipping/data';
import { getConfig, initSentry } from 'utils';

initAndRegisterStore();

const domNode = document.getElementById( 'woocommerce-shipping-admin-status' );
const root = createRoot( domNode );
const { nonce, formData, storeOptions } = getConfig();

initSentry();

root.render(
	<PluginStatus
		healthItems={ formData.health_items }
		services={ formData.services }
		loggingEnabled={ formData.logging_enabled }
		debugEnabled={ formData.debug_enabled }
		logs={ formData.logs }
		nonce={ nonce }
		storeOptions={ storeOptions }
	/>
);
