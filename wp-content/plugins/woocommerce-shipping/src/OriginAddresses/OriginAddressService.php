<?php

namespace Automattic\WCShipping\OriginAddresses;

use Automattic\WCShipping\Connect\WC_Connect_Options;
use Automattic\WCShipping\Utils;

class OriginAddressService {

	public function get_origin_addresses() {
		$addresses = array_values(
			WC_Connect_Options::get_option( 'origin_addresses', array( $this->get_store_details() ) )
		);

		// Make sure a default address is selected.
		$defaultAddress = array_filter(
			$addresses,
			function ( $address ) {
				return isset( $address['default_address'] ) && $address['default_address'];
			}
		);
		if ( empty( $defaultAddress ) ) {
			$addresses[0]['default_address'] = true;
		}

		// Make sure a default return address is selected.
		$defaultReturnAddress = array_filter(
			$addresses,
			function ( $address ) {
				return isset( $address['default_return_address'] ) && $address['default_return_address'];
			}
		);
		if ( empty( $defaultReturnAddress ) ) {
			$addresses[0]['default_return_address'] = true;
		}

		// Ensure verified addresses have a non-empty email field.
		// Skip this check for approved addresses (CIAB), as they go through a separate approval flow.
		foreach ( $addresses as &$address ) {
			if ( ! empty( $address['is_verified'] ) && empty( $address['email'] ) && empty( $address['is_approved'] ) ) {
				$address['is_verified'] = false;
			}
		}

		return $addresses;
	}

	public function update_origin_addresses( $address ) {
		$origin_addresses  = $this->get_origin_addresses();
		$sanitized_address = array_map(
			function ( $value ) {
				// The mapping used in wc_clean converts boolean values to 1 or 0, so we need to check for that
				return is_bool( $value ) ? $value : wc_clean( $value );
			},
			$address
		);
		// If the new address is set as default, remove default_address from all existing addresses
		if ( isset( $address['default_address'] ) && $address['default_address'] ) {
			foreach ( $origin_addresses as &$origin_address ) {
				unset( $origin_address['default_address'] );
			}
		}

		// If the new address is set as default return address, remove default_return_address from all existing addresses
		if ( isset( $address['default_return_address'] ) && $address['default_return_address'] ) {
			foreach ( $origin_addresses as &$origin_address ) {
				unset( $origin_address['default_return_address'] );
			}
		}

		$address_exists = array_search( $address['id'], array_column( $origin_addresses, 'id' ) );

		if ( $address_exists !== false && ! empty( $address['id'] ) ) {
			$origin_addresses[ $address_exists ] = $sanitized_address;
		} else {
			$sanitized_address['id'] = ! empty( $sanitized_address['id'] ) ? $sanitized_address['id'] : uniqid();
			$origin_addresses[]      = $sanitized_address;
		}

		WC_Connect_Options::update_option( 'origin_addresses', $origin_addresses );

		if ( isset( $sanitized_address['id'] ) && 'store_details' === strval( $sanitized_address['id'] ) ) {
			$this->maybe_clear_main_origin_store_address_drift_after_store_details_save( $sanitized_address );
		}

		return $sanitized_address;
	}

	public function delete_origin_address( $id ) {
		// get all addresses
		$origin_addresses = $this->get_origin_addresses();

		// if there's only one address, do not delete it
		if ( count( $origin_addresses ) <= 1 ) {
			return $origin_addresses;
		}

		// if an address with the same `id` field exists, delete it...
		foreach ( $origin_addresses as $index => $origin_address ) {
			if ( strval( $origin_address['id'] ) === strval( $id ) ) {
				unset( $origin_addresses[ $index ] );
				break;
			}
		}

		// save the updated addresses
		WC_Connect_Options::update_option( 'origin_addresses', $origin_addresses );
		return $origin_addresses;
	}

	/**
	 * Get the default origin address for outbound shipments.
	 *
	 * @return array|null
	 */
	public function get_default_outbound_address() {
		$addresses = $this->get_origin_addresses();
		foreach ( $addresses as $address ) {
			if ( isset( $address['default_address'] ) && $address['default_address'] ) {
				return $address;
			}
		}
		return null;
	}

