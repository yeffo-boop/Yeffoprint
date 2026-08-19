import { useLabelPurchaseContext } from 'context/label-purchase';
import { useRatesEffects } from './rates-effects';

export const LabelPurchaseEffects = () => {
	const context = useLabelPurchaseContext();
	useRatesEffects( context );
	return null; // No UI to render.
};
