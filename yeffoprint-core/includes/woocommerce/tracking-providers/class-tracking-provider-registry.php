<?php
/**
 * Maps a WC Shipping `carrier_id` (class-order-tracking.php) to the
 * provider that can look up live events for it. One place to ask "do we
 * have working credentials for this carrier yet" — the REST controller
 * doesn't need to know how each provider is configured, just whether it
 * is.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Tracking_Provider_Registry {

	/** @var array<string, YeffoPrint_Tracking_Provider> */
	private array $providers;

	public function __construct() {
		$this->providers = [
			'usps' => new YeffoPrint_Usps_Tracking_Provider(),
			'ups'  => new YeffoPrint_Ups_Tracking_Provider(),
		];
	}

	public function get( string $carrier_id ): ?YeffoPrint_Tracking_Provider {
		return $this->providers[ $carrier_id ] ?? null;
	}

	public function is_configured( string $carrier_id ): bool {
		$provider = $this->get( $carrier_id );
		return $provider && $provider->is_configured();
	}
}
