<?php
/**
 * Service that suggests a single shipping package for each order in a bulk
 * label-purchase request, using the merchant's configured packages and the
 * `woocommerce/box-packer` library.
 *
 * @package Automattic\WCShipping\PackageAssignment
 */

namespace Automattic\WCShipping\PackageAssignment;

use Automattic\WCShipping\Connect\WC_Connect_Logger;
use Automattic\WCShipping\Connect\WC_Connect_Service_Schemas_Store;
use Automattic\WCShipping\Connect\WC_Connect_Service_Settings_Store;
use WooCommerce\BoxPacker\WC_Boxpack;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a suggested package for each order in a bulk request.
 */
class PackageAssignmentService {

	/** Status: a single configured package can hold every shippable item in the order. */
	const STATUS_FIT = 'fit';
	/** Status: items would need more than one package, or a single item is too large for any configured package. */
	const STATUS_NEEDS_SPLIT = 'needs_split';
	/** Status: at least one shippable product is missing length, width, height, or weight. */
	const STATUS_MISSING_DIMENSIONS = 'missing_dimensions';
	/** Status: the store has no configured packages to choose from. */
	const STATUS_NO_PACKAGES = 'no_packages';
	/** Status: the order has no items requiring shipping (e.g. all items are virtual or downloadable). */
	const STATUS_NO_SHIPPABLE_ITEMS = 'no_shippable_items';
	/** Status: an unexpected condition prevented the service from producing a suggestion. */
	const STATUS_ERROR = 'error';

	/**
	 * Settings store providing the merchant's package configuration.
	 *
	 * @var WC_Connect_Service_Settings_Store
	 */
	private $settings_store;

	/**
	 * Schemas store used to resolve enabled predefined package IDs against
	 * the cached predefined-packages schema.
	 *
	 * @var WC_Connect_Service_Schemas_Store
	 */
	private $service_schemas_store;

	/**
	 * Logger used to record skipped orders and unexpected packer failures.
	 *
	 * @var WC_Connect_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param WC_Connect_Service_Settings_Store $settings_store        Settings store.
	 * @param WC_Connect_Service_Schemas_Store  $service_schemas_store Schemas store.
	 * @param WC_Connect_Logger                 $logger                Logger.
	 */
	public function __construct(
		WC_Connect_Service_Settings_Store $settings_store,
		WC_Connect_Service_Schemas_Store $service_schemas_store,
		WC_Connect_Logger $logger
	) {
		$this->settings_store        = $settings_store;
		$this->service_schemas_store = $service_schemas_store;
		$this->logger                = $logger;
	}

