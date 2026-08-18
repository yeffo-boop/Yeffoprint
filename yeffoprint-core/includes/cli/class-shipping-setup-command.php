<?php
/**
 * Dev-only setup for the shipping methods PROJECT_SPEC §15 specifies:
 * US — USPS Ground Advantage $6, UPS 2nd Day Air $15; International —
 * $25. These are plain WooCommerce Shipping Zones/flat-rate methods
 * ("Configured via WooCommerce, not hard-coded in templates" —
 * PROJECT_SPEC §15) — no custom shipping code exists or is needed.
 * This command exists only so that configuration doesn't have to be
 * clicked through by hand in wp-admin on every fresh setup.
 *
 * `wp yeffoprint setup-shipping` — idempotent: checks for an existing
 * "United States" zone and existing methods on the "Locations not
 * covered by your other zones" zone before adding anything, so
 * re-running it doesn't duplicate methods.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Shipping_Setup_Command {

	public function register(): void {
		\WP_CLI::add_command( 'yeffoprint setup-shipping', [ $this, 'setup' ] );
	}

	/**
	 * ## EXAMPLES
	 *
	 *     wp yeffoprint setup-shipping
	 */
	public function setup(): void {
		if ( ! class_exists( 'WC_Shipping_Zone' ) ) {
			\WP_CLI::error( 'WooCommerce is not active.' );
			return;
		}

		$this->setup_us_zone();
		$this->setup_international_zone();

		\WP_CLI::success( 'Shipping zones configured.' );
	}

	private function setup_us_zone(): void {
		$existing_id = $this->find_zone_id_by_name( 'United States' );

		if ( $existing_id ) {
			\WP_CLI::log( 'United States zone already exists — leaving its methods as-is.' );
			return;
		}

		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( 'United States' );
		$zone->set_zone_order( 0 );
		$zone->add_location( 'US', 'country' );
		$zone->save();

		$this->add_flat_rate( $zone, 'USPS Ground Advantage', 6.00 );
		$this->add_flat_rate( $zone, 'UPS 2nd Day Air', 15.00 );

		\WP_CLI::log( 'Created United States zone with USPS Ground Advantage ($6) and UPS 2nd Day Air ($15).' );
	}

	private function setup_international_zone(): void {
		// Zone 0 is WooCommerce's built-in "Locations not covered by
		// your other zones" catch-all — the natural fit for
		// "International" once the US zone above exists.
		$zone = new \WC_Shipping_Zone( 0 );

		foreach ( $zone->get_shipping_methods() as $method ) {
			if ( 'flat_rate' === $method->id ) {
				\WP_CLI::log( 'The "Locations not covered" zone already has a flat rate method — leaving it as-is.' );
				return;
			}
		}

		$this->add_flat_rate( $zone, 'International Shipping', 25.00 );

		\WP_CLI::log( 'Added a $25 International Shipping flat rate to the "Locations not covered by your other zones" zone.' );
	}

	private function add_flat_rate( \WC_Shipping_Zone $zone, string $title, float $cost ): void {
		$instance_id = $zone->add_shipping_method( 'flat_rate' );
		$method       = \WC_Shipping_Zones::get_shipping_method( $instance_id );

		if ( ! $method ) {
			\WP_CLI::warning( "Could not configure shipping method: {$title}" );
			return;
		}

		$settings            = $method->instance_settings;
		$settings['title']   = $title;
		$settings['cost']    = (string) $cost;
		update_option( $method->get_instance_option_key(), $settings );
	}

	private function find_zone_id_by_name( string $name ): int {
		foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
			if ( $zone['zone_name'] === $name ) {
				return (int) $zone['id'];
			}
		}

		return 0;
	}
}
