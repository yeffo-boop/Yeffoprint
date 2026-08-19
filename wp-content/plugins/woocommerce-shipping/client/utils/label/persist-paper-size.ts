import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';
import { getAccountSettingsPath } from 'data/routes';
import { PaperSize } from 'types';
import { getConfig } from 'utils/config';

// `data/settings` is loaded lazily because its reducer module calls
// `getAccountSettings()` from `utils` at top level, and pulling it in
// eagerly here re-creates a circular dep
// (`persist-paper-size -> data/settings -> reducer -> utils`) that
// Webpack tolerates but Jest does not, breaking every test in the
// import chain.

/**
 * Mirror the saved value onto window.WCShipping_Config so helpers that read
 * directly from the inline config pick it up without a page reload. Creates
 * the nested shape when missing so the mirror is never silently skipped after
 * a successful API write.
 */
const mirrorToWindowConfig = ( paperSize: PaperSize[ 'key' ] ): void => {
	const config = getConfig() as
		| {
				accountSettings?: {
					purchaseSettings?: {
						paper_size?: PaperSize[ 'key' ];
					};
				};
		  }
		| undefined;
	if ( ! config ) {
		return;
	}
	config.accountSettings = config.accountSettings ?? {};
	config.accountSettings.purchaseSettings =
		config.accountSettings.purchaseSettings ?? {};
	config.accountSettings.purchaseSettings.paper_size = paperSize;
};

export const persistPaperSize = async (
	paperSize: PaperSize[ 'key' ]
): Promise< void > => {
	// The settings endpoint's `update_account_settings()` treats any
	// boolean field missing from the POST body as `false`, then overwrites
	// the whole `account_settings` option. POSTing a paper-size-only payload
	// silently flips `enabled`, `email_receipts`, etc. to false and disables
	// the label-purchase metabox account-wide. Every POST below must carry
	// the full current `purchaseSettings` from `WCShipping_Config` so the
	// server cannot interpret a missing key as "set to false".
	const inlinePurchaseSettings =
		getConfig()?.accountSettings?.purchaseSettings ?? {};

	// The settings store may not be registered when this helper runs outside
	// the settings page. `registerSettingsStore` is idempotent.
	//
	// `settingsStore` is a `let` binding in the module that is only assigned
	// inside `registerSettingsStore()`. If we destructured both names here,
	// the local `settingsStore` reference would snapshot to `undefined`
	// because the module hasn't been registered yet. Read it from the module
	// namespace AFTER the register call so we pick up the assigned value.
	const settingsModule = await import( 'data/settings' );
	settingsModule.registerSettingsStore();
	const { settingsStore } = settingsModule;

	const storeConfig = select( settingsStore ).getConfigSettings();

	if ( storeConfig ) {
		if ( storeConfig.paper_size === paperSize ) {
			return;
		}

		const previousPaperSize = storeConfig.paper_size;
		await dispatch( settingsStore ).updateFormData(
			'paper_size',
			paperSize
		);

		const updatedConfig = select( settingsStore ).getConfigSettings();
		try {
			await dispatch( settingsStore ).saveSettings( {
				payload: {
					...inlinePurchaseSettings,
					...updatedConfig,
					paper_size: paperSize,
				},
			} );
		} catch ( e ) {
			// Roll back Redux so it matches the unsaved server state. The
			// window mirror has not run yet at this point, so nothing else
			// needs to be reverted. Re-throw so the caller can decide
			// whether to surface it.
			await dispatch( settingsStore ).updateFormData(
				'paper_size',
				previousPaperSize
			);
			throw e;
		}

		mirrorToWindowConfig( paperSize );
		return;
	}

	// Fallback for entrypoints that don't hydrate the settings store from
	// PHP (e.g. the orders list page). Read the previous value from the
	// inline `WCShipping_Config` and POST the change directly to the
	// account-settings endpoint. Without this, any size picker on a page
	// that hasn't bootstrapped the settings store would silently no-op.
	if ( inlinePurchaseSettings?.paper_size === paperSize ) {
		return;
	}

	await apiFetch( {
		path: getAccountSettingsPath(),
		method: 'POST',
		data: { ...inlinePurchaseSettings, paper_size: paperSize },
	} );

	mirrorToWindowConfig( paperSize );
};
