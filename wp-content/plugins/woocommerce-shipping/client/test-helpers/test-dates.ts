/**
 * Date mock for tests
 *
 * This file provides a consistent date mock for Jest tests.
 * Import this in your test files to ensure consistent date behavior.
 *
 * Usage:
 * import { setupDateMock, teardownDateMock } from '../../tests/js/date-mock';
 *
 * beforeAll(() => {
 *   setupDateMock();
 * });
 *
 * afterAll(() => {
 *   teardownDateMock();
 * });
 */

// Store the real Date implementation
const OriginalDate = global.Date;

// Fixed date for testing (February 26, 2025 at noon UTC)
const MOCK_DATE = new Date( '2025-02-26T12:00:00.000Z' );

/**
 * Sets up the Date mock
 */
export const setupDateMock = () => {
	// @ts-ignore
	global.Date = class extends OriginalDate {
		// @ts-ignore
		constructor( date ) {
			if ( date ) {
				return new OriginalDate( date );
			}
			return new OriginalDate( MOCK_DATE );
		}

		static now() {
			return MOCK_DATE.getTime();
		}
	};
};

/**
 * Tears down the Date mock and restores the original Date
 */
export const teardownDateMock = () => {
	global.Date = OriginalDate;
};

/**
 * Normalize a date by setting the time to noon UTC to ensure consistent serialization
 * This helps prevent snapshot test failures due to time differences
 *
 * @param {Date} date - The date to normalize
 * @return {Date} The normalized date
 */
export const normalizeDate = ( date: Date ) => {
	const normalized = new Date( date );
	normalized.setUTCHours( 12, 0, 0, 0 );
	return normalized;
};
