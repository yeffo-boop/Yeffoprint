import { WCTracks } from './wc-tracks.d';
import { WCShippingSettings } from './wcshipping-settings.d';
import { WC } from './wc.d';
import { WCShippingAnalyticsConfig } from './wcshipping-analytics-config.d';
import { WCShippingConfig } from './wcshipping-config.d';

declare global {
	interface Window {
		WCShipping_Config: ( WCShippingConfig | WCShippingAnalyticsConfig ) & {
			navigate?: ( params: any ) => void; // eslint-disable-line @typescript-eslint/no-explicit-any
		};
		MSStream: unknown;
		wcTracks: WCTracks;
		wcShippingSettings: WCShippingSettings;
		wc?: WC;
		// For UMD build of the shipping plugin
		WCShipping_Plugin?: React.ComponentType;
	}
}

export * from './wcshipping-config.d';
export * from './helpers';
export * from './rate.d';
export * from './order-item.d';
export * from './package.d';
export * from './custom-package.d';
export * from './customs-item.d';
export * from './customs-state.d';
export * from './form-validation.d';
export * from './destination.d';
export * from './connect-server';
export * from './origin-address.d';
export * from './label.d';
export * from './store-options.d';
export * from './paper-size.d';
export * from './pdf-json.d';
export * from './hazmat.d';
export * from './hazmat-state.d';
export * from './selected-hazmat.d';
export * from './label-purchase-error.d';
export * from './order.d';
export * from './carrier.d';
export * from './address-normalization.d';
export * from './continent.d';
export * from './address-types.d';
export * from './selected-rates.d';
export * from './selected-origin.d';
export * from './selected-destination.d';
export * from './rate-with-parent.d';
export * from './reduxe-helpers.d';
export * from './available-packages.d';
export * from './wpcom-connection.d';
export * from './shipment-item.d';
export * from './shipment-type.d';
export * from './label-shipment-id-map.d';
export * from './label-print-fulfillment-ref.d';
export * from './store-notice.d';
export * from './user-meta.d';
export * from './constants.d';
export * from './payment-method.d';
export * from './purchase-meta.d';
export * from './purchase-settings.d';
export * from './wcshipping-settings-config.d';
export * from './weight-unit.d';
export * from './dimension-unit.d';
export * from './report-label.d';
export * from './report-response.d';
export * from './report-query.d';
export * from './wcshipping-analytics-config.d';
export * from './label-rate-type.d';
export * from './custom-package-type.d';
export * from './shipment-date.d';
export * from './request-extra-options.d';
export * from './return-shipment-info.d';
export * from './package-dimensions.d';
export * from './promotion.d';
export * from './shipments.d';
export * from './scanform-types.d';
export * from './site-settings.d';
export * from './core-data.d';
export * from './service-status.d';
