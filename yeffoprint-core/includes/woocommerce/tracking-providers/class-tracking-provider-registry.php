<?php
/**
 * Maps a WC Shipping `carrier_id` (class-order-tracking.php) to the
 * provider that can look up live events for it. One place to ask "do we
 * have working credentials for this carrier yet" — the REST controller
 * doesn't need to know how each provider is configured, just whether it
 * is.
 *
 * Prefers Shippo (class-shippo-tracking-provider.php) over a carrier's
 * own native provider whenever Shippo itself is configured — direct
 * request, "I want live tracking to show." Shippo already has a live API
 * key on this store and can track any of the four carriers below without
 * needing separate developer.usps.com/developer.ups.com credentials,
 * unlike the carrier-native providers, which are still waiting on those
 * (see their own docblocks). Falls back to a carrier-native provider only
 * when Shippo isn't configured — keeping USPS/UPS's own providers around
 * means whenever real carrier credentials do get added, they're already
 * wired up and ready, no extra work needed then either.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Tracking_Provider_Registry {

	/** @var array<string, YeffoPrint_Tracking_Provider|null> */
	private array $providers;

	public function __construct() {
		$shippo_client = YeffoPrint_Shippo_Settings::is_configured()
			? new YeffoPrint_Shippo_Client( YeffoPrint_Shippo_Settings::get_api_key() )
			: null;

		$this->providers = [
			'usps'        => $this->pick( $shippo_client, 'usps', new YeffoPrint_Usps_Tracking_Provider() ),
			'ups'         => $this->pick( $shippo_client, 'ups', new YeffoPrint_Ups_Tracking_Provider() ),
			// Shippo can track these two as well; this store has no
			// FedEx/DHL Express-native provider of its own to fall back to
			// (only USPS/UPS shipments have ever come up), so without
			// Shippo configured there's simply nothing to offer for them.
			'fedex'       => $this->pick( $shippo_client, 'fedex', null ),
			'dhl_express' => $this->pick( $shippo_client, 'dhl_express', null ),
		];
	}

	private function pick( ?YeffoPrint_Shippo_Client $shippo_client, string $carrier_id, ?YeffoPrint_Tracking_Provider $native ): ?YeffoPrint_Tracking_Provider {
		return $shippo_client ? new YeffoPrint_Shippo_Tracking_Provider( $shippo_client, $carrier_id ) : $native;
	}

	public function get( string $carrier_id ): ?YeffoPrint_Tracking_Provider {
		return $this->providers[ $carrier_id ] ?? null;
	}

	public function is_configured( string $carrier_id ): bool {
		$provider = $this->get( $carrier_id );
		return $provider && $provider->is_configured();
	}
}
