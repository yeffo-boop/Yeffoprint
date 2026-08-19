import { getDate, getSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

const DATE_FORMAT_OPTIONS = { month: 'long', day: 'numeric' } as const;

/**
 * Get a date object from a date string
 *  getDate() without params returns current date
 *
 * the original getDate doctype requires a dateString, but it's not used if not provided
 * @see @wordpress/date
 * @param dateString          - The date string to get the date object from
 * @param normalizeToMidnight - Whether to normalize the date to midnight
 * @return The date object
 */
export const getDateTS = (
	dateString?: string | null,
	normalizeToMidnight = false
) => {
	const date = getDate( dateString ?? null );
	if ( normalizeToMidnight ) {
		date.setHours( 0, 0, 0, 0 );
	}
	return date;
};

/**
 * Get the display date for the shipping date
 *
 * @param date - The date to get the display date for
 * @return The display date in the format of 'Today (25 Feb)' or 'Tomorrow (26 Feb)' or the date in the format of '25 Feb'
 */
export const getDisplayDate = ( date: Date ) => {
	// Check if date is today or tomorrow
	const today = getDateTS();
	today.setHours( 0, 0, 0, 0 );

	const tomorrow = getDateTS();
	tomorrow.setDate( tomorrow.getDate() + 1 );
	tomorrow.setHours( 0, 0, 0, 0 );

	const dateOnly = getDateTS( date.toISOString() );
	dateOnly.setHours( 0, 0, 0, 0 );

	const formattedDate = date.toLocaleDateString(
		getSettings().l10n.locale.replace( '_', '-' ),
		DATE_FORMAT_OPTIONS
	);

	if ( dateOnly.getTime() === today.getTime() ) {
		return sprintf(
			// translators: %s is the formatted date
			__( 'Today (%s)', 'woocommerce-shipping' ),
			formattedDate
		);
	} else if ( dateOnly.getTime() === tomorrow.getTime() ) {
		return sprintf(
			// translators: %s is the formatted date
			__( 'Tomorrow (%s)', 'woocommerce-shipping' ),
			formattedDate
		);
	}
	return formattedDate;
};

/**
 * Check if a date is valid
 *
 */
export const isDateValid = ( date: string ): boolean => {
	const dateObject = new Date( date );
	return ! isNaN( dateObject.getTime() );
};

/**
 * Extract UTC date components from a date string or Date object.
 * This is useful for comparing dates without timezone conversion issues.
 *
 * @param date - The date string or Date object to extract UTC components from
 * @return Object with year, month, and day in UTC
 */
export const getUTCDateComponents = (
	date: string | Date
): { year: number; month: number; day: number } => {
	const dateObj = typeof date === 'string' ? new Date( date ) : date;
	return {
		year: dateObj.getUTCFullYear(),
		month: dateObj.getUTCMonth(),
		day: dateObj.getUTCDate(),
	};
};

/**
 * Validate that a delivery date is after the ship date.
 * Compares dates using UTC to avoid timezone conversion issues.
 *
 * For overnight/next-day services, the delivery date should be at least
 * the day after the ship date. Same-day delivery is not supported for
 * these services.
 *
 * @param deliveryDate - The delivery date from the API (ISO string)
 * @param shipDate     - The ship date (Date object)
 * @return True if the delivery date is valid (after ship date) or if validation
 *         cannot be performed due to missing dates, false if the delivery date
 *         is invalid (same day or before ship date)
 */
export const isDeliveryDateValid = (
	deliveryDate: string | null | undefined,
	shipDate: Date | null | undefined
): boolean => {
	if ( ! deliveryDate || ! shipDate ) {
		return true; // If we don't have both dates, we can't validate
	}

	const delivery = getUTCDateComponents( deliveryDate );
	const ship = getUTCDateComponents( shipDate );

	// Create comparable date values (days since epoch at UTC midnight)
	const deliveryDays =
		Date.UTC( delivery.year, delivery.month, delivery.day ) /
		( 1000 * 60 * 60 * 24 );
	const shipDays =
		Date.UTC( ship.year, ship.month, ship.day ) / ( 1000 * 60 * 60 * 24 );

	// Delivery date must be after ship date (not same day)
	return deliveryDays > shipDays;
};

/**
 * Convert a Date object to a UTC midnight ISO string, preserving the local date.
 *
 * Extracts the local date components (year, month, day) and returns them as a
 * UTC midnight timestamp. This prevents timezone conversion from shifting the date.
 *
 * Example: If local date is 2025-12-19 in UTC+7:
 * - date.toISOString() returns '2025-12-18T17:00:00.000Z' (shifted)
 * - toUTCMidnightISOString() returns '2025-12-19T00:00:00.000Z' (preserved)
 *
 * @param date - The Date object to convert.
 * @return ISO string at UTC midnight (YYYY-MM-DDTHH:mm:ss.sssZ).
 */
export const toUTCMidnightISOString = ( date: Date ): string => {
	return new Date(
		Date.UTC( date.getFullYear(), date.getMonth(), date.getDate() )
	).toISOString();
};