	/**
	 * Get the default return address for return shipments.
	 *
	 * @return array|null
	 */
	public function get_default_return_address() {
		$addresses = $this->get_origin_addresses();
		foreach ( $addresses as $address ) {
			if ( isset( $address['default_return_address'] ) && $address['default_return_address'] ) {
				return $address;
			}
		}
		return null;
	}

	/**
	 * Seed the origin addresses with the store address on first run.
	 *
	 * This is a one-time operation: if origin addresses have already been
	 * persisted, the store address update is ignored so that merchant
	 * edits to their ship-from address are never silently overwritten.
	 *
	 * After the initial seed, {@see on_woocommerce_store_address_option_updated()}
	 * persists the wcshipping_main_origin_store_address_drift option so
	 * {@see is_main_origin_address_in_sync_with_store()} only reflects
	 * store-address-initiated drift (not sender-only edits).
	 *
	 * @return void
	 */
	public function sync_origin_addresses_with_woocommerce_store_address() {
		$existing = WC_Connect_Options::get_option( 'origin_addresses', false );
		if ( false !== $existing ) {
			return;
		}

		$store_address = $this->get_store_details();
		$this->update_origin_addresses( $store_address );
	}

	/**
	 * Runs after WooCommerce store address options change. Seeds origin addresses
	 * on first run, then either auto-syncs or tracks drift depending on context.
	 *
	 * In the standard WooCommerce admin (non-CIAB), the store-seeded sender
	 * address is automatically kept in sync with the store address so merchants
	 * always ship from their current store location without manual intervention.
	 *
	 * In CIAB (Next Admin), drift is only persisted so the admin UI can surface
	 * a notice and let the merchant decide whether to sync.
	 *
	 * General settings may update several options in one request; each fires this
	 * hook. {@see persist_main_origin_store_address_drift_flag()} skips writing
	 * when the computed drift value is unchanged to avoid redundant option updates.
	 *
	 * @param mixed  $old_value Previous option value.
	 * @param mixed  $value     New option value.
	 * @param string $option   Option name.
	 * @return void
	 */
	public function on_woocommerce_store_address_option_updated( $old_value, $value, $option ) {
		if ( Utils::is_next() ) {
			$this->sync_origin_addresses_with_woocommerce_store_address();
			$this->persist_main_origin_store_address_drift_flag();
		} else {
			$this->sync_store_details_address();
		}
	}

	/**
	 * Check whether the UI should treat the main sender as in sync with the store address.
	 *
	 * When {@see persist_main_origin_store_address_drift_flag()} has run, reads the
	 * persisted drift flag so a manual sender edit alone does not surface the
	 * "store address changed" warning. When the flag is unset (legacy installs),
	 * falls back to comparing live store settings with the store_details origin.
	 *
	 * @return bool True when no store-initiated drift warning should be shown.
	 */
	public function is_main_origin_address_in_sync_with_store(): bool {
		$drift = WC_Connect_Options::get_option( 'main_origin_store_address_drift', '' );
		if ( 'yes' === $drift ) {
			return false;
		}
		if ( 'no' === $drift ) {
			return true;
		}

		return $this->addresses_physically_match_store_and_store_details();
	}

	/**
	 * Whether the store_details origin address matches current WooCommerce store address fields.
	 *
	 * When the merchant has removed or renamed the store-seeded sender row,
	 * there is nothing left to sync back to — treat that as "in sync" so the
	 * UI does not nag with an unactionable warning (the sync REST endpoint
	 * would 404 because {@see sync_store_details_address()} returns null).
	 *
	 * @return bool
	 */
	private function addresses_physically_match_store_and_store_details(): bool {
		$store_seeded_address = $this->find_store_details_origin_address();
		if ( ! $store_seeded_address ) {
			return true;
		}

		$store_details = $this->get_store_details();

		return $this->compute_address_hash( $store_seeded_address ) === $this->compute_address_hash( $store_details );
	}

