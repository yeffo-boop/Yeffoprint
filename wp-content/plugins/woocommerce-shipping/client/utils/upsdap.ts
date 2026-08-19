import { LabelPurchaseError } from 'types';

export const getUPSDAPTosApprovedVersionsFromError = (
	error: LabelPurchaseError | null
): string[] => error?.data?.acceptedVersions ?? [];
