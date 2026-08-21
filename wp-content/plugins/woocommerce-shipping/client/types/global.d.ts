/**
 * @wordpress/preferences is not typed, so we need to declare it here
 * @see https://github.com/woocommerce/woocommerce/blob/1db393bd033e64395cba5b1406951247b81860dd/packages/js/email-editor/src/types/wordpress-modules.ts#L90
 */
declare module '@wordpress/preferences' {
	import { StoreDescriptor } from '@wordpress/data/build-types/types';

	export const store: { name: 'core/preferences' } & StoreDescriptor< {
		reducer: () => unknown;
		selectors: {
			get: < T >( state: unknown, scope: string, name: string ) => T;
		};
	} >;
	export const PreferenceToggleMenuItem: any;
}
