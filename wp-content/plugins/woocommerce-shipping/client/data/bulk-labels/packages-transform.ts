/**
 * Turns the GET /wcshipping/v1/packages payload into the flat list of
 * box-type `AssignablePackage`s the per-order package dropdown renders.
 * Kept separate from the store/hook so the bulk-labels @wordpress/data
 * store can import it without a circular dependency.
 */

import type { AssignablePackage } from './types';

/**
 * Raw shapes from GET /wcshipping/v1/packages. Only the fields we need
 * are modeled; the endpoint also returns storeOptions / success etc.
 */
interface RawCustomPackage {
	id?: string;
	name?: string;
	dimensions?: string;
	length?: number | string;
	width?: number | string;
	height?: number | string;
	box_weight?: number;
	boxWeight?: number;
	is_letter?: boolean;
	isLetter?: boolean;
	type?: string;
}

interface RawPredefinedDefinition {
	id?: string;
	name?: string;
	dimensions?: string;
	inner_dimensions?: string;
	outer_dimensions?: string;
	box_weight?: number;
	is_letter?: boolean;
	isLetter?: boolean;
	type?: string;
}

type RawSchema = Record<
	string,
	Record< string, { definitions?: RawPredefinedDefinition[] } >
>;

/**
 * The endpoint shape has drifted between versions (and the docs vs. the
 * PHP differ on whether custom packages carry `type`). Model every
 * nesting we've seen so a small shape change can't silently empty the
 * dropdown again.
 */
export interface RawPackagesResponse {
	packages?: {
		saved?: {
			custom?: RawCustomPackage[];
			predefined?: Record< string, string[] >;
		};
		custom?: RawCustomPackage[];
		predefined?: RawSchema | Record< string, string[] >;
	};
	custom?: RawCustomPackage[];
	predefined?: Record< string, string[] >;
}

const toNumber = ( value: unknown ): number => {
	const n = typeof value === 'string' ? parseFloat( value ) : Number( value );
	return Number.isFinite( n ) ? n : 0;
};

interface ParsedDims {
	length: number;
	width: number;
	height: number;
}

/**
 * Resolve numeric L/W/H from explicit fields, falling back to parsing a
 * "L x W x H" string. Zeros mean "unknown" — callers decide whether that
 * disqualifies the box from a rate request.
 */
const parseDims = (
	length?: number | string,
	width?: number | string,
	height?: number | string,
	dimensionsString?: string
): ParsedDims => {
	const explicit = [ length, width, height ].map( toNumber );
	if ( explicit.every( ( n ) => n > 0 ) ) {
		return {
			length: explicit[ 0 ],
			width: explicit[ 1 ],
			height: explicit[ 2 ],
		};
	}

	if ( dimensionsString ) {
		const matched = /([-.0-9]+).+?([-.0-9]+).+?([-.0-9]+)/.exec(
			dimensionsString
		);
		if ( matched ) {
			const parsed = matched.slice( 1, 4 ).map( Number );
			if ( parsed.every( ( n ) => Number.isFinite( n ) && n > 0 ) ) {
				return {
					length: parsed[ 0 ],
					width: parsed[ 1 ],
					height: parsed[ 2 ],
				};
			}
		}
	}

	return { length: 0, width: 0, height: 0 };
};

/** Compact "L×W×H" for the package cell; empty when any dimension is unknown. */
const formatDims = ( dims: ParsedDims ): string =>
	[ dims.length, dims.width, dims.height ].every( ( n ) => n > 0 )
		? [ dims.length, dims.width, dims.height ].join( '×' )
		: '';

/**
 * Whether a package is a rigid box. The auto-assign service uses a strict
 * `type === 'box'` filter, but the GET /packages payload has been seen
 * without an explicit `type` (only `is_letter`), and `Package::from_array`
 * itself defaults a missing type to "box". So treat a package as a box
 * unless it's *explicitly* an envelope / letter — a stricter check is
 * what was wrongly hiding the merchant's custom boxes from the dropdown.
 */
