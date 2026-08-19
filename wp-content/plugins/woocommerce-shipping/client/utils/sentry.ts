import * as Sentry from '@sentry/react';

const REPLAY_SAMPLE_RATE = 0.005;

export const initSentry = () => {
	Sentry.init( {
		dsn: 'https://971a8d22e72fade3cc3bc7ee7c0c2093@o248881.ingest.us.sentry.io/4506903329046528',
		integrations: [ Sentry.replayIntegration() ],
		environment: window.wcShippingSettings?.environment,
		release: 'wcshipping@' + window.wcShippingSettings?.version,
		// Keep Replay volume low so wc-shipping does not exhaust the shared
		// Sentry Replay budget. 0.005 = 0.5% of sessions.
		replaysSessionSampleRate: REPLAY_SAMPLE_RATE,
		replaysOnErrorSampleRate: REPLAY_SAMPLE_RATE,
		// Only send errors to Sentry that comes WooCommerce or WooCommerce Shipping
		allowUrls: [
			/woocommerce\/assets\//, // Match woocommerce assets
			...( window.wcShippingSettings?.environment === 'local'
				? [ /\/woocommerce-shipping-/ ]
				: [] ), // Match local dev server
			/woocommerce-shipping\/assets/, // Match woocommerce-shipping assets
			/woocommerce-shipping\/dist/, // Match woocommerce-shipping assets under dist
			/wp-json\/wcshipping\//, // Match woocommerce-shipping endpoints
		],
	} );

	Sentry.setTag(
		'wc_version',
		window.wc?.wcSettings.WC_VERSION ?? 'unknown version'
	);
};