	/**
	 * @return array|null The origin address with id store_details, or null.
	 */
	private function find_store_details_origin_address(): ?array {
		foreach ( $this->get_origin_addresses() as $address ) {
			if ( isset( $address['id'] ) && 'store_details' === strval( $address['id'] ) ) {
				return $address;
			}
		}

		return null;
	}

	/**
	 * Persists drift after WooCommerce general/store address options change.
	 *
	 * @return void
	 */
	private function persist_main_origin_store_address_drift_flag(): void {
		$store_seeded_address = $this->find_store_details_origin_address();
		if ( ! $store_seeded_address ) {
			$current = WC_Connect_Options::get_option( 'main_origin_store_address_drift', '' );
			if ( '' !== $current ) {
				WC_Connect_Options::delete_option( 'main_origin_store_address_drift' );
			}
			return;
		}

		$store_details = $this->get_store_details();
		$match         = $this->compute_address_hash( $store_seeded_address ) === $this->compute_address_hash( $store_details );
		$new_flag      = $match ? 'no' : 'yes';
		$current       = WC_Connect_Options::get_option( 'main_origin_store_address_drift', '' );

		if ( $current === $new_flag ) {
			return;
		}

		WC_Connect_Options::update_option( 'main_origin_store_address_drift', $new_flag );
	}

	/**
	 * After the store_details sender is saved, clear drift only when it matches the store again.
	 * Never sets drift to yes from a sender edit alone.
	 *
	 * @param array $saved_store_details_address Saved store_details row.
	 * @return void
	 */
	private function maybe_clear_main_origin_store_address_drift_after_store_details_save( array $saved_store_details_address ): void {
		$store_snapshot = $this->get_store_details();
		if ( $this->compute_address_hash( $saved_store_details_address ) !== $this->compute_address_hash( $store_snapshot ) ) {
			return;
		}

		$current = WC_Connect_Options::get_option( 'main_origin_store_address_drift', '' );
		// Only clear persisted drift (yes -> no) when the merchant saved a matching
		// store_details row after being out of sync. Do not set 'no' while drift is
		// still unset: initial seed must keep '' so is_main_origin_address_in_sync_with_store()
		// can detect WooCommerce store-only changes via live comparison until the
		// store-address option hook runs and persist_main_origin_store_address_drift_flag() runs.
		if ( 'yes' !== $current ) {
			return;
		}

		WC_Connect_Options::update_option( 'main_origin_store_address_drift', 'no' );
	}

	/**
	 * Mark the main origin sender as in sync with the store address.
	 *
	 * Used when the merchant intentionally updates the store-seeded sender from
	 * the store-address drift notice flow and confirms the change via the editor.
	 *
	 * @return void
	 */
	public function mark_main_origin_store_address_in_sync(): void {
		if ( 'no' !== WC_Connect_Options::get_option( 'main_origin_store_address_drift', '' ) ) {
			WC_Connect_Options::update_option( 'main_origin_store_address_drift', 'no' );
		}
	}

	/**
	 * Compute an MD5 hash over the physical location fields of an address.
	 *
	 * Address lines (address_1, address_2) are combined before hashing so that
	 * different splits of the same street address still produce the same hash.
	 *
	 * @param array $address Address array.
	 * @return string 32-character hex MD5 hash.
	 */
	private function compute_address_hash( array $address ): string {
		$combined_address = trim( ( $address['address_1'] ?? '' ) . ' ' . ( $address['address_2'] ?? '' ) );
		$fields           = array(
			'address'  => $combined_address,
			'city'     => $address['city'] ?? '',
			'state'    => $address['state'] ?? '',
			'postcode' => $address['postcode'] ?? '',
			'country'  => $address['country'] ?? '',
		);

		return md5( wp_json_encode( $fields ) );
	}

