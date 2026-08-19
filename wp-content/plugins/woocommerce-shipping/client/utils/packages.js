import { getConfig } from './config';
import { camelCaseKeys } from './common';

export const getCarrierPackages = (
	predefinedPackages,
	config = getConfig()
) => {
	const carrierPackages = {};
	if ( ! config.packagesSettings.schema?.predefined ) {
		return carrierPackages;
	}

	for ( const [ carrierId, pkg ] of Object.entries(
		config.packagesSettings.schema.predefined
	) ) {
		for ( const [ , pkgData ] of Object.entries( pkg ) ) {
			carrierPackages[ carrierId ] ??= [];

			pkgData.definitions.forEach( ( definition ) => {
				if (
					( predefinedPackages[ carrierId ] ?? [] ).includes(
						definition.id
					)
				) {
					carrierPackages[ carrierId ].push( {
						...camelCaseKeys( definition ),
						carrierId,
					} );
				}
			} );
		}
	}

	return carrierPackages;
};

export const getCustomPackages = ( config = getConfig() ) => {
	return config.packagesSettings.packages.custom ?? [];
};

export const getAvailableCarrierPackages = ( config = getConfig() ) => {
	return Object.entries(
		config.packagesSettings.schema?.predefined ?? {}
	).reduce( ( acc, [ carrierId, groups ] ) => {
		return {
			...acc,
			[ carrierId ]: Object.entries( groups ).reduce(
				( groupAcc, [ groupId, group ] ) => {
					return {
						...groupAcc,
						[ groupId ]: {
							...group,
							definitions: group.definitions.map( camelCaseKeys ),
						},
					};
				},
				{}
			),
		};
	}, {} );
};

export const hasUPSPackages = () => {
	const availablePackages = getAvailableCarrierPackages();
	return availablePackages?.upsdap;
};

export const getPackageDimensions = ( {
	outerDimensions,
	innerDimensions,
	dimensions,
} ) => {
	const boxDimensions = (
		outerDimensions ??
		dimensions ??
		innerDimensions
	).match( /([-.0-9]+).+?([-.0-9]+).+?([-.0-9]+)/ );

	const [ length, width, height ] = boxDimensions.slice( 1 ).map( Number );

	return { length, width, height };
};

// Generates a flat 'package ID' => 'package definition' structure from the tree of carrier packages.
export const getAvailablePackagesById = ( config = getConfig() ) => {
	return Object.fromEntries(
		Object.values( getAvailableCarrierPackages( config ) ).reduce(
			( packageGroupsByCarrierAcc, packageGroupsByCarrier ) => [
				...packageGroupsByCarrierAcc,
				...Object.values( packageGroupsByCarrier ).reduce(
					( carrierPackageGroupAcc, carrierPackageGroup ) => [
						...carrierPackageGroupAcc,
						...carrierPackageGroup.definitions.map( ( pckg ) => [
							pckg.id,
							pckg,
						] ),
					],
					[]
				),
			],
			[]
		)
	);
};
