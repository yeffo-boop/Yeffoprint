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
 * Phase A shipped Custom Design orders — the simplest order type, and
 * the one that fully exercised every piece of shared plumbing (customer
 * resolution, direct order assembly outside the cart, the proof-approval
 * linkage). Phase B (this revision) adds Custom Stickers, reusing all of
 * that plumbing unchanged — no fee item (Custom Stickers has none) and
 * no batching (one sticker configuration per submission, matching the
 * customer-facing flow exactly). Phase C (this revision) adds Template
 * Label orders — a real, existing `yp_template` (the same kind customers
 * order from directly), not a freeform Custom Design/Sticker submission.
 * This is the one case that genuinely needed its own new `ORDER_TYPE`
 * ('template', class-custom-order-meta.php): the customer-facing
 * Template flow (class-cart-controller.php) never routes through the
 * proof-approval pipeline at all — only this manual path, when staff opt
 * in, does. It also required a real fix to the shared snapshot function
 * this class already relies on: YeffoPrint_Order_Item_Meta::apply()
 * previously assumed any line item carrying both CUSTOM_ORDER_ID and
 * TOTAL_QTY was a Custom Design labels row; a Template order requiring
 * proof approval carries both too, so that method now checks for
 * TEMPLATE_ID first to tell the two apart (see its own comment). See
 * docs/ARCHITECTURE.md for the full phasing rationale.
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
	 *     @type string $order_type      'custom_design', 'sticker', or 'template'.
	 *     @type array  $customer        { @type int $id } OR { @type string $name, @type string $email }.
	 *     @type string $brand_name      custom_design only.
	 *     @type array  $batch           custom_design only — [ { size_id, material_id, quantity, compound_strength } ]
	 *     @type string $style_notes     custom_design only.
	 *     @type string $instructions
	 *     @type int    $size_id         sticker/template only.
	 *     @type int    $material_id     sticker/template only.
	 *     @type string $sticker_type    sticker only.
	 *     @type string $shape           sticker only.
	 *     @type int    $quantity        sticker only.
	 *     @type float  $custom_width_in  sticker only.
	 *     @type float  $custom_height_in sticker only.
	 *     @type array  $uploads         sticker only — attachment IDs, already uploaded via /custom-orders/uploads.
	 *     @type int    $template_id     template only.
	 *     @type array  $variants        template only — [ { quantity, values: { field_id: value } } ]
	 *     @type bool   $requires_proof
	 *     @type bool   $waive_design_fee custom_design only — direct request: staff need to be able to
	 *                                    waive the $25 design fee on some manual orders (VIP customer,
	 *                                    goodwill, etc). No effect on sticker/template orders, which have
	 *                                    no flat fee to waive in the first place.
	 *     @type bool   $send_invoice_email  All order types — direct request: email the customer their
	 *                                       order details and a payment link right on creation, via
	 *                                       WooCommerce's own built-in Order details/Customer Invoice email.
	 * }
	 * @return array{order:\WC_Order, custom_order_id:int}|\WP_Error
	 */
	public static function create( array $payload ) {
		$order_type = sanitize_key( (string) ( $payload['order_type'] ?? '' ) );
		if ( ! in_array( $order_type, [ 'custom_design', 'sticker', 'template' ], true ) ) {
			return new \WP_Error( 'yeffoprint_unsupported_order_type', __( 'That order type is not available yet.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$brand_name = '';
		$batch      = [];
		$sticker    = [];
		$template   = [];

		if ( 'custom_design' === $order_type ) {
			$brand_name = sanitize_text_field( (string) ( $payload['brand_name'] ?? '' ) );
			if ( '' === $brand_name ) {
				return new \WP_Error( 'yeffoprint_missing_brand_name', __( 'Brand name is required.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}

			$batch = self::validate_batch_rows( $payload['batch'] ?? null );
			if ( is_wp_error( $batch ) ) {
				return $batch;
			}
		} elseif ( 'sticker' === $order_type ) {
			$sticker = self::validate_sticker_fields( $payload );
			if ( is_wp_error( $sticker ) ) {
				return $sticker;
			}
		} else {
			$template = self::validate_template_fields( $payload );
			if ( is_wp_error( $template ) ) {
				return $template;
			}
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

		$order_type_to_shell_type = [
			'custom_design' => 'label',
			'sticker'       => 'sticker',
			'template'      => 'template',
		];

		$custom_order_id = 0;
		if ( ! empty( $payload['requires_proof'] ) ) {
			if ( 'custom_design' === $order_type ) {
				$title = sprintf( '%s — %s', $brand_name, current_time( 'Y-m-d H:i' ) );
			} elseif ( 'sticker' === $order_type ) {
				/* translators: %s: submission date/time */
				$title = sprintf( __( 'Custom Stickers — %s', 'yeffoprint-core' ), current_time( 'Y-m-d H:i' ) );
			} else {
				$title = sprintf( '%s — %s', $template['template_title'], current_time( 'Y-m-d H:i' ) );
			}

			$custom_order_id = YeffoPrint_Custom_Order_Meta::create_shell(
				$order_type_to_shell_type[ $order_type ],
				$title,
				$customer->ID,
				$customer->user_email,
				trim( $customer->first_name . ' ' . $customer->last_name ) ?: $customer->display_name
			);

			if ( ! $custom_order_id ) {
				$order->delete( true );
				return new \WP_Error( 'yeffoprint_custom_order_failed', __( "Couldn't create the linked proof-approval record. Please try again.", 'yeffoprint-core' ), [ 'status' => 500 ] );
			}
		}

		if ( 'custom_design' === $order_type ) {
			$waive_fee = ! empty( $payload['waive_design_fee'] );
			$result    = self::add_custom_design_rows( $order, $batch, $custom_order_id, $waive_fee );
		} elseif ( 'sticker' === $order_type ) {
			$result = self::add_sticker_row( $order, $sticker, $custom_order_id );
		} else {
			$result = self::add_template_row( $order, $template, $custom_order_id );
		}

		if ( is_wp_error( $result ) ) {
			$order->delete( true );
			if ( $custom_order_id ) {
				wp_delete_post( $custom_order_id, true );
			}
			return $result;
		}

		if ( $custom_order_id && 'custom_design' === $order_type ) {
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::BRAND_NAME, $brand_name );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::BATCH, wp_json_encode( $batch ) );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::SIZE_ID, $batch[0]['size_id'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::MATERIAL_ID, $batch[0]['material_id'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::QUANTITY, $batch[0]['quantity'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::COMPOUND_STRENGTH, $batch[0]['compound_strength'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STYLE_NOTES, sanitize_textarea_field( (string) ( $payload['style_notes'] ?? '' ) ) );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::INSTRUCTIONS, sanitize_textarea_field( (string) ( $payload['instructions'] ?? '' ) ) );
		} elseif ( $custom_order_id && 'sticker' === $order_type ) {
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::SIZE_ID, $sticker['size_id'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::MATERIAL_ID, $sticker['material_id'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::QUANTITY, $sticker['quantity'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STICKER_TYPE, $sticker['sticker_type'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::SHAPE, $sticker['shape'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOM_WIDTH_IN, $sticker['custom_width_in'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOM_HEIGHT_IN, $sticker['custom_height_in'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::INSTRUCTIONS, $sticker['instructions'] );
			// Optional here unlike the customer-facing form's hard
			// requirement — staff placing a phone/email order often
			// haven't received the artwork file yet and shouldn't be
			// blocked from getting the order into the pipeline; the
			// customer's proof-approval flow (once a proof is uploaded)
			// is what actually needs artwork in hand, not this step.
			if ( $sticker['uploads'] ) {
				update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::ARTWORK_UPLOADS, $sticker['uploads'] );
			}
		} elseif ( $custom_order_id ) {
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::TEMPLATE_ID, $template['template_id'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::SIZE_ID, $template['size_id'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::MATERIAL_ID, $template['material_id'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::TEMPLATE_VARIANTS, wp_json_encode( $template['variants'] ) );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::INSTRUCTIONS, $template['instructions'] );
		}

		$order->calculate_totals();
		$order->add_order_note( sprintf(
			/* translators: %s: staff display name */
			__( 'Order manually created by %s via the admin app.', 'yeffoprint-core' ),
			wp_get_current_user()->display_name
		) );
		$order->update_meta_data( '_yp_manually_created', 1 );
		$order->save();

		// Direct report: this used to force the order straight to
		// 'processing' regardless of payment, which (a) showed an unpaid
		// order as if it were already in production, and (b) made
		// WooCommerce's own order-pay page refuse it ("This order cannot
		// be paid for") since needs_payment() is false once an order is
		// processing. Left at 'pending' (set in wc_create_order() above)
		// instead — the exact same "pending payment" status a real
		// checkout starts an order in. Once it's actually paid (via the
		// order-pay link, a manual status change, whatever gateway is
		// used), the existing woocommerce_order_status_processing/
		// _completed/payment_complete hooks in class-custom-order-payment.php
		// publish/link the shell created above — same rule regardless of
		// origin, per YeffoPrint_Custom_Order_Meta::create_shell()'s own
		// docblock.

		// Direct request: staff need a way to hand the customer their
		// order details plus a working payment link, right from creation,
		// instead of relaying one by hand (the likely source of a
		// separately-reported "your cart is empty" error — a manually
		// typed/copied link that wasn't actually built from
		// $order->get_checkout_payment_url()). Reuses WooCommerce's own
		// built-in "Order details" (nee "Customer invoice") email
		// wholesale — WC_Email_Customer_Invoice, `manual = true`, the
		// exact email core's own "Email invoice / order details to
		// customer" order action sends — rather than building a parallel
		// one: it already renders the order items/totals table, and its
		// own template (theme override: woocommerce/emails/
		// customer-invoice.php) already includes the payment link via
		// $order->get_checkout_payment_url() whenever needs_payment() is
		// true, so this order's fee waiver/pending status are reflected
		// automatically with no extra plumbing here.
		if ( ! empty( $payload['send_invoice_email'] ) && function_exists( 'WC' ) && WC()->mailer() ) {
			WC()->mailer()->customer_invoice( $order );
		}

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
	 * _yp_custom_order_id anywhere, same as a normal print run — or if
	 * $waive_fee is true, staff choosing not to charge it on this
	 * particular order) plus one "Custom Order Labels" line item per
	 * batch row — mirrors class-custom-order-controller.php::submit()'s
	 * own fee-then-rows shape, just built directly on the order instead
	 * of through WC()->cart->add_to_cart().
	 *
	 * @return true|\WP_Error
	 */
	private static function add_custom_design_rows( \WC_Order $order, array $batch, int $custom_order_id, bool $waive_fee = false ) {
		$labels_product_id = YeffoPrint_Custom_Order_Labels_Product::get_product_id();
		if ( ! $labels_product_id ) {
			return new \WP_Error( 'yeffoprint_no_labels_product', __( 'Custom design orders are not available right now.', 'yeffoprint-core' ), [ 'status' => 503 ] );
		}
		$labels_product = wc_get_product( $labels_product_id );

		if ( $custom_order_id && $waive_fee ) {
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::FEE_WAIVED, '1' );
		} elseif ( $custom_order_id ) {
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
	 * Same validation rules as class-custom-sticker-controller.php's own
	 * submit() — kept local rather than shared, matching this file's own
	 * existing per-controller convention (see validate_batch_rows()'s own
	 * docblock below).
	 *
	 * @return array{size_id:int, material_id:int, sticker_type:string, shape:string, quantity:int, custom_width_in:float, custom_height_in:float, instructions:string, uploads:int[]}|\WP_Error
	 */
	private static function validate_sticker_fields( array $payload ) {
		$size_id = absint( $payload['size_id'] ?? 0 );
		if ( ! $size_id || ! self::is_published( 'yp_sticker_size', $size_id ) ) {
			return new \WP_Error( 'yeffoprint_invalid_size', __( 'Please choose a valid size.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$material_id = absint( $payload['material_id'] ?? 0 );
		if ( ! $material_id || ! self::is_published( 'yp_material', $material_id ) ) {
			return new \WP_Error( 'yeffoprint_invalid_material', __( 'Please choose a valid material.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		if ( ! (bool) get_post_meta( $material_id, YeffoPrint_Commerce_Record_Meta::IN_STOCK, true ) ) {
			return new \WP_Error( 'yeffoprint_material_out_of_stock', __( 'That material is currently out of stock. Please choose a different one.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$sticker_type = sanitize_key( (string) ( $payload['sticker_type'] ?? '' ) );
		if ( ! array_key_exists( $sticker_type, YeffoPrint_Sticker_Pricing::TYPES ) ) {
			return new \WP_Error( 'yeffoprint_invalid_sticker_type', __( 'Please choose a valid sticker type.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$shape = sanitize_key( (string) ( $payload['shape'] ?? '' ) );
		if ( ! array_key_exists( $shape, YeffoPrint_Sticker_Pricing::SHAPES ) ) {
			return new \WP_Error( 'yeffoprint_invalid_shape', __( 'Please choose a valid shape.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$quantity = absint( $payload['quantity'] ?? 0 );
		if ( $quantity < 1 ) {
			return new \WP_Error( 'yeffoprint_invalid_quantity', __( 'Quantity must be at least 1.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$custom_width_in  = (float) ( $payload['custom_width_in'] ?? 0 );
		$custom_height_in = (float) ( $payload['custom_height_in'] ?? 0 );

		$pricing_check = YeffoPrint_Sticker_Pricing::calculate( $size_id, $custom_width_in, $custom_height_in, $material_id, $sticker_type, $shape, $quantity );
		if ( is_wp_error( $pricing_check ) ) {
			return $pricing_check;
		}

		$uploads = array_values( array_filter(
			array_map( 'absint', is_array( $payload['uploads'] ?? null ) ? $payload['uploads'] : [] ),
			static function ( $id ) {
				return $id && 'attachment' === get_post_type( $id );
			}
		) );

		return [
			'size_id'          => $size_id,
			'material_id'      => $material_id,
			'sticker_type'     => $sticker_type,
			'shape'            => $shape,
			'quantity'         => $quantity,
			'custom_width_in'  => $custom_width_in,
			'custom_height_in' => $custom_height_in,
			'instructions'     => sanitize_textarea_field( (string) ( $payload['instructions'] ?? '' ) ),
			'uploads'          => $uploads,
		];
	}

	/**
	 * Custom Stickers' single line item — no fee item (Custom Stickers
	 * has none at all, unlike Custom Design's separate $25 design fee)
	 * and no batching (one sticker configuration per manual order,
	 * matching the customer-facing flow's own shape exactly). Mirrors
	 * class-custom-sticker-controller.php::submit()'s own pricing/add
	 * steps, just built directly on the order instead of through
	 * WC()->cart->add_to_cart().
	 *
	 * @return true|\WP_Error
	 */
	private static function add_sticker_row( \WC_Order $order, array $sticker, int $custom_order_id ) {
		$product_id = YeffoPrint_Custom_Sticker_Product::get_product_id();
		if ( ! $product_id ) {
			return new \WP_Error( 'yeffoprint_no_sticker_product', __( 'Custom Stickers orders are not available right now.', 'yeffoprint-core' ), [ 'status' => 503 ] );
		}
		$product = wc_get_product( $product_id );

		// No cart-wide pool to combine against — this is the only sticker
		// row a manual order can ever have (no batching for Custom
		// Stickers), so the bulk-discount tier is just this row's own
		// quantity, same as a solo customer-facing submission would see.
		$pricing = YeffoPrint_Sticker_Pricing::calculate(
			$sticker['size_id'],
			$sticker['custom_width_in'],
			$sticker['custom_height_in'],
			$sticker['material_id'],
			$sticker['sticker_type'],
			$sticker['shape'],
			$sticker['quantity']
		);
		if ( is_wp_error( $pricing ) ) {
			return $pricing;
		}
		$total = $pricing['total'];

		$item_id = $order->add_product( $product, $sticker['quantity'], [ 'subtotal' => $total, 'total' => $total ] );
		if ( ! $item_id ) {
			return new \WP_Error( 'yeffoprint_add_item_failed', __( "Couldn't add the stickers to the order.", 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		$item = $order->get_item( $item_id );
		if ( $item instanceof \WC_Order_Item_Product ) {
			$values = [
				YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ID  => $custom_order_id,
				YeffoPrint_Cart_Item_Keys::SIZE_ID          => $sticker['size_id'],
				YeffoPrint_Cart_Item_Keys::MATERIAL_ID      => $sticker['material_id'],
				YeffoPrint_Cart_Item_Keys::STICKER_TYPE     => $sticker['sticker_type'],
				YeffoPrint_Cart_Item_Keys::SHAPE            => $sticker['shape'],
				YeffoPrint_Cart_Item_Keys::CUSTOM_WIDTH_IN  => $sticker['custom_width_in'],
				YeffoPrint_Cart_Item_Keys::CUSTOM_HEIGHT_IN => $sticker['custom_height_in'],
				YeffoPrint_Cart_Item_Keys::TOTAL_QTY        => $sticker['quantity'],
			];
			YeffoPrint_Order_Item_Meta::apply( $item, $values, $sticker['quantity'] );
			$item->save();
		}

		return true;
	}

	/**
	 * Same validation shape as class-cart-controller.php's own add() —
	 * kept local rather than shared, matching this file's own existing
	 * per-controller convention. One deliberate deviation from that
	 * controller: it treats an empty compatible-sizes/materials list as
	 * "no restriction" and never separately checks that a chosen size/
	 * material is itself a real, published record when the list is empty
	 * — harmless there since a live cart's own product data still gates
	 * checkout, but this path builds an order directly, so a size_id/
	 * material_id of 0 (nothing chosen) needs to be rejected outright
	 * rather than silently accepted.
	 *
	 * @return array{template_id:int, template_title:string, size_id:int, material_id:int, variants:array, instructions:string}|\WP_Error
	 */
	private static function validate_template_fields( array $payload ) {
		$template_id = absint( $payload['template_id'] ?? 0 );
		$template    = get_post( $template_id );
		if ( ! $template || 'yp_template' !== $template->post_type || 'publish' !== $template->post_status ) {
			return new \WP_Error( 'yeffoprint_invalid_template', __( 'Please choose a valid design.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$compatible_sizes     = array_map( 'absint', (array) get_post_meta( $template_id, YeffoPrint_Template_Meta::COMPATIBLE_SIZES, true ) );
		$compatible_materials = array_map( 'absint', (array) get_post_meta( $template_id, YeffoPrint_Template_Meta::COMPATIBLE_MATERIALS, true ) );

		$size_id = absint( $payload['size_id'] ?? 0 );
		if ( ! self::is_published( 'yp_size', $size_id ) || ( $compatible_sizes && ! in_array( $size_id, $compatible_sizes, true ) ) ) {
			return new \WP_Error( 'yeffoprint_invalid_size', __( 'Please choose a valid size.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$material_id = absint( $payload['material_id'] ?? 0 );
		if ( ! self::is_published( 'yp_material', $material_id ) || ( $compatible_materials && ! in_array( $material_id, $compatible_materials, true ) ) ) {
			return new \WP_Error( 'yeffoprint_invalid_material', __( 'Please choose a valid material.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		if ( ! (bool) get_post_meta( $material_id, YeffoPrint_Commerce_Record_Meta::IN_STOCK, true ) ) {
			return new \WP_Error( 'yeffoprint_material_out_of_stock', __( 'That material is currently out of stock. Please choose a different one.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$variants = YeffoPrint_Field_Schema::sanitize_variants( $payload['variants'] ?? null, YeffoPrint_Field_Schema::get( $template_id ) );
		if ( is_wp_error( $variants ) ) {
			return $variants;
		}

		return [
			'template_id'    => $template_id,
			'template_title' => $template->post_title,
			'size_id'        => $size_id,
			'material_id'    => $material_id,
			'variants'       => $variants,
			'instructions'   => sanitize_textarea_field( (string) ( $payload['instructions'] ?? '' ) ),
		];
	}

	/**
	 * Custom Stickers' single-line-item shape, not Custom Design's fee-
	 * plus-batch-rows one: a Template order has no separate fee item
	 * either (same reasoning as Custom Stickers — this is priced exactly
	 * like the customer-facing flow, proof approval is a staff opt-in
	 * addition here, not a paid design service). Adds exactly one line
	 * item to the Template's own linked product
	 * (YeffoPrint_Linked_Product), for the combined quantity across every
	 * variant in the batch — mirrors class-cart-controller.php::add()'s
	 * own add-to-cart step, just built directly on the order instead of
	 * through WC()->cart->add_to_cart().
	 *
	 * @return true|\WP_Error
	 */
	private static function add_template_row( \WC_Order $order, array $template, int $custom_order_id ) {
		$product_id = YeffoPrint_Linked_Product::get_linked_product_id( $template['template_id'] );
		if ( ! $product_id ) {
			return new \WP_Error( 'yeffoprint_no_template_product', __( 'This design is not orderable yet.', 'yeffoprint-core' ), [ 'status' => 503 ] );
		}
		$product = wc_get_product( $product_id );

		$total_quantity = array_sum( array_column( $template['variants'], 'quantity' ) );

		$values = [
			YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ID => $custom_order_id,
			YeffoPrint_Cart_Item_Keys::TEMPLATE_ID     => $template['template_id'],
			YeffoPrint_Cart_Item_Keys::SIZE_ID         => $template['size_id'],
			YeffoPrint_Cart_Item_Keys::MATERIAL_ID     => $template['material_id'],
			YeffoPrint_Cart_Item_Keys::VARIANTS        => $template['variants'],
			YeffoPrint_Cart_Item_Keys::TOTAL_QTY       => $total_quantity,
		];

		// No cart-wide pool to combine against — same reasoning as
		// add_sticker_row() above — this order's own combined variant
		// quantity is the whole tier pool.
		$pricing = YeffoPrint_Cart_Pricing::calculate_for_cart_item( $values, $total_quantity );
		$total   = $pricing['total'] ?? 0.0;

		$item_id = $order->add_product( $product, $total_quantity, [ 'subtotal' => $total, 'total' => $total ] );
		if ( ! $item_id ) {
			return new \WP_Error( 'yeffoprint_add_item_failed', __( "Couldn't add this design to the order.", 'yeffoprint-core' ), [ 'status' => 500 ] );
		}

		$item = $order->get_item( $item_id );
		if ( $item instanceof \WC_Order_Item_Product ) {
			YeffoPrint_Order_Item_Meta::apply( $item, $values, $total_quantity );
			$item->save();
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
