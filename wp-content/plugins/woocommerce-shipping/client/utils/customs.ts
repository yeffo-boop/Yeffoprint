/**
 * Check if the HS Tariff Number is valid
 * It should be a string of 6 to 12 digits, with optional dots in between every 2 digits
 * @param hsTariffNumber
 */
export const isHSTariffNumberValid = ( hsTariffNumber: string ) => {
	const codePattern = /^(\d{1,2}\.?){3,6}$/;
	return (
		codePattern.test( hsTariffNumber ) &&
		hsTariffNumber.replace( /\D/g, '' ).length >= 6 &&
		hsTariffNumber.replace( /\D/g, '' ).length <= 12
	);
};

/**
 * Sanitize the HS Tariff Number
 * Remove all non-digit characters
 * @param hsTariffNumber
 */
export const sanitizeHSTariffNumber = ( hsTariffNumber: string ) => {
	return hsTariffNumber.replace( /\D/g, '' );
};
