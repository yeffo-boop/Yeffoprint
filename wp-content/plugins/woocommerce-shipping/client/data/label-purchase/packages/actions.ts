import { apiFetch } from '@wordpress/data-controls';
import { getDeletePackagePath, getPackagesPath } from 'data/routes';
import { CustomPackage, CustomPackageResponse, CustomPackageType } from 'types';
import { camelCasePackageResponse } from 'utils';
import { PACKAGES_UPDATE, PACKAGES_UPDATE_ERROR } from './action-types';
import { PackageUpdateAction, PackageUpdateFailedAction } from '../types.d';

export function* saveFavoritePackage( payload: unknown ): Generator<
	ReturnType< typeof apiFetch >,
	PackageUpdateAction | PackageUpdateFailedAction,
	{
		custom: CustomPackageResponse[];
		predefined: Record< string, string[] >;
	}
> {
	try {
		const result = yield apiFetch( {
			path: getPackagesPath(),
			method: 'POST',
			data: {
				predefined: payload,
			},
		} );
		return {
			type: PACKAGES_UPDATE,
			payload: camelCasePackageResponse( result ),
		};
	} catch ( error ) {
		return {
			type: PACKAGES_UPDATE_ERROR,
			payload: error as Record< string, string >,
		};
	}
}

export function* updateFavoritePackages( payload: unknown ): Generator<
	ReturnType< typeof apiFetch >,
	PackageUpdateAction | PackageUpdateFailedAction,
	{
		custom: CustomPackageResponse[];
		predefined: Record< string, string[] >;
	}
> {
	try {
		const result = yield apiFetch( {
			path: getPackagesPath(),
			method: 'PUT',
			data: {
				predefined: payload,
			},
		} );
		return {
			type: PACKAGES_UPDATE,
			payload: camelCasePackageResponse( result ),
		};
	} catch ( error ) {
		return {
			type: PACKAGES_UPDATE_ERROR,
			payload: error as Record< string, string >,
		};
	}
}

export function* saveCustomPackage( payload: CustomPackage ): Generator<
	ReturnType< typeof apiFetch >,
	| PackageUpdateAction
	| PackageUpdateFailedAction< {
			custom: Record< string, string >;
	  } >,
	{
		custom: CustomPackageResponse[];
		predefined: Record< string, string[] >;
	}
> {
	const { length, width, height, isUserDefined, ...rest } = payload;
	const data = {
		custom: [
			{
				...rest,
				dimensions: `${ length } x ${ width } x ${ height }`,
				is_user_defined: true,
			},
		],
	};
	try {
		const result = yield apiFetch( {
			path: getPackagesPath(),
			method: 'POST',
			data,
		} );
		return {
			type: PACKAGES_UPDATE,
			payload: camelCasePackageResponse( result ),
		};
	} catch ( error ) {
		return {
			type: PACKAGES_UPDATE_ERROR,
			payload: {
				custom: error as Record< string, string >,
			},
		};
	}
}

export function* deletePackage(
	id: string,
	type: CustomPackageType
): Generator<
	ReturnType< typeof apiFetch >,
	| PackageUpdateAction
	| PackageUpdateFailedAction< {
			custom: Record< string, string >;
	  } >,
	{
		custom: CustomPackageResponse[];
		predefined: Record< string, string[] >;
	}
> {
	try {
		const result = yield apiFetch( {
			path: getDeletePackagePath( type, id ),
			method: 'DELETE',
		} );
		return {
			type: PACKAGES_UPDATE,
			payload: camelCasePackageResponse( result ),
		};
	} catch ( error ) {
		return {
			type: PACKAGES_UPDATE_ERROR,
			payload: {
				custom: error as Record< string, string >,
			},
		};
	}
}
