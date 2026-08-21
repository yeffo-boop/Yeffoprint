import { useOriginAddressState } from 'components/shipping-settings/hooks';
import { OriginAddress } from 'types';

export interface ShippingSettingsContextType {
	originAddresses: ReturnType< typeof useOriginAddressState > & {
		addresses: OriginAddress[];
	};
}

export interface ShippingSettingsContextProviderProps {
	initialValue: ShippingSettingsContextType;
	children: React.JSX.Element | React.JSX.Element[];
}
