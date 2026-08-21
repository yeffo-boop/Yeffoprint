import { createContext } from '@wordpress/element';
import { ShippingSettingsContextType } from './types';

export const ShippingSettingsContext =
	createContext< ShippingSettingsContextType >(
		{} as ShippingSettingsContextType
	);
