import apiFetch from '@wordpress/api-fetch';
import { useState, useEffect } from '@wordpress/element';
import { NAMESPACE } from 'data/constants';
import { registerAddressStore } from 'data/address';
import { registerSettingsStore } from 'data/settings';
import type { WCShippingConfig } from 'types';
import { __dangerousOptInToUnstableAPIsOnlyForCoreModules } from '@wordpress/private-apis';

let configPromise: Promise< WCShippingConfig > | null = null;

export const loadConfig = async (): Promise< WCShippingConfig > => {
	if ( window.WCShipping_Config ) {
		// Still register stores so this bundle's store refs are set (e.g. when
		// navigating from "Buy a shipping label" to "Shipping operations").
		registerAddressStore( false );
		registerSettingsStore();
		return window.WCShipping_Config as WCShippingConfig;
	}
	configPromise ??= ( async () => {
		const config = await apiFetch< WCShippingConfig >( {
			path: NAMESPACE + '/config/settings',
		} );
		window.WCShipping_Config = config;
		registerAddressStore( false );
		registerSettingsStore();
		return config as WCShippingConfig;
	} )();
	return configPromise;
};

/**
 * Ensures config (and registered stores) are loaded on mount. Returns true once
 * loading has completed. Use when a component needs to wait for config before
 * rendering (e.g. using the address store), or to kick off loading when a
 * screen mounts.
 */
export const useConfigLoader = (): boolean => {
	const [ isConfigReady, setIsConfigReady ] = useState( false );

	useEffect( () => {
		loadConfig().then( () => setIsConfigReady( true ) );
	}, [] );

	return isConfigReady;
};

export const { lock, unlock } =
	__dangerousOptInToUnstableAPIsOnlyForCoreModules(
		'I acknowledge private features are not for use in themes or plugins and doing so will break in the next version of WordPress.',
		'@wordpress/edit-site'
	);