	/**
	 * Suggest a package for each order id.
	 *
	 * @param int[] $order_ids Order ids to process.
	 *
	 * @return array<int, array{status: string, package_id?: string, package_name?: string, service_id?: string, reason?: string}> Map of order id to assignment result. `service_id` is only set when the picked package is a predefined carrier package.
	 */
	public function assign_for_orders( array $order_ids ): array {
		// Read the canonical sources once per batch. `assign_for_orders()`
		// uses them for the "no packages configured" fast-path and then
		// hands them to `build_configured_packages()`, which used to read
		// the same options a second time. One read per option per batch is
		// enough and avoids a small race if the option changes mid-request.
		$custom_packages    = $this->settings_store->get_packages();
		$enabled_predefined = $this->settings_store->get_predefined_packages();

		// Schema cache lookup is the only remaining bulk read tied to this
		// batch. Fetch it once here and pass it into the helper instead of
		// re-fetching inside `build_configured_packages()`.
		$schema = $this->service_schemas_store->get_predefined_packages_schema();

		// Mirror the canonical "no packages configured" check used by
		// EligibilityRESTController so the fast-path response matches what
		// other label-flow gates already report. We must not treat schema
		// definitions cached on the server as "configured packages" here.
		// `get_predefined_packages()` returns a map of service id to id list
		// (e.g. `[ 'usps' => [], 'fedex' => [] ]`), which is non-empty as a
		// map even when every inner list is empty. The helper walks the
		// inner lists so the fast-path correctly recognizes "zero enabled
		// predefined ids".
		$has_enabled_predef = self::has_enabled_predefined( $enabled_predefined );

		$has_no_packages = empty( $custom_packages ) && ! $has_enabled_predef;

		if ( $has_no_packages ) {
			$no_packages = array(
				'status' => self::STATUS_NO_PACKAGES,
				'reason' => __( 'No packages are configured for this store.', 'woocommerce-shipping' ),
			);

			$results = array();
			foreach ( $order_ids as $order_id ) {
				$results[ $order_id ] = $no_packages;
			}
			return $results;
		}

		// Build the normalized package list once for the whole batch. The
		// per-order method uses this list verbatim, so a 25-order batch
		// walks the schema, parses dimensions, and applies the type filter
		// only once instead of N times.
		$built                     = $this->build_configured_packages(
			is_array( $custom_packages ) ? $custom_packages : array(),
			is_array( $enabled_predefined ) ? $enabled_predefined : array(),
			$schema
		);
		$configured_packages       = $built['packages'];
		$had_box_candidate         = $built['had_box_candidate'];
		$had_unresolved_predefined = $built['had_unresolved_predefined'];
		$results                   = array();

		if ( empty( $configured_packages ) ) {
			// Two empty-list shapes need different surfaces. If the merchant
			// configured at least one `type === box` candidate but every box
			// failed to parse/resolve, surface STATUS_ERROR (the fix is to
			// repair the data, not "add a package"). If the only candidates
			// were non-box types filtered out for MVP, surface
			// STATUS_NO_PACKAGES with a reason that points the merchant at
			// the missing box-type entry: the fast-path above has already
			// handled the "nothing saved at all" case, so reaching here with
			// no box candidate means everything saved was an envelope or
			// other non-box type and adding another non-box one would not
			// help.
			$has_unusable_configured_packages = $had_box_candidate || $had_unresolved_predefined;
			if ( $has_unusable_configured_packages ) {
				$reason = __( 'All configured packages have invalid dimensions or could not be resolved.', 'woocommerce-shipping' );
			} else {
				$reason = __( 'No box-type packages are configured. Add a box-type package in Settings → Shipping → Packages.', 'woocommerce-shipping' );
			}

			$status = $has_unusable_configured_packages ? self::STATUS_ERROR : self::STATUS_NO_PACKAGES;

			foreach ( $order_ids as $order_id ) {
				$results[ $order_id ] = array(
					'status' => $status,
					'reason' => $reason,
				);
			}
			return $results;
		}

		foreach ( $order_ids as $order_id ) {
			$results[ $order_id ] = $this->assign_for_order( (int) $order_id, $configured_packages );
		}

		return $results;
	}

	/**
	 * Suggest a package for a single order.
	 *
	 * @param int   $order_id            Order id.
	 * @param array $configured_packages Normalized merchant package list produced by `build_configured_packages()`. Built once per batch in `assign_for_orders()` and passed through verbatim, so this method does not re-walk the schema.
	 *
	 * @return array{status: string, package_id?: string, package_name?: string, service_id?: string, reason?: string} `service_id` is only set when the picked package is a predefined carrier package.
	 */
	private function assign_for_order( int $order_id, array $configured_packages ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			$this->logger->log(
				sprintf( 'Auto-assign skipped order: not found (order_id=%d)', $order_id ),
				__CLASS__
			);
			return array(
				'status' => self::STATUS_ERROR,
				'reason' => __( 'Order not found.', 'woocommerce-shipping' ),
			);
		}

		$shippable_items = $this->get_shippable_items( $order );
		if ( empty( $shippable_items ) ) {
			return array(
				'status' => self::STATUS_NO_SHIPPABLE_ITEMS,
				'reason' => __( 'Order has no items that need shipping.', 'woocommerce-shipping' ),
			);
		}

		$missing = $this->find_item_with_invalid_metrics( $shippable_items );
		if ( null !== $missing ) {
			return array(
				'status' => self::STATUS_MISSING_DIMENSIONS,
				'reason' => sprintf(
					/* translators: %s: product name */
					__( 'Product "%s" is missing length, width, height, or weight.', 'woocommerce-shipping' ),
					$missing
				),
			);
		}

