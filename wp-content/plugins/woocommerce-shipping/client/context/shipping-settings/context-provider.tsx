import { ShippingSettingsContextProviderProps } from './types';
import { ShippingSettingsContext } from './context';
export const ShippingSettingsContextProvider = ( {
	children,
	initialValue,
}: ShippingSettingsContextProviderProps ): React.JSX.Element => (
	<ShippingSettingsContext.Provider value={ initialValue }>
		{ children }
	</ShippingSettingsContext.Provider>
);