	/**
	 * Return the current WooCommerce store address as a single formatted string.
	 *
	 * Example: "456 Oak Ave, Chicago, IL 60601"
	 *
	 * @return string
	 */
	public function get_formatted_store_address(): string {
		$details = $this->get_store_details();
		$parts   = array_filter(
			array(
				$details['address_1'],
				$details['city'],
				trim( $details['state'] . ' ' . $details['postcode'] ),
			)
		);

		return implode( ', ', $parts );
	}

	/**
	 * Return a draft of the store-seeded sender row with the current store
	 * address applied to its physical fields, without persisting anything.
	 *
	 * @return array|null Draft address array, or null when no store-seeded address exists.
	 */
	public function get_store_details_origin_address_draft(): ?array {
		$store_seeded_address = $this->find_store_details_origin_address();
		if ( ! $store_seeded_address ) {
			return null;
		}

		$store_details = $this->get_store_details();

		$physical_overrides = array_intersect_key(
			$store_details,
			array_flip( self::PHYSICAL_ADDRESS_FIELDS )
		);

		return array_merge( $store_seeded_address, $physical_overrides );
	}

	/**
	 * Physical address fields copied from the store settings when syncing the
	 * store-seeded sender. Non-address fields (name, company, email, phone,
	 * first/last name, verification flags, default flags) on the existing row
	 * are preserved so merchant customisations are not wiped out.
	 */
	private const PHYSICAL_ADDRESS_FIELDS = array(
		'address_1',
		'address_2',
		'city',
		'state',
		'postcode',
		'country',
	);

	/**
	 * Overwrite the store-seeded origin address with the current WooCommerce store address.
	 *
	 * Finds the origin address whose id is "store_details" and replaces only
	 * its physical address fields with the current store settings, preserving
	 * everything else (name, company, email, phone, default flags, etc.).
	 *
	 * @return array|null The updated address array, or null when no store-seeded address exists.
	 */
	public function sync_store_details_address(): ?array {
		$origin_addresses = $this->get_origin_addresses();
		$store_details    = $this->get_store_details();

		$physical_overrides = array_intersect_key(
			$store_details,
			array_flip( self::PHYSICAL_ADDRESS_FIELDS )
		);

		foreach ( $origin_addresses as $index => $address ) {
			if ( isset( $address['id'] ) && 'store_details' === $address['id'] ) {
				$origin_addresses[ $index ] = array_merge( $address, $physical_overrides );
				WC_Connect_Options::update_option( 'origin_addresses', $origin_addresses );
				if ( 'no' !== WC_Connect_Options::get_option( 'main_origin_store_address_drift', '' ) ) {
					WC_Connect_Options::update_option( 'main_origin_store_address_drift', 'no' );
				}
				return $origin_addresses[ $index ];
			}
		}

		return null;
	}

	/**
	 * Returns the Store's address to be included in the shipping settings script parameters
	 *
	 * @return mixed
	 */
	private function get_store_details() {
		$address   = get_option( 'woocommerce_store_address', '' );
		$address_2 = get_option( 'woocommerce_store_address_2', '' );
		$city      = get_option( 'woocommerce_store_city', '' );
		$postcode  = get_option( 'woocommerce_store_postcode', '' );

		$raw_country   = get_option( 'woocommerce_default_country', '' );
		$split_country = explode( ':', $raw_country );

		$country = isset( $split_country[0] ) ? $split_country[0] : '';
		$state   = isset( $split_country[1] ) ? $split_country[1] : '';

		$store_name = get_option( 'blogname', '' );
		$email      = get_option( 'admin_email', '' );

		$store_details = array(
			'id'                     => 'store_details',
			'name'                   => 'Store Address',
			'company'                => $store_name,
			'address_1'              => trim( $address . ' ' . $address_2 ),
			'address_2'              => '',
			'city'                   => $city,
			'state'                  => $state,
			'postcode'               => $postcode,
			'country'                => $country,
			'email'                  => $email,
			'phone'                  => '',
			'first_name'             => '',
			'last_name'              => '',
			'is_verified'            => false,
			'default_return_address' => true,
		);

		return $store_details;
	}
}
