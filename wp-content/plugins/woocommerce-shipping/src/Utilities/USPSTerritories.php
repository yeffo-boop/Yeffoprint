<?php
/**
 * USPSTerritories class.
 *
 * Single source of truth for the countries/territories USPS treats as
 * domestic mail origins/destinations. Mirrors
 * `ACCEPTED_USPS_ORIGIN_COUNTRIES` on the client.
 *
 * @package Automattic/WCShipping
 * @since 2.3.0
 */

namespace Automattic\WCShipping\Utilities;

/**
 * USPSTerritories class.
 *
 * @since 2.3.0
 */
class USPSTerritories {

	/**
	 * USPS domestic mail territory country codes.
	 *
	 * @see https://webpmt.usps.gov/pmt010.cfm
	 *
	 * @var string[]
	 */
	public const DOMESTIC_MAIL_TERRITORIES = array(
		'US', // United States.
		'AS', // American Samoa.
		'PR', // Puerto Rico.
		'VI', // Virgin Islands.
		'GU', // Guam.
		'MP', // Northern Mariana Islands.
		'UM', // United States Minor Outlying Islands.
		'FM', // Micronesia.
		'MH', // Marshall Islands.
	);

	/**
	 * Check if a country code is in the USPS domestic mail territory list.
	 *
	 * @since 2.3.0
	 * @param string $country_code Country code to check (case-insensitive).
	 * @return bool True if the code is a USPS domestic mail territory.
	 */
	public static function is_domestic_mail_territory( string $country_code ): bool {
		return in_array( strtoupper( $country_code ), self::DOMESTIC_MAIL_TERRITORIES, true );
	}

	/**
	 * Determine if a shipment is domestic based on origin and destination countries.
	 *
	 * A shipment is domestic if origin and destination are the same country,
	 * or if both are in the USPS domestic mail territory list. Labels with an
	 * unknown (empty) origin or destination are classified as NOT domestic so
	 * they do not silently join a domestic batch.
	 *
	 * @since 2.3.0
	 * @param string $origin_country      Origin country code.
	 * @param string $destination_country Destination country code.
	 * @return bool True if the shipment is domestic.
	 */
	public static function is_domestic_shipment( string $origin_country, string $destination_country ): bool {
		$origin      = strtoupper( $origin_country );
		$destination = strtoupper( $destination_country );

		if ( '' === $origin || '' === $destination ) {
			return false;
		}

		return $origin === $destination
			|| ( self::is_domestic_mail_territory( $origin )
				&& self::is_domestic_mail_territory( $destination ) );
	}
}
