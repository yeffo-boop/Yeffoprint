import { createSelector } from '@wordpress/data';
import { uniqBy } from 'lodash';
import { camelCaseKeys, getCarrierPackages } from 'utils';
import { LabelPurchaseState } from '../../types';
import { Package } from 'types';

export const getPredefinedPackages = (
	state: LabelPurchaseState,
	carrierId?: string
): string[] | typeof state.packages.predefined => {
	return carrierId
		? state.packages.predefined?.[ carrierId ] ?? []
		: state.packages.predefined ?? [];
};

export const getPackageUpdateErrors = (
	state: LabelPurchaseState,
	packageType = 'custom'
) => {
	return state.packages.errors?.[ packageType ] ?? {};
};

const getCustomPackages = ( state: LabelPurchaseState ) => {
	return ( state.packages.custom ?? [] ).map( camelCaseKeys );
};

export const getSavedPackages = createSelector(
	( state: LabelPurchaseState ): Package[] => {
		return [
			...uniqBy(
				Object.values(
					getCarrierPackages( getPredefinedPackages( state ) )
				).flat(),
				'id'
			),
			...getCustomPackages( state ),
		];
	},
	( state ) => [ state.packages.predefined, state.packages.custom ]
);