const isBox = ( pkg: {
	is_letter?: boolean;
	isLetter?: boolean;
	type?: string;
} ): boolean => {
	if ( pkg.type ) {
		return pkg.type.toLowerCase() === 'box';
	}
	return pkg.is_letter !== true && pkg.isLetter !== true;
};

/** Does this look like the predefined *schema* (carrier → groups → definitions)? */
const looksLikeSchema = ( value: unknown ): value is RawSchema => {
	if ( ! value || typeof value !== 'object' ) {
		return false;
	}
	const firstCarrier = Object.values(
		value as Record< string, unknown >
	)[ 0 ];
	if ( ! firstCarrier || typeof firstCarrier !== 'object' ) {
		return false;
	}
	const firstGroup = Object.values(
		firstCarrier as Record< string, unknown >
	)[ 0 ];
	return (
		!! firstGroup &&
		typeof firstGroup === 'object' &&
		'definitions' in ( firstGroup as object )
	);
};

export const buildAssignablePackages = (
	response: RawPackagesResponse | undefined
): AssignablePackage[] => {
	const pkgs = response?.packages;
	const predefinedAtPkgs = pkgs?.predefined;
	const predefinedIsSchema = looksLikeSchema( predefinedAtPkgs );

	// Custom boxes can arrive at packages.saved.custom, packages.custom, or
	// the top level depending on endpoint/version.
	const customList: RawCustomPackage[] =
		pkgs?.saved?.custom ?? pkgs?.custom ?? response?.custom ?? [];

	// Starred predefined map (carrier → [ids]) vs. the full predefined
	// schema (carrier → groups → definitions). Either can sit at
	// packages.predefined depending on the shape, so disambiguate.
	const starredFromPkgs =
		! predefinedIsSchema && predefinedAtPkgs
			? ( predefinedAtPkgs as Record< string, string[] > )
			: undefined;
	const starredPredefined: Record< string, string[] > =
		pkgs?.saved?.predefined ??
		starredFromPkgs ??
		response?.predefined ??
		{};

	const schema: RawSchema = predefinedIsSchema
		? ( predefinedAtPkgs as RawSchema )
		: {};

	const result: AssignablePackage[] = [];

	// Saved custom boxes — self-describing, no schema lookup needed.
	for ( const custom of customList ) {
		const id = custom.id?.trim();
		if ( ! id || ! isBox( custom ) ) {
			continue;
		}
		const customName = custom.name?.trim();
		const dims = parseDims(
			custom.length,
			custom.width,
			custom.height,
			custom.dimensions
		);
		result.push( {
			key: `custom:${ id }`,
			package_id: id,
			service_id: null,
			name: customName && customName.length > 0 ? customName : id,
			dimensions: formatDims( dims ),
			length: dims.length,
			width: dims.width,
			height: dims.height,
			weight: custom.box_weight ?? custom.boxWeight ?? 0,
			is_letter: false,
		} );
	}

	// Starred predefined boxes — resolve each starred id against the
	// per-carrier schema so the option carries a real name + dimensions.
	for ( const [ carrierId, starredIds ] of Object.entries(
		starredPredefined
	) ) {
		if ( ! Array.isArray( starredIds ) || starredIds.length === 0 ) {
			continue;
		}
		const starred = new Set( starredIds );
		const carrierGroups = schema[ carrierId ] ?? {};

		for ( const group of Object.values( carrierGroups ) ) {
			for ( const def of group.definitions ?? [] ) {
				const id = def.id?.trim();
				if ( ! id || ! starred.has( id ) || ! isBox( def ) ) {
					continue;
				}
				const defName = def.name?.trim();
				const dims = parseDims(
					undefined,
					undefined,
					undefined,
					def.inner_dimensions ??
						def.dimensions ??
						def.outer_dimensions
				);
				result.push( {
					key: `predef:${ carrierId }:${ id }`,
					package_id: id,
					service_id: carrierId,
					name: defName && defName.length > 0 ? defName : id,
					dimensions: formatDims( dims ),
					length: dims.length,
					width: dims.width,
					height: dims.height,
					weight: def.box_weight ?? 0,
					is_letter: false,
				} );
			}
		}
	}

	return result;
};