		$store_dim_unit    = get_option( 'woocommerce_dimension_unit', 'cm' );
		$store_weight_unit = get_option( 'woocommerce_weight_unit', 'kg' );

		$boxpack = ( new WC_Boxpack( $store_dim_unit, $store_weight_unit, 'dvdoug' ) )->get_packer();

		foreach ( $configured_packages as $package ) {
			$box = $boxpack->add_box(
				(float) $package['length'],
				(float) $package['width'],
				(float) $package['height'],
				(float) ( $package['box_weight'] ?? 0 )
			);
			$box->set_max_weight( (float) ( $package['max_weight'] ?? 0 ) );
			$box->set_id( (string) $package['id'] );
		}

		foreach ( $shippable_items as $item_data ) {
			$product  = $item_data['product'];
			$quantity = $item_data['quantity'];
			$boxpack->add_item(
				(float) $product->get_length(),
				(float) $product->get_width(),
				(float) $product->get_height(),
				(float) $product->get_weight(),
				(float) $product->get_price(),
				array(),
				$quantity
			);
		}

		try {
			$packed = $this->pack_with_boxpack( $boxpack, $order_id );
		} catch ( \Throwable $e ) {
			$this->logger->log(
				sprintf(
					'Auto-assign packer threw %s for order_id=%d: %s',
					get_class( $e ),
					$order_id,
					$e->getMessage()
				),
				__CLASS__
			);
			return array(
				'status' => self::STATUS_ERROR,
				'reason' => __( 'An unexpected error occurred while suggesting a package.', 'woocommerce-shipping' ),
			);
		}

		$count = count( $packed );

		if ( 0 === $count ) {
			$this->logger->log(
				sprintf( 'Auto-assign produced no packages (order_id=%d)', $order_id ),
				__CLASS__
			);
			return array(
				'status' => self::STATUS_ERROR,
				'reason' => __( 'No packages were produced by the packer.', 'woocommerce-shipping' ),
			);
		}

		// InfalliblePacker marks oversize items via the unpacked property on the
		// emitted Package. If any result is unpacked, no configured box can hold
		// at least one item. Surface this as needs_split rather than a misleading
		// fit with an empty package_id.
		foreach ( $packed as $pkg ) {
			if ( ! empty( $pkg->unpacked ) ) {
				return array(
					'status' => self::STATUS_NEEDS_SPLIT,
					'reason' => __( 'An item is too large for any configured package.', 'woocommerce-shipping' ),
				);
			}
		}

		if ( $count >= 2 ) {
			return array(
				'status' => self::STATUS_NEEDS_SPLIT,
				'reason' => __( "This order's items don't all fit in one package. Pick a larger package, or split the order into multiple shipments.", 'woocommerce-shipping' ),
			);
		}

		$picked_internal_id = (string) $packed[0]->id;
		$package            = null;
		foreach ( $configured_packages as $candidate ) {
			if ( (string) $candidate['id'] === $picked_internal_id ) {
				$package = $candidate;
				break;
			}
		}

		if ( null === $package || empty( $package['name'] ) ) {
			$this->logger->log(
				sprintf(
					'Auto-assign packer returned unknown package id "%s" for order_id=%d',
					$picked_internal_id,
					$order_id
				),
				__CLASS__
			);
			return array(
				'status' => self::STATUS_ERROR,
				'reason' => __( 'An unexpected error occurred while suggesting a package.', 'woocommerce-shipping' ),
			);
		}

		$result = array(
			'status'       => self::STATUS_FIT,
			'package_id'   => (string) $package['package_id'],
			'package_name' => (string) $package['name'],
		);

		// Predefined entries carry the carrier scope so REST clients can
		// disambiguate carriers that share a predefined id (e.g. both USPS
		// and FedEx publishing "medium_box"). Custom packages have no
		// service scope, so the field is omitted there.
		if ( ! empty( $package['service_id'] ) ) {
			$result['service_id'] = (string) $package['service_id'];
		}

