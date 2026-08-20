<?php
/**
 * Order history: built entirely on WooCommerce's own order CRUD
 * (wc_get_orders(), WC_Order, WC_Order_Item_*) rather than raw table
 * queries, so this works correctly whether the store uses legacy
 * post-based order storage or HPOS (custom order tables) — the CRUD
 * layer is the one thing that already abstracts over both.
 *
 * Two documented, deliberate gaps (see the plugin bootstrap file's
 * docblock for the reasoning): product_id/variation_id on migrated
 * line items won't resolve to real products, since products never
 * migrate; and refunds are preserved only as a read-only summary
 * (amount/reason/date) rather than reconstructed as real
 * WC_Order_Refund objects, which would risk re-triggering refund-time
 * side effects (stock adjustments, gateway hooks, emails) that have
 * no business firing again during a data migration.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Migrate_Orders_Migrator {

	private const ITEM_TYPES = [ 'line_item', 'shipping', 'fee', 'coupon', 'tax' ];
	private const TOTAL_PROPS = [ 'total', 'total_tax', 'shipping_total', 'shipping_tax', 'discount_total', 'discount_tax' ];

	public function count_total(): int {
		$result = wc_get_orders( [ 'limit' => 1, 'paginate' => true, 'return' => 'ids' ] );
		return (int) $result->total;
	}

	/** @return array<int, array> */
	public function export_batch( int $offset, int $limit ): array {
		$orders = wc_get_orders( [
			'limit'   => $limit,
			'offset'  => $offset,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'objects',
		] );

		return array_map( [ $this, 'export_order' ], $orders );
	}

	private function export_order( \WC_Order $order ): array {
		$items = [];
		foreach ( self::ITEM_TYPES as $type ) {
			foreach ( $order->get_items( $type ) as $item ) {
				$items[] = $this->export_item( $item, $type );
			}
		}

		$notes = array_map( static function ( $note ) {
			return [
				'content'       => $note->content,
				'added_by'      => $note->added_by,
				'date_created'  => $note->date_created ? $note->date_created->date( 'Y-m-d H:i:s' ) : null,
				'customer_note' => (bool) $note->customer_note,
			];
		}, wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );

		$refund_summary = array_map( static function ( \WC_Order_Refund $refund ) {
			return [
				'amount' => $refund->get_amount(),
				'reason' => $refund->get_reason(),
				'date'   => $refund->get_date_created() ? $refund->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
			];
		}, $order->get_refunds() );

		return [
			'old_id'               => $order->get_id(),
			'status'               => $order->get_status(),
			'currency'             => $order->get_currency(),
			'customer_old_id'      => $order->get_customer_id(),
			'customer_note'        => $order->get_customer_note(),
			'payment_method'       => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'transaction_id'       => $order->get_transaction_id(),
			'date_created'         => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
			'date_paid'            => $order->get_date_paid() ? $order->get_date_paid()->date( 'Y-m-d H:i:s' ) : null,
			'date_completed'       => $order->get_date_completed() ? $order->get_date_completed()->date( 'Y-m-d H:i:s' ) : null,
			'billing'              => $order->get_address( 'billing' ),
			'shipping'             => $order->get_address( 'shipping' ),
			'totals'               => $this->export_totals( $order ),
			'items'                => $items,
			'notes'                => $notes,
			'refund_summary'       => $refund_summary,
			'order_meta'           => $this->export_meta( $order->get_meta_data() ),
		];
	}

	private function export_totals( \WC_Order $order ): array {
		$totals = [];
		foreach ( self::TOTAL_PROPS as $prop ) {
			$getter = 'get_' . $prop;
			if ( method_exists( $order, $getter ) ) {
				$totals[ $prop ] = $order->$getter();
			}
		}
		return $totals;
	}

	private function export_item( \WC_Order_Item $item, string $type ): array {
		$data = [
			'type' => $type,
			'name' => $item->get_name(),
			'meta' => $this->export_meta( $item->get_meta_data() ),
		];

		switch ( $type ) {
			case 'line_item':
				/** @var \WC_Order_Item_Product $item */
				$data += [
					'product_id'   => $item->get_product_id(),
					'variation_id' => $item->get_variation_id(),
					'quantity'     => $item->get_quantity(),
					'subtotal'     => $item->get_subtotal(),
					'total'        => $item->get_total(),
					'subtotal_tax' => $item->get_subtotal_tax(),
					'total_tax'    => $item->get_total_tax(),
					'taxes'        => $item->get_taxes(),
				];
				break;

			case 'shipping':
				/** @var \WC_Order_Item_Shipping $item */
				$data += [
					'method_id'   => $item->get_method_id(),
					'instance_id' => $item->get_instance_id(),
					'total'       => $item->get_total(),
					'total_tax'   => $item->get_total_tax(),
					'taxes'       => $item->get_taxes(),
				];
				break;

			case 'fee':
				/** @var \WC_Order_Item_Fee $item */
				$data += [
					'total'      => $item->get_total(),
					'total_tax'  => $item->get_total_tax(),
					'tax_class'  => $item->get_tax_class(),
					'tax_status' => $item->get_tax_status(),
				];
				break;

			case 'coupon':
				/** @var \WC_Order_Item_Coupon $item */
				$data += [
					'code'         => $item->get_code(),
					'discount'     => $item->get_discount(),
					'discount_tax' => $item->get_discount_tax(),
				];
				break;

			case 'tax':
				/** @var \WC_Order_Item_Tax $item */
				$data += [
					'rate_code'           => $item->get_rate_code(),
					'rate_id'             => $item->get_rate_id(),
					'label'               => $item->get_label(),
					'compound'            => $item->get_compound(),
					'tax_total'           => $item->get_tax_total(),
					'shipping_tax_total'  => $item->get_shipping_tax_total(),
				];
				break;
		}

		return $data;
	}

	/** @return array<int, array{key:string, value:mixed}> */
	private function export_meta( array $meta_data ): array {
		return array_map( static function ( $meta ) {
			return [ 'key' => $meta->key, 'value' => $meta->value ];
		}, $meta_data );
	}

	/**
	 * @param array $rows One export_batch() page, decoded.
	 * @param array $user_id_map Old user ID => new user ID, from a completed users import.
	 * @return array{created:int, skipped:int, errors:array<int,string>}
	 */
	public function import_batch( array $rows, array $user_id_map ): array {
		$created = 0;
		$skipped = 0;
		$errors  = [];

		foreach ( $rows as $row ) {
			$old_id = (int) ( $row['old_id'] ?? 0 );
			if ( ! $old_id ) {
				continue;
			}

			if ( $this->already_imported( $old_id ) ) {
				$skipped++;
				continue;
			}

			try {
				$this->import_order( $row, $user_id_map );
				$created++;
			} catch ( \Throwable $e ) {
				$errors[] = sprintf( __( 'Order (old id %1$d): %2$s', 'yeffoprint-migrate' ), $old_id, $e->getMessage() );
			}
		}

		return [ 'created' => $created, 'skipped' => $skipped, 'errors' => $errors ];
	}

	private function already_imported( int $old_id ): bool {
		$existing = wc_get_orders( [
			'limit'    => 1,
			'return'   => 'ids',
			'meta_key' => '_yp_migrated_from_order_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time migration tool, not a hot path; see class docblock.
			'meta_value' => $old_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );
		return ! empty( $existing );
	}

	private function import_order( array $row, array $user_id_map ): void {
		$order = new \WC_Order();

		$status = sanitize_key( (string) ( $row['status'] ?? 'pending' ) );
		$order->set_status( $status );

		if ( ! empty( $row['currency'] ) ) {
			$order->set_currency( (string) $row['currency'] );
		}

		$customer_old_id = (int) ( $row['customer_old_id'] ?? 0 );
		$order->set_customer_id( $customer_old_id ? (int) ( $user_id_map[ $customer_old_id ] ?? 0 ) : 0 );

		$order->set_customer_note( (string) ( $row['customer_note'] ?? '' ) );
		$order->set_created_via( 'yeffoprint_migrate' );

		if ( ! empty( $row['payment_method'] ) ) {
			$order->set_payment_method( (string) $row['payment_method'] );
		}
		if ( ! empty( $row['payment_method_title'] ) ) {
			$order->set_payment_method_title( (string) $row['payment_method_title'] );
		}
		if ( ! empty( $row['transaction_id'] ) ) {
			$order->set_transaction_id( (string) $row['transaction_id'] );
		}

		foreach ( [ 'billing', 'shipping' ] as $address_type ) {
			if ( ! empty( $row[ $address_type ] ) && is_array( $row[ $address_type ] ) ) {
				$order->set_address( $row[ $address_type ], $address_type );
			}
		}

		foreach ( (array) ( $row['items'] ?? [] ) as $item_data ) {
			$this->import_item( $order, $item_data );
		}

		foreach ( (array) ( $row['totals'] ?? [] ) as $prop => $value ) {
			$setter = 'set_' . $prop;
			if ( in_array( $prop, self::TOTAL_PROPS, true ) && method_exists( $order, $setter ) ) {
				$order->$setter( $value );
			}
		}

		foreach ( [ 'date_created', 'date_paid', 'date_completed' ] as $date_prop ) {
			if ( ! empty( $row[ $date_prop ] ) ) {
				$order->{ 'set_' . $date_prop }( (string) $row[ $date_prop ] );
			}
		}

		$order->update_meta_data( '_yp_migrated_from_order_id', (int) $row['old_id'] );
		if ( ! empty( $row['refund_summary'] ) ) {
			$order->update_meta_data( '_yp_migrate_refund_summary', $row['refund_summary'] );
		}
		foreach ( (array) ( $row['order_meta'] ?? [] ) as $meta ) {
			if ( isset( $meta['key'] ) ) {
				$order->add_meta_data( (string) $meta['key'], $meta['value'] ?? '' );
			}
		}

		$order_id = $order->save();

		foreach ( (array) ( $row['notes'] ?? [] ) as $note ) {
			if ( ! empty( $note['content'] ) ) {
				wc_create_order_note( $order_id, (string) $note['content'], ! empty( $note['customer_note'] ), false );
			}
		}
	}

	private function import_item( \WC_Order $order, array $item_data ): void {
		$type = (string) ( $item_data['type'] ?? '' );

		switch ( $type ) {
			case 'line_item':
				$item = new \WC_Order_Item_Product();
				$item->set_name( (string) ( $item_data['name'] ?? '' ) );
				$item->set_product_id( (int) ( $item_data['product_id'] ?? 0 ) ); // Frozen name/price above still display correctly even though this id won't resolve to a real product — see class docblock.
				$item->set_variation_id( (int) ( $item_data['variation_id'] ?? 0 ) );
				$item->set_quantity( (float) ( $item_data['quantity'] ?? 1 ) );
				$item->set_subtotal( (string) ( $item_data['subtotal'] ?? '0' ) );
				$item->set_total( (string) ( $item_data['total'] ?? '0' ) );
				$item->set_subtotal_tax( (string) ( $item_data['subtotal_tax'] ?? '0' ) );
				$item->set_total_tax( (string) ( $item_data['total_tax'] ?? '0' ) );
				if ( isset( $item_data['taxes'] ) && is_array( $item_data['taxes'] ) ) {
					$item->set_taxes( $item_data['taxes'] );
				}
				break;

			case 'shipping':
				$item = new \WC_Order_Item_Shipping();
				$item->set_method_title( (string) ( $item_data['name'] ?? '' ) );
				$item->set_method_id( (string) ( $item_data['method_id'] ?? '' ) );
				$item->set_instance_id( (int) ( $item_data['instance_id'] ?? 0 ) );
				$item->set_total( (string) ( $item_data['total'] ?? '0' ) );
				$item->set_total_tax( (string) ( $item_data['total_tax'] ?? '0' ) );
				if ( isset( $item_data['taxes'] ) && is_array( $item_data['taxes'] ) ) {
					$item->set_taxes( $item_data['taxes'] );
				}
				break;

			case 'fee':
				$item = new \WC_Order_Item_Fee();
				$item->set_name( (string) ( $item_data['name'] ?? '' ) );
				$item->set_total( (string) ( $item_data['total'] ?? '0' ) );
				$item->set_total_tax( (string) ( $item_data['total_tax'] ?? '0' ) );
				$item->set_tax_class( (string) ( $item_data['tax_class'] ?? '' ) );
				$item->set_tax_status( (string) ( $item_data['tax_status'] ?? 'taxable' ) );
				break;

			case 'coupon':
				$item = new \WC_Order_Item_Coupon();
				$item->set_code( (string) ( $item_data['code'] ?? '' ) );
				$item->set_discount( (string) ( $item_data['discount'] ?? '0' ) );
				$item->set_discount_tax( (string) ( $item_data['discount_tax'] ?? '0' ) );
				break;

			case 'tax':
				$item = new \WC_Order_Item_Tax();
				$item->set_rate_id( (int) ( $item_data['rate_id'] ?? 0 ) );
				$item->set_label( (string) ( $item_data['label'] ?? '' ) );
				$item->set_compound( ! empty( $item_data['compound'] ) );
				$item->set_tax_total( (string) ( $item_data['tax_total'] ?? '0' ) );
				$item->set_shipping_tax_total( (string) ( $item_data['shipping_tax_total'] ?? '0' ) );
				break;

			default:
				return;
		}

		foreach ( (array) ( $item_data['meta'] ?? [] ) as $meta ) {
			if ( isset( $meta['key'] ) ) {
				$item->add_meta_data( (string) $meta['key'], $meta['value'] ?? '' );
			}
		}

		$order->add_item( $item );
	}
}
