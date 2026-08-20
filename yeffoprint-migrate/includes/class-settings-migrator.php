<?php
/**
 * WooCommerce settings: options, shipping zones/methods/locations, and
 * tax rates. Small and stable enough in size that this runs in one
 * shot (no batching needed) — unlike orders/users, a WooCommerce
 * install realistically has hundreds, not tens of thousands, of
 * matching option rows.
 *
 * Import replaces, not merges: options are overwritten key-by-key
 * (the natural behavior for simple key/value settings — no way for
 * two values to coexist), and shipping zones/methods/locations + tax
 * rates are wiped and re-inserted wholesale with their *original*
 * IDs preserved. That ID preservation is deliberate, not an oversight:
 * each shipping method instance's actual configuration (e.g. a flat
 * rate's cost) lives in a separate option keyed by that instance's ID
 * (`woocommerce_flat_rate_{instance_id}_settings`), which rides along
 * automatically in the options sweep under its *original* key — going
 * through WC_Shipping_Zone's normal API would generate fresh instance
 * IDs on the new site and break that linkage, requiring a whole
 * separate ID-remapping pass for no benefit. Wholesale replacement
 * only makes sense for a genuine site-to-site migration (the
 * documented, confirmed-in-the-UI use case) — never call this
 * expecting to merge with an existing site's own zones/rates.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Migrate_Settings_Migrator {

	/**
	 * Option-name prefixes that match `woocommerce_%` but are internal
	 * admin-UI/analytics state, not a "setting" a store owner would
	 * recognize or want carried over (onboarding wizard progress, task-
	 * list dismissals, install timestamps). Excluded so importing this
	 * doesn't leave the new site's WooCommerce Admin dashboard in a
	 * confusing half-onboarded state.
	 */
	private const EXCLUDED_PREFIXES = [
		'woocommerce_admin_',
		'woocommerce_onboarding_',
		'woocommerce_task_list_',
		'woocommerce_setup_',
		'woocommerce_remote_variant_assignment',
	];

	public function export(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce\\_%' OR option_name LIKE 'woocommerce-%' ORDER BY option_name ASC",
			ARRAY_A
		);

		$options = [];
		foreach ( $rows as $row ) {
			if ( $this->is_excluded( $row['option_name'] ) ) {
				continue;
			}
			// maybe_unserialize mirrors what get_option() does — many
			// WC settings are stored as serialized arrays.
			$options[ $row['option_name'] ] = maybe_unserialize( $row['option_value'] );
		}

		return [
			'options'        => $options,
			'shipping_zones' => $this->export_shipping_zones(),
			'tax_rates'      => $this->export_tax_rates(),
		];
	}

	private function is_excluded( string $option_name ): bool {
		foreach ( self::EXCLUDED_PREFIXES as $prefix ) {
			if ( 0 === strpos( $option_name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	private function export_shipping_zones(): array {
		global $wpdb;

		$zones = $wpdb->get_results( "SELECT zone_id, zone_name, zone_order FROM {$wpdb->prefix}woocommerce_shipping_zones", ARRAY_A );
		$locations = $wpdb->get_results( "SELECT zone_id, location_code, location_type, location_name FROM {$wpdb->prefix}woocommerce_shipping_zone_locations", ARRAY_A );
		$methods = $wpdb->get_results( "SELECT zone_id, instance_id, method_id, method_order, is_enabled FROM {$wpdb->prefix}woocommerce_shipping_zone_methods", ARRAY_A );

		return [ 'zones' => $zones, 'locations' => $locations, 'methods' => $methods ];
	}

	private function export_tax_rates(): array {
		global $wpdb;

		$rates = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates", ARRAY_A );
		$locations = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rate_locations", ARRAY_A );

		return [ 'rates' => $rates, 'locations' => $locations ];
	}

	/** @return array{options_written:int, zones_written:int, tax_rates_written:int} */
	public function import( array $data ): array {
		global $wpdb;

		$options_written = 0;
		foreach ( (array) ( $data['options'] ?? [] ) as $name => $value ) {
			$name = (string) $name;
			if ( '' === $name || $this->is_excluded( $name ) ) {
				continue; // Defensive — a hand-edited import file shouldn't be able to smuggle in a non-WC option key.
			}
			if ( 0 !== strpos( $name, 'woocommerce_' ) && 0 !== strpos( $name, 'woocommerce-' ) ) {
				continue;
			}
			update_option( $name, $value );
			$options_written++;
		}

		$zones_written     = $this->import_shipping_zones( (array) ( $data['shipping_zones'] ?? [] ) );
		$tax_rates_written = $this->import_tax_rates( (array) ( $data['tax_rates'] ?? [] ) );

		return [
			'options_written'   => $options_written,
			'zones_written'     => $zones_written,
			'tax_rates_written' => $tax_rates_written,
		];
	}

	private function import_shipping_zones( array $export ): int {
		global $wpdb;

		$zones     = (array) ( $export['zones'] ?? [] );
		$locations = (array) ( $export['locations'] ?? [] );
		$methods   = (array) ( $export['methods'] ?? [] );

		if ( ! $zones && ! $locations && ! $methods ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- these three tables are WooCommerce's own schema with no wrapping API for a wholesale ID-preserving replace; see the class docblock for why that's required here.
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}woocommerce_shipping_zone_methods" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}woocommerce_shipping_zone_locations" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}woocommerce_shipping_zones" );

		foreach ( $zones as $zone ) {
			$wpdb->insert( "{$wpdb->prefix}woocommerce_shipping_zones", [
				'zone_id'    => (int) $zone['zone_id'],
				'zone_name'  => (string) $zone['zone_name'],
				'zone_order' => (int) $zone['zone_order'],
			], [ '%d', '%s', '%d' ] );
		}

		foreach ( $locations as $location ) {
			$wpdb->insert( "{$wpdb->prefix}woocommerce_shipping_zone_locations", [
				'zone_id'        => (int) $location['zone_id'],
				'location_code'  => (string) $location['location_code'],
				'location_type'  => (string) $location['location_type'],
				'location_name'  => (string) ( $location['location_name'] ?? '' ),
			], [ '%d', '%s', '%s', '%s' ] );
		}

		foreach ( $methods as $method ) {
			$wpdb->insert( "{$wpdb->prefix}woocommerce_shipping_zone_methods", [
				'zone_id'      => (int) $method['zone_id'],
				'instance_id'  => (int) $method['instance_id'],
				'method_id'    => (string) $method['method_id'],
				'method_order' => (int) $method['method_order'],
				'is_enabled'   => (int) $method['is_enabled'],
			], [ '%d', '%d', '%s', '%d', '%d' ] );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return count( $zones );
	}

	private function import_tax_rates( array $export ): int {
		global $wpdb;

		$rates     = (array) ( $export['rates'] ?? [] );
		$locations = (array) ( $export['locations'] ?? [] );

		if ( ! $rates && ! $locations ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- same reasoning as import_shipping_zones() above.
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}woocommerce_tax_rate_locations" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}woocommerce_tax_rates" );

		foreach ( $rates as $rate ) {
			// tax_rate_id is preserved (not unset) — tax_rate_locations
			// rows below reference it as a foreign key, so the two
			// tables only stay linked correctly if the ID survives the
			// re-insert intact.
			$rate['tax_rate_id'] = (int) $rate['tax_rate_id'];
			$wpdb->insert( "{$wpdb->prefix}woocommerce_tax_rates", $rate );
		}

		foreach ( $locations as $location ) {
			// location_id itself is never referenced from elsewhere, so
			// it's dropped and left to auto-increment — only tax_rate_id
			// (preserved above) needs to survive.
			unset( $location['location_id'] );
			$wpdb->insert( "{$wpdb->prefix}woocommerce_tax_rate_locations", $location );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return count( $rates );
	}
}
