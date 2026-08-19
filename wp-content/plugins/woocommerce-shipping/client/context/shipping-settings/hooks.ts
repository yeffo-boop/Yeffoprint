import { useContext } from '@wordpress/element';
import { ShippingSettingsContext } from './context';

export const useShippingSettingsContext = () => {
	return useContext( ShippingSettingsContext );
};
