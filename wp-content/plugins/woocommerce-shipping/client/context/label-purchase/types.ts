import CurrencyFactory from '@woocommerce/currency';
import {
	useEssentialDetails,
	useAccountState,
	useTotalWeight,
	useShipmentState,
	useRatesState,
	usePackageState,
	useHazmatState,
	useCustomsState,
	useLabelsState,
} from 'components/label-purchase/hooks';

export interface LabelPurchaseContextType {
	orderItems: unknown[];
	storeCurrency: ReturnType< typeof CurrencyFactory >;
	hazmat: ReturnType< typeof useHazmatState >;
	packages: ReturnType< typeof usePackageState >;
	rates: ReturnType< typeof useRatesState >;
	shipment: Omit<
		ReturnType< typeof useShipmentState >,
		'getShipmentWeight'
	>;
	weight: ReturnType< typeof useTotalWeight > &
		Pick< ReturnType< typeof useShipmentState >, 'getShipmentWeight' >;
	customs: ReturnType< typeof useCustomsState >;
	labels: ReturnType< typeof useLabelsState >;
	account: ReturnType< typeof useAccountState >;
	essentialDetails: ReturnType< typeof useEssentialDetails >;
	nextDesign: boolean;
}

export interface LabelPurchaseContextProviderProps {
	initialValue: LabelPurchaseContextType;
	children: React.JSX.Element | React.JSX.Element[];
}
