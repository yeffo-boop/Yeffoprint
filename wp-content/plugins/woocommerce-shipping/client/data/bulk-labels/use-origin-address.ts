import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { getOriginAddressesPath } from 'data/routes';
import type { LocationResponse } from 'types';

interface UseOriginAddressResult {
	isResolving: boolean;
	error: Error | null;
	origin: Partial< LocationResponse > | null;
}

/**
 * Raw origin row from GET /wcshipping/v1/address/origins. Includes
 * fields needed by both rate requests and purchase persistence.
 */
interface RawOriginAddress {
	id?: string;
	company?: string;
	name?: string;
	first_name?: string;
	last_name?: string;
	phone?: string;
	address_1?: string;
	address_2?: string;
	city?: string;
	state?: string;
	postcode?: string;
	country?: string;
	default_address?: boolean;
	is_verified?: boolean;
}

export const toOrigin = (
	raw: RawOriginAddress
): Partial< LocationResponse > => {
	const trimmedName = raw.name?.trim();
	const name =
		trimmedName && trimmedName.length > 0
			? trimmedName
			: [ raw.first_name, raw.last_name ]
					.map( ( part ) => part?.trim() )
					.filter( Boolean )
					.join( ' ' );

	return {
		id: raw.id,
		company: raw.company,
		name,
		phone: raw.phone,
		address_1: raw.address_1,
		address_2: raw.address_2,
		city: raw.city,
		state: raw.state,
		postcode: raw.postcode,
		country: raw.country,
		is_verified: raw.is_verified,
	};
};

/**
 * Fetch the store's ship-from address once when the modal opens. The
 * batch rate endpoint quotes the whole batch from a single origin, so we
 * pick the merchant's default origin (falling back to the first one).
 */
export const useOriginAddress = (
	enabled: boolean
): UseOriginAddressResult => {
	const [ origin, setOrigin ] =
		useState< Partial< LocationResponse > | null >( null );
	const [ isResolving, setIsResolving ] = useState< boolean >( enabled );
	const [ error, setError ] = useState< Error | null >( null );

	useEffect( () => {
		if ( ! enabled ) {
			return;
		}

		let cancelled = false;
		setIsResolving( true );
		setError( null );

		apiFetch< RawOriginAddress[] >( { path: getOriginAddressesPath() } )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}
				const list = Array.isArray( response ) ? response : [];
				// Match the single-label rate flow: only quote from a
				// verified origin. If none is verified, block the quote
				// (origin stays null) and surface why, rather than
				// silently rating against an address the other flow
				// would reject.
				const verified = list.filter( ( row ) => row.is_verified );
				const picked =
					verified.find( ( row ) => row.default_address ) ??
					verified[ 0 ] ??
					null;
				if ( ! picked ) {
					setOrigin( null );
					setError(
						list.length > 0
							? new Error(
									__(
										'No verified ship-from address. Verify an origin address in settings to quote rates.',
										'woocommerce-shipping'
									)
							  )
							: null
					);
					setIsResolving( false );
					return;
				}
				setOrigin( toOrigin( picked ) );
				setIsResolving( false );
			} )
			.catch( ( err: unknown ) => {
				if ( cancelled ) {
					return;
				}
				setOrigin( null );
				setError(
					err instanceof Error
						? err
						: new Error( 'Failed to load the ship-from address.' )
				);
				setIsResolving( false );
			} );

		return () => {
			cancelled = true;
		};
	}, [ enabled ] );

	return useMemo(
		() => ( { isResolving, error, origin } ),
		[ isResolving, error, origin ]
	);
};
