<?php
/**
 * Builds a real WooCommerce order directly from the admin app, for a
 * staff member keying in an order over the phone/email rather than a
 * customer checking out themselves — direct request: "I already have
 * the ability to create orders for customers, am I able to make a
 * custom order manually that also is with a proof to be approved by
 * the customer?", broadened immediately after to "manually create any
 * orders, not just custom orders."
 *
 * Phase A (this class, for now): Custom Design orders only — the
 * simplest order type, and the one that fully exercises every piece of
 * shared plumbing (customer resolution, direct order assembly outside
 * the cart, the proof-approval linkage) that Phases B (Custom Sticker)
 * and C (Template label) will reuse unchanged. See docs/ARCHITECTURE.md
 * for the full phasing rationale.
 *
 * Never goes through `WC()->cart` — unlike the customer-facing
 * `YeffoPrint_Custom_Order_Controller::submit()`, which adds items to
 * the session cart and hands the customer a checkout URL, staff aren't
 * "checking out" anything; the order needs to exist, priced and
 * correctly snapshotted, the moment this call returns. Built directly
 * with `wc_create_order()`/`WC_Order::add_product()`, priced with the
 * exact same `YeffoPrint_Cart_Pricing::calculate_for_cart_item()` the
 * live cart uses, and snapshotted with the exact same
 * `YeffoPrint_Order_Item_Meta::apply()` checkout uses (extracted from
 * its hook wrapper for exactly this reason) — so a manually-created
 * order's line items are indistinguishable from a normal checkout's,
 * and every downstream reader (reorder links, QR downloads, order
 * emails, the customization display on the order screen) keeps working
 * unmodified.
 *
 * "Requires proof approval" is not a separate feature bolted on here —
 * it's the same seam `submit()` already relies on:
 * `_yp_custom_order_id` on a line item is what
 * `YeffoPrint_Custom_Order_Payment::link_paid_custom_orders()` (hooked
 * to `woocommerce_order_status_processing`) looks for to publish/link a
 * `yp_custom_order` shell. Mint that shell first when requested, thread
 * its ID into every added item, then actually transition the order to
 * 'processing' — that hook does the rest for free, no new linking code.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Manual_Order_Creator {

	/**
	 * @param array $payload {
	 *     @type string $order_type      Only 'custom_design' supported in Phase A.
	 *     @type array  $customer        { @type int $id } OR { @type string $name, @type string $email }.
	 *     @type string $brand_name
	 *     @type array  $batch           [ { size_id, material_id, quantity, compound_strength } ]
	 *     @type string $style_notes
	 *     @type string $instructions
	 *     @type bool   $requires_proof
	 * }
	 * @return array{order:\WC_Order, custom_order_id:int}|\WP_Error
	 */
	public static function create( array $payload ) {
		$order_type = sanitize_key( (string) ( $payload['order_type'] ?? '' ) );
		if ( 'custom_design' !== $order_type ) {
			return new \WP_Error( 'yeffoprint_unsupported_order_type', __( 'That order type is not available yet.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$brand_name = sanitize_text_field( (string) ( $payload['brand_name'] ?? '' ) );
		if ( '' === $brand_name ) {
			return new \WP_Error( 'yeffoprint_missing_brand_name', __( 'Brand name is required.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$batch = self::validate_batch_rows( $payload['batch'] ?? null );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}

		$customer = self::resolve_or_create_customer( is_array( $payload['customer'] ?? null ) ? $payload['customer'] : [] );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$order = wc_create_order( [
			'customer_id' => $customer->ID,
			'status'      => 'pending',
			'created_via' => 'yeffoprint-admin',
		] );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$order->set_billing_email( $customer->user_email );
		$order->set_billing_first_name( $customer->first_name ?: $customer->display_name );
		$order->set_billing_last_name( $customer->last_name );

		$custom_order_id = 0;
		if ( ! empty( $payload['requires_proof'] ) ) {
			$custom_order_id = YeffoPrint_Custom_Order_Meta::create_shell(
				'label',
				sprintf( '%s — %s', $brand_name, current_time( 'Y-m-d H:i' ) ),
				$customer->ID,
				$customer->user_email,
				trim( $customer->first_name . ' ' . $customer->last_name ) ?: $customer->display_name
			);

			if ( ! $custom_order_id ) {
				$order->delete( true );
				return new \WP_Error( 'yeffoprint_custom_order_failed', __( "Couldn't create the linked proof-approval record. Please try again.", 'yeffoprint-core' ), [ 'status' => 500 ] );
			}
		}

		$result = self::add_custom_design_rows( $order, $batch, $custom_order_id );
		if ( is_wp_error( $result ) ) {
			$order->delete( true );
			if ( $custom_order_id ) {
				wp_delete_post( $custom_order_id, true );
			}
			return $result;
		}

		if ( $custom_order_id ) {
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::BRAND_NAME, $brand_name );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::BATCH, wp_json_encode( $batch ) );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::SIZE_ID, $batch[0]['size_id'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::MATERIAL_ID, $batch[0]['material_id'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::QUANTITY, $batch[0]['quantity'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::COMPOUND_STRENGTH, $batch[0]['compound_strength'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STYLE_NOTES, sanitize_textarea_field( (string) ( $payload['style_notes'] ?? '' ) ) );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::INSTRUCTIONS, sanitize_textarea_field( (string) ( $payload['instructions'] ?? '' ) ) );
		}

		$order->calculate_totals();
		$order->add_order_note( sprintf(
			/* translators: %s: staff display name */
			__( 'Order manually created by %s via the admin app.', 'yeffoprint-core' ),
			wp_get_current_user()->display_name
		) );
		$order->update_meta_data( '_yp_manually_created', 1 );
		$order->save();

		// Fires woocommerce_order_status_processing, which is what
		// link_paid_custom_orders() listens for — publishes/links the
		// shell created above (if any) and makes this order show up in
		// the Dashboard's existing Pending Orders panel / Send to
		// Printer action, exactly like a real checkout.
		$order->update_status( 'processing', __( 'Marked processing on manual creation.', 'yeffoprint-core' ) );

		return [ 'order' => $order, 'custom_order_id' => $custom_order_id ];
	}

	/**
	 * @return \WP_User|\WP_Error
	 */
	private static function resolve_or_create_customer( array $customer_payload ) {
		if ( ! empty( $customer_payload['id'] ) ) {
			$user = get_user_by( 'id', absint( $customer_payload['id'] ) );
			if ( ! $user ) {
				return new \WP_Error( 'yeffoprint_invalid_customer', __( 'Please choose a valid customer.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}
			return $user;
		}

		$email = sanitize_email( (string) ( $customer_payload['email'] ?? '' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error( 'yeffoprint_invalid_customer_email', __( 'Please enter a valid email address for the new customer.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		// Never duplicate — same rule YeffoPrint_Social_Login::find_or_create_user() already uses.
		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			return $existing;
		}

		[ $first_name, $last_name ] = self::split_name( sanitize_text_field( (string) ( $customer_payload['name'] ?? '' ) ) );

		$user_id = wc_create_new_customer( $email, '', wp_generate_password( 32, true, true ), [
			'first_name' => $first_name,
			'last_name'  => $last_name,
		] );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		return get_user_by( 'id', $user_id );
	}

	private static function split_name( string $name ): array {
		$name = trim( $name );
		if ( '' === $name ) {
			return [ '', '' ];
		}
		$parts = explode( ' ', $name, 2 );
		return [ $parts[0], $parts[1] ?? '' ];
	}

	/**
	 * Adds the design fee (skipped if $custom_order_id is 0 — an order
	 * not requiring proof approval still gets its labels priced and
	 * snapshotted identically, just with no fee item and no
	 * _yp_custom_order_id anywhere, same as a normal print run) plus one
	 * "Custom Order Labels" line item per batch row — mirrors
	 * class-custom-order-controller.php::submit()'s own fee-then-rows
	 * shape, just built directly on the order instead of through
	 * WC()->cart->add_to_cart().
	 *
	 * @return true|\WP_Error
	 */
	private static function add_custom_design_rows( \WC_Order $order, array $batch, int $custom_order_id ) {
		$labels_product_id = YeffoPrint_Custom_Order_Labels_Product::get_product_id();
		if ( ! $labels_product_id ) {
			return new \WP_Error( 'yeffoprint_no_labels_product', __( 'Custom design orders are not available right now.', 'yeffoprint-core' ), [ 'status' => 503 ] );
		}
		$labels_product = wc_get_product( $labels_product_id );

		if ( $custom_order_id ) {
			$fee_product_id = YeffoPrint_Custom_Design_Fee_Product::get_product_id();
			if ( ! $fee_product_id ) {
				return new \WP_Error( 'yeffoprint_no_fee_product', __( 'Custom design orders are not available right now.', 'yeffoprint-core' ), [ 'status' => 503 ] );
			}
			$fee_product = wc_get_product( $fee_product_id );
			$fee_amount  = YeffoPrint_Pricing_Rule::get_custom_design_fee();

			$fee_item_id = $order->add_product( $fee_product, 1, [ 'subtotal' => $fee_amount, 'total' => $fee_amount ] );
			if ( ! $fee_item_id ) {
				return new \WP_Error( 'yeffoprint_add_item_failed', __( "Couldn't add the design fee to the order.", 'yeffoprint-core' ), [ 'status' => 500 ] );
			}

			$fee_item = $order->get_item( $fee_item_id );
			if ( $fee_item instanceof \WC_Order_Item_Product ) {
				YeffoPrint_Order_Item_Meta::apply( $fee_item, [
					YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ID => $custom_order_id,
				] );
				$fee_item->save();
			}
		}

		// Same bulk-discount pool a real cart would use, computed across
		// this order's own rows since there's no live WC()->cart here for
		// YeffoPrint_Cart_Pricing::calculate_for_cart_item()'s own default
		// to fall back on.
		$tier_quantity = 0;
		foreach ( $batch as $row ) {
			$tier_quantity += (int) $row['quantity'];
		}

		foreach ( $batch as $row_index => $row ) {
			$values = [
				YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ID        => $custom_order_id,
				YeffoPrint_Cart_Item_Keys::SIZE_ID                => $row['size_id'],
				YeffoPrint_Cart_Item_Keys::MATERIAL_ID            => $row['material_id'],
				YeffoPrint_Cart_Item_Keys::TOTAL_QTY              => $row['quantity'],
				YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ROW_INDEX => $row_index,
				YeffoPrint_Cart_Item_Keys::COMPOUND_STRENGTH      => $row['compound_strength'],
			];

			$pricing = YeffoPrint_Cart_Pricing::calculate_for_cart_item( $values, $tier_quantity );
			$total   = $pricing['total'] ?? 0.0;

			$item_id = $order->add_product( $labels_product, $row['quantity'], [ 'subtotal' => $total, 'total' => $total ] );
			if ( ! $item_id ) {
				return new \WP_Error( 'yeffoprint_add_item_failed', __( "Couldn't add your labels to the order.", 'yeffoprint-core' ), [ 'status' => 500 ] );
			}

			$item = $order->get_item( $item_id );
			if ( $item instanceof \WC_Order_Item_Product ) {
				YeffoPrint_Order_Item_Meta::apply( $item, $values, $tier_quantity );
				$item->save();
			}
		}

		return true;
	}

	/**
	 * Same validation rules as class-custom-order-controller.php's own
	 * private validate_batch_rows() — kept local rather than shared,
	 * matching this plugin's existing per-controller convention (that
	 * method's own docblock notes is_published()/record_adjustment() are
	 * deliberately not shared either).
	 *
	 * @return array<int, array{size_id:int, material_id:int, quantity:int, compound_strength:string}>|\WP_Error
	 */
	private static function validate_batch_rows( $raw ) {
		if ( ! is_array( $raw ) || ! $raw ) {
			return new \WP_Error( 'yeffoprint_empty_batch', __( 'Please add at least one label to the order.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$rows = [];

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$size_id = absint( $row['size_id'] ?? 0 );
			if ( ! $size_id || ! self::is_published( 'yp_size', $size_id ) ) {
				return new \WP_Error( 'yeffoprint_invalid_size', __( 'Please choose a valid size for every label.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}

			$material_id = absint( $row['material_id'] ?? 0 );
			if ( ! $material_id || ! self::is_published( 'yp_material', $material_id ) ) {
				return new \WP_Error( 'yeffoprint_invalid_material', __( 'Please choose a valid material for every label.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}

			if ( ! (bool) get_post_meta( $material_id, YeffoPrint_Commerce_Record_Meta::IN_STOCK, true ) ) {
				return new \WP_Error( 'yeffoprint_material_out_of_stock', __( 'One of the materials you chose is currently out of stock. Please choose a different one.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}

			$quantity = absint( $row['quantity'] ?? 0 );
			if ( $quantity < 1 ) {
				return new \WP_Error( 'yeffoprint_invalid_quantity', __( 'Quantity must be at least 1 for every label.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}

			$rows[] = [
				'size_id'           => $size_id,
				'material_id'       => $material_id,
				'quantity'          => $quantity,
				'compound_strength' => sanitize_text_field( (string) ( $row['compound_strength'] ?? '' ) ),
			];
		}

		if ( ! $rows ) {
			return new \WP_Error( 'yeffoprint_empty_batch', __( 'Please add at least one label to the order.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		return $rows;
	}

	private static function is_published( string $post_type, int $post_id ): bool {
		$post = get_post( $post_id );
		return (bool) ( $post && $post_type === $post->post_type && 'publish' === $post->post_status );
	}
}
