import { useContext } from '@wordpress/element';

import { LabelPurchaseContext } from './context';
import { LabelPurchaseContextType } from './types';

export const useLabelPurchaseContext = (): LabelPurchaseContextType => {
	return useContext( LabelPurchaseContext );
};