		return $result;
	}

	/**
	 * Run the boxpacker against the configured boxes/items and return the
	 * resulting list of `WooCommerce\BoxPacker\Package` objects. Wrapped in
	 * an output buffer because the dvdoug packer's
	 * `Abstract_Packer::maybe_display_packing_error()` echoes
	 * "Packing error: ..." straight to stdout for any user with
	 * `manage_options`, which would corrupt the JSON REST response.
	 *
	 * The captured stdout is logged (with the order id for context) instead
	 * of being discarded, so production packing diagnostics survive into the
	 * Connect Server logs without leaking into the REST body.
	 *
	 * Marked `protected` so tests can override it to simulate an unexpected
	 * exception thrown deep in the packer; the production implementation
	 * intentionally does its own error handling so the caller's `\Throwable`
	 * catch only fires on truly unexpected fatal errors.
	 *
	 * @param mixed $boxpack  The packer instance returned by `WC_Boxpack::get_packer()`.
	 * @param int   $order_id Order id, used to tag any captured packer stdout.
	 * @return array
	 * @throws \Throwable Re-thrown after capturing and logging any stdout the packer emitted before failing.
	 */
	protected function pack_with_boxpack( $boxpack, int $order_id = 0 ): array {
		ob_start();
		try {
			$boxpack->pack();
			$packages = $boxpack->get_packages();
		} catch ( \Throwable $e ) {
			$packer_output = (string) ob_get_clean();
			if ( '' !== trim( $packer_output ) ) {
				$this->logger->log(
					sprintf(
						'Auto-assign packer emitted stdout for order_id=%d before throwing: %s',
						$order_id,
						trim( $packer_output )
					),
					__CLASS__
				);
			}
			throw $e;
		}

		$packer_output = (string) ob_get_clean();
		if ( '' !== trim( $packer_output ) ) {
			$this->logger->log(
				sprintf(
					'Auto-assign packer emitted stdout for order_id=%d: %s',
					$order_id,
					trim( $packer_output )
				),
				__CLASS__
			);
		}

		return $packages;
	}

	/**
	 * Filter order line items to those that need shipping.
	 *
	 * @param WC_Order $order Order instance.
	 *
	 * @return array<int, array{product: \WC_Product, quantity: int}>
	 */
	private function get_shippable_items( WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product || ! $product->needs_shipping() ) {
				continue;
			}
			$items[] = array(
				'product'  => $product,
				'quantity' => (int) $item->get_quantity(),
			);
		}
		return $items;
	}

	/**
	 * Build a normalized list of packages the merchant has configured for
	 * label purchase: custom packages saved against the store and predefined
	 * carrier packages explicitly enabled in the store's predefined-package
	 * settings.
	 *
	 * Returns a flat list of `{ id, name, length, width, height, box_weight,
	 * max_weight }` ready to feed into `WC_Boxpack`. Packages whose dimension
	 * string cannot be parsed are skipped (logged via WC_Connect_Logger)
	 * rather than silently coerced to 0x0x0 boxes that would fit anything in
	 * the packer.
	 *
	 * Crucially this does NOT use `WC_Connect_Service_Settings_Store::get_package_lookup()`
	 * because that helper returns every predefined definition from the cached
	 * schema regardless of whether the merchant enabled it. Auto-assign must
	 * only consider packages the merchant actually picked.
	 *
	 * The `id` field is a stable internal id used to look up the picked box
	 * after packing: for custom packages it is `"custom:{package_id}"`, for
	 * predefined packages it is `"predef:{service_id}:{package_id}"`, so two
	 * carriers sharing a predefined id (e.g. both USPS and FedEx defining
	 * `"medium_box"`) cannot collide and a custom id cannot accidentally
	 * shadow a predefined id either. The merchant-facing `package_id`
	 * returned to clients is the original saved id, kept in `package_id`.
	 * Predefined entries also carry `service_id` so callers can scope the
	 * picked id back to its carrier; custom entries leave it null because
	 * custom packages have no service scope.
	 *
	 * Candidates are filtered to `type === 'box'` for MVP. The dvdoug packer
	 * is a rigid-box model and produces false fit results for envelopes,
	 * paks, tubes, etc. Non-box types are skipped silently with a log entry
	 * via WC_Connect_Logger so we can audit the gap later.
	 * TODO(WOOSHIP-2164+): re-add non-box types with type-specific handling.
	 *
	 * The return value also carries a `had_box_candidate` flag: true if the
	 * merchant configured at least one entry whose `type` was `'box'`,
	 * regardless of whether its dimensions later parsed cleanly. The caller
	 * uses this to distinguish "no packages configured for this store"
	 * (true MVP gap) from "boxes were configured but unparseable"
	 * (a data-repair issue).
	 *
	 * `had_unresolved_predefined` is true when the merchant enabled at least
	 * one predefined package id that cannot be found in the cached schema.
	 * That is not the same as "no packages configured"; the saved selection
	 * exists, but the schema needs to be refreshed or repaired.
	 *
	 * The predefined-packages schema is fetched once per batch in
	 * `assign_for_orders()` and passed in via `$schema`.
	 *
	 * The schema can arrive as either nested `stdClass` objects or
	 * associative arrays depending on how the upstream payload was decoded.
	 * Each level is cast to `(array)` before reading so the loop produces
	 * the same candidate set in both shapes. The cast is a no-op when the
	 * value is already an array, and on an object it returns the public
	 * property values keyed by name, which matches the existing object-
	 * access path verbatim for the test fixtures.
	 *
	 * @param array             $custom_packages    Custom packages list as returned by `WC_Connect_Service_Settings_Store::get_packages()`.
	 * @param array             $enabled_predefined Map of service id to enabled predefined ids as returned by `WC_Connect_Service_Settings_Store::get_predefined_packages()`.
	 * @param array|object|null $schema             Predefined packages schema as returned by `WC_Connect_Service_Schemas_Store::get_predefined_packages_schema()`.
	 *
	 * @return array{packages: array<int, array{id: string, package_id: string, service_id: ?string, name: string, length: float, width: float, height: float, box_weight: float, max_weight: float}>, had_box_candidate: bool, had_unresolved_predefined: bool}
	 */
	private function build_configured_packages( array $custom_packages, array $enabled_predefined, $schema ): array {
		$packages                  = array();
		$had_box_candidate         = false;
		$had_unresolved_predefined = false;
		// Track internal ids that have already been registered so a schema
		// listing the same predefined id under multiple groups for one
		// service (or any unexpected collision with a custom id) does not
		// produce two boxes that share an internal id and create
		// iteration-order-dependent results.
		$added_internal = array();

		// Custom packages: dimensions stored as "L x W x H" with camelCase
		// boxWeight/maxWeight. See `Package::to_array()`.
		foreach ( $custom_packages as $custom ) {
			$custom_id   = isset( $custom['id'] ) ? (string) $custom['id'] : '(unknown)';
			$custom_type = isset( $custom['type'] ) ? (string) $custom['type'] : '';
			if ( 'box' !== $custom_type ) {
				$this->logger->log(
					sprintf(
						'Auto-assign skipped custom package "%s": type "%s" is not a box (MVP only supports rigid boxes)',
						$custom_id,
						$custom_type
					),
					__CLASS__
				);
				continue;
			}

			$had_box_candidate = true;

			$dims = $this->parse_dimensions( isset( $custom['dimensions'] ) ? (string) $custom['dimensions'] : '' );
			if ( null === $dims ) {
				$this->logger->log(
					sprintf(
						'Auto-assign skipped custom package "%s": malformed dimensions "%s"',
						$custom_id,
						isset( $custom['dimensions'] ) ? (string) $custom['dimensions'] : ''
					),
					__CLASS__
				);
				continue;
			}

			$internal_id = sprintf( 'custom:%s', $custom_id );
			if ( isset( $added_internal[ $internal_id ] ) ) {
				continue;
			}

			$packages[]                     = array(
				'id'         => $internal_id,
				'package_id' => $custom_id,
				'service_id' => null,
				'name'       => isset( $custom['name'] ) ? (string) $custom['name'] : '',
				'length'     => $dims['length'],
				'width'      => $dims['width'],
				'height'     => $dims['height'],
				'box_weight' => isset( $custom['boxWeight'] ) ? (float) $custom['boxWeight'] : 0.0,
				'max_weight' => isset( $custom['maxWeight'] ) ? (float) $custom['maxWeight'] : 0.0,
			);
			$added_internal[ $internal_id ] = true;
		}

		// Predefined packages: only those the merchant enabled, resolved per
		// service. Two carriers can ship a predefined id with the same string
		// (e.g. "medium_box") but different dimensions; matching by id alone
		// would let one carrier's box leak into the other carrier's slot.
		// Walk the schema service-by-service so each (service_id, package_id)
		// pair is resolved against its own definitions.
		if ( ! empty( $enabled_predefined ) ) {
			if ( empty( $schema ) ) {
				$had_unresolved_predefined = self::has_enabled_predefined( $enabled_predefined );
			} else {
				// Cast to array up front so both `stdClass` and associative
				// shapes share one access path.
				$schema_arr = (array) $schema;
				foreach ( $enabled_predefined as $service_id => $ids ) {
					if ( ! is_array( $ids ) || empty( $ids ) ) {
						continue;
					}

					$service_key = (string) $service_id;
					if ( ! isset( $schema_arr[ $service_key ] ) ) {
						$had_unresolved_predefined = true;
						continue;
					}
					$service_section = $schema_arr[ $service_key ];
					if ( ! is_array( $service_section ) && ! is_object( $service_section ) ) {
						$had_unresolved_predefined = true;
						continue;
					}

					$enabled_for_service = array();
					foreach ( $ids as $id ) {
						$enabled_for_service[ (string) $id ] = true;
					}
					$resolved_for_service = array();

					foreach ( (array) $service_section as $group ) {
						$group_arr   = (array) $group;
						$definitions = $group_arr['definitions'] ?? array();
						if ( ! is_array( $definitions ) && ! is_object( $definitions ) ) {
							continue;
						}
						foreach ( (array) $definitions as $definition ) {
							$def_arr = (array) $definition;
							$def_id  = isset( $def_arr['id'] ) ? (string) $def_arr['id'] : '';
							if ( '' === $def_id || ! isset( $enabled_for_service[ $def_id ] ) ) {
								continue;
							}
							$resolved_for_service[ $def_id ] = true;

							// MVP: only rigid boxes are modeled correctly. Read
							// `type` from the schema definition when present,
							// but older/current predefined schemas often only
							// expose `is_letter`. In that shape, a false
							// `is_letter` value means the definition is a
							// rigid box.
							$def_type = $this->get_predefined_package_type( $def_arr );
							if ( 'box' !== $def_type ) {
								$this->logger->log(
									sprintf(
										'Auto-assign skipped predefined package "%s" under service "%s": type "%s" is not a box (MVP only supports rigid boxes)',
										$def_id,
										$service_key,
										$def_type
									),
									__CLASS__
								);
								continue;
							}

							$had_box_candidate = true;

							$inner_dimensions = isset( $def_arr['inner_dimensions'] ) ? (string) $def_arr['inner_dimensions'] : '';
							$dims             = $this->parse_dimensions( $inner_dimensions );
							if ( null === $dims ) {
								$this->logger->log(
									sprintf(
										'Auto-assign skipped predefined package "%s" under service "%s": malformed inner_dimensions "%s"',
										$def_id,
										$service_key,
										$inner_dimensions
									),
									__CLASS__
								);
								continue;
							}

							$internal_id = sprintf( 'predef:%s:%s', $service_key, $def_id );
							if ( isset( $added_internal[ $internal_id ] ) ) {
								continue;
							}

							$packages[]                     = array(
								'id'         => $internal_id,
								'package_id' => $def_id,
								'service_id' => $service_key,
								'name'       => isset( $def_arr['name'] ) ? (string) $def_arr['name'] : '',
								'length'     => $dims['length'],
								'width'      => $dims['width'],
								'height'     => $dims['height'],
								'box_weight' => isset( $def_arr['box_weight'] ) ? (float) $def_arr['box_weight'] : 0.0,
								'max_weight' => isset( $def_arr['max_weight'] ) ? (float) $def_arr['max_weight'] : 0.0,
							);
							$added_internal[ $internal_id ] = true;
						}
					}

					foreach ( array_keys( $enabled_for_service ) as $enabled_id ) {
						if ( ! isset( $resolved_for_service[ $enabled_id ] ) ) {
							$had_unresolved_predefined = true;
							break;
						}
					}
				}
			}
		}

		return array(
			'packages'                  => $packages,
			'had_box_candidate'         => $had_box_candidate,
			'had_unresolved_predefined' => $had_unresolved_predefined,
		);
	}

	/**
	 * Return true if at least one predefined package id is enabled.
	 *
	 * `get_predefined_packages()` returns a map of service id to id list
	 * (e.g. `[ 'usps' => [], 'fedex' => [] ]`), which is non-empty as a
	 * map even when every inner list is empty. Walk the inner lists so
	 * callers correctly recognize "zero enabled predefined ids".
	 *
	 * @param mixed $enabled_predefined Map of service id to enabled predefined ids, or any non-array value (treated as "nothing enabled").
	 *
	 * @return bool
	 */
	private static function has_enabled_predefined( $enabled_predefined ): bool {
		if ( ! is_array( $enabled_predefined ) ) {
			return false;
		}
		foreach ( $enabled_predefined as $ids ) {
			if ( ! empty( $ids ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve the predefined package type from schema data.
	 *
	 * Older/current predefined package schemas may omit `type` and only carry
	 * `is_letter`. Treat `is_letter: false` as a box so enabled carrier boxes
	 * remain usable with those schemas.
	 *
	 * @param array $definition Predefined package definition from the schema.
	 *
	 * @return string Package type.
	 */
	private function get_predefined_package_type( array $definition ): string {
		if ( isset( $definition['type'] ) && '' !== (string) $definition['type'] ) {
			return (string) $definition['type'];
		}

		if ( array_key_exists( 'is_letter', $definition ) ) {
			return $this->is_schema_truthy( $definition['is_letter'] )
				? 'envelope'
				: 'box';
		}

		return '';
	}

	/**
	 * Interpret bool-like schema values.
	 *
	 * @param mixed $value Schema value.
	 *
	 * @return bool
	 */
	private function is_schema_truthy( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (float) $value > 0;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Parse an "L x W x H" dimension string into floats.
	 *
	 * Returns null if the string does not match the expected shape so callers
	 * can skip the package rather than feeding 0x0x0 into the packer.
	 *
	 * @param string $dimensions Dimension string, e.g. "10 x 10 x 10".
	 *
	 * @return array{length: float, width: float, height: float}|null
	 */
	private function parse_dimensions( string $dimensions ): ?array {
		// Single optional decimal per token. The looser `[0-9.]+` would have
		// matched "1.2.3" and silently truncated to 1.2 via the float cast.
		if ( ! preg_match( '/^\s*(\d+(?:\.\d+)?)\s*x\s*(\d+(?:\.\d+)?)\s*x\s*(\d+(?:\.\d+)?)\s*$/i', $dimensions, $m ) ) {
			return null;
		}

		$length = (float) $m[1];
		$width  = (float) $m[2];
		$height = (float) $m[3];

		if ( $length <= 0 || $width <= 0 || $height <= 0 ) {
			return null;
		}

		return array(
			'length' => $length,
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * Return the name of the first product whose length, width, height, or
	 * weight is missing or non-positive. The status constant
	 * STATUS_MISSING_DIMENSIONS keeps its public value for client compatibility,
	 * but the helper is named to reflect that it inspects all four metrics.
	 *
	 * @param array<int, array{product: \WC_Product, quantity: int}> $shippable_items Items.
	 *
	 * @return string|null
	 */
	private function find_item_with_invalid_metrics( array $shippable_items ): ?string {
		foreach ( $shippable_items as $item_data ) {
			$product = $item_data['product'];
			if (
				( (float) $product->get_length() ) <= 0
				|| ( (float) $product->get_width() ) <= 0
				|| ( (float) $product->get_height() ) <= 0
				|| ( (float) $product->get_weight() ) <= 0
			) {
				return (string) $product->get_name();
			}
		}
		return null;
	}
}
