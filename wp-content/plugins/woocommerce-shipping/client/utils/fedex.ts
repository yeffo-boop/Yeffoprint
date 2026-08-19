import { LabelPurchaseError, RateWithParent } from 'types';

export const isFedExTosError = ( error: LabelPurchaseError | null ): boolean =>
	error?.code === 'missing_fedex_terms_of_service_acceptance';

export const isFedExRate = ( selectedRate?: RateWithParent | null ): boolean =>
	selectedRate?.rate?.carrierId === 'fedex';
