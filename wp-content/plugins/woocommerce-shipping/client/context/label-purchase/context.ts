import { createContext } from '@wordpress/element';

import { LabelPurchaseContextType } from './types';

export const LabelPurchaseContext = createContext< LabelPurchaseContextType >(
	{} as LabelPurchaseContextType
);
