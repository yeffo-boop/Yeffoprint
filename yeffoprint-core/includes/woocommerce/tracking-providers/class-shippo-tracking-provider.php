<?php
/**
 * Live tracking backed by Shippo's own /tracks/ endpoint instead of a
 * carrier's own API — direct request: "I want live tracking to show for
 * any orders that haven't been delivered." class-usps-tracking-provider.php
 * and class-ups-tracking-provider.php (this same directory) already exist
 * for that, but both are still waiting on real developer.usps.com/
 * developer.ups.com credentials (see their own docblocks) this store
 * doesn't have yet. Shippo, on the other hand, is already configured with
 * a live API key (class-shippo-settings.php) and can track a shipment
 * regardless of which system purchased its label — this class is what
 * makes live tracking actually work today, with no new credentials to go
 * get. class-tracking-provider-registry.php prefers this provider over
 * the carrier-native ones whenever Shippo itself is configured, falling
 * back to a carrier-native provider only when Shippo isn't set up.
 *
 * One instance per carrier (constructed with the carrier_id it represents)
 * rather than one shared instance — matches how the registry already
 * keys providers per carrier_id, and Shippo's own track endpoint takes
 * the carrier as part of the URL path, not the request body.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Shippo_Tracking_Provider implements YeffoPrint_Tracking_Provider {

	private YeffoPrint_Shippo_Client $client;
	private string $carrier_id;

	public function __construct( YeffoPrint_Shippo_Client $client, string $carrier_id ) {
		$this->client     = $client;
		$this->carrier_id = $carrier_id;
	}

	/** Only ever constructed by the registry once it's already confirmed Shippo is configured — see class-tracking-provider-registry.php. */
	public function is_configured(): bool {
		return true;
	}

	public function get_events( string $tracking_number ): array {
		$result = $this->client->track( $this->carrier_id, $tracking_number );

		if ( is_wp_error( $result ) ) {
			throw new YeffoPrint_Tracking_Exception( $result->get_error_message() );
		}

		return $result['events'];
	}
}
