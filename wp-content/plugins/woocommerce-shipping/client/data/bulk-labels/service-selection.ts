/**
 * Resolve which quoted rate a row should default to, given the
 * "Apply to all" service strategy and any per-order manual override.
 *
 * The two sentinels resolve dynamically against each order's own rates;
 * a named strategy (e.g. "USPS Priority Mail") matches by title /
 * service id and falls back to the cheapest rate when the order doesn't
 * offer that service.
 */

import { __ } from '@wordpress/i18n';
import { SERVICE_CHEAPEST, SERVICE_FASTEST } from './types';
import type { ApplyOption } from 'components/bulk-labels/bulk-purchase-modal/toolbar';
import type { OrderRate } from './types';

/** Named strategies offered by the Service "Apply to all" control. */
export const NAMED_SERVICE_STRATEGIES = [
	'USPS Priority Mail',
	'USPS Ground Advantage',
] as const;

const cheapest = ( rates: OrderRate[] ): OrderRate | null =>
	rates.reduce< OrderRate | null >(
		( best, r ) => ( ! best || r.rate < best.rate ? r : best ),
		null
	);

const fastest = ( rates: OrderRate[] ): OrderRate | null => {
	const withEta = rates.filter( ( r ) => typeof r.deliveryDays === 'number' );
	if ( withEta.length === 0 ) {
		// No transit estimates to compare — cheapest is the safest default.
		return cheapest( rates );
	}
	return withEta.reduce< OrderRate | null >(
		( best, r ) =>
			! best || r.deliveryDays! < best.deliveryDays! ? r : best,
		null
	);
};

/** Lowercase + collapse every non-alphanumeric run to a single space. */
const normalize = ( value: string ): string =>
	value
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, ' ' )
		.trim();

/**
 * A rate matches a named strategy when every word of the strategy
 * appears as a word in the rate's title or service id. This tolerates
 * real-world shapes like title "USPS - Priority Mail" / service id
 * "Priority" against the "USPS Priority Mail" option, instead of the
 * old exact include/equals check that fell back to cheapest.
 */
const matchesNamed = ( rate: OrderRate, strategy: string ): boolean => {
	const haystack = new Set(
		`${ normalize( rate.title ) } ${ normalize( rate.serviceId ) }`
			.split( ' ' )
			.filter( Boolean )
	);
	const words = normalize( strategy ).split( ' ' ).filter( Boolean );
	return words.length > 0 && words.every( ( word ) => haystack.has( word ) );
};

/**
 * The rate a given order should select. `manualRateId` (a row-level
 * override) wins when it still exists in the order's rates.
 */
export const resolveSelectedRate = (
	rates: OrderRate[],
	mode: string,
	manualRateId?: string
): OrderRate | null => {
	if ( rates.length === 0 ) {
		return null;
	}

	if ( manualRateId ) {
		const manual = rates.find( ( r ) => r.rateId === manualRateId );
		if ( manual ) {
			return manual;
		}
	}

	if ( mode === SERVICE_FASTEST ) {
		return fastest( rates );
	}
	if ( mode === SERVICE_CHEAPEST ) {
		return cheapest( rates );
	}

	const named = rates.find( ( r ) => matchesNamed( r, mode ) );
	return named ?? cheapest( rates );
};

/** Build the Service "Apply to all" option list (order matters). */
export const buildServiceApplyOptions = (): ApplyOption[] => [
	{
		label: __( 'Cheapest available', 'woocommerce-shipping' ),
		value: SERVICE_CHEAPEST,
	},
	{
		label: __( 'Fastest available', 'woocommerce-shipping' ),
		value: SERVICE_FASTEST,
	},
	...NAMED_SERVICE_STRATEGIES.map( ( strategy ) => ( {
		label: strategy,
		value: strategy,
	} ) ),
];
