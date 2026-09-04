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
 * Shipping/billing address + shipping cost — direct request: "I need
 * the ability to verify the shipping/billing address for the customer
 * before finalizing, also need to be able to select a shipping method
 * so shipping can be added to the invoice." Address verification
 * happens client-side against class-admin-manual-order-controller.php's
 * own /admin/manual-orders/verify-address route *before* this method is
 * ever called; the shipping method is a flat admin-edited preset picked
 * client-side too (Settings → Shipping → "Manual order shipping
 * options," no live rate-shop). This class only receives the
 * already-chosen address and shipping-method payload, applies the
 * address to the order's own shipping/billing fields, and adds the
 * method as a real WC_Order_Item_Shipping line item. See
 * sanitize_address() and add_shipping_line() below.
 *
 * Customer address prefill + save-back (this revision) — direct
 * follow-up: "can it pull their existing address from their profile if
 * it has it filled out? ... if they dont have one, when I key it into
 * that order, it should update their account with their address for
 * future use." The prefill half lives client-side
 * (class-admin-manual-order-controller.php's own /admin/manual-orders/
 * customer/{id}/address route, fetched the moment staff pick an
 * existing customer) — this class only handles the other direction,
 * writing the address back onto the customer's own WooCommerce account
 * once the order is built, via maybe_save_address_to_profile() below.
 *
 * "Requires proof approval" is not a separate feature bolted on here —
 * it's the same seam `submit()` already relies on:
 * `_yp_custom_order_id` on a line item is what
 * `YeffoPrint_Custom_Order_Payment::link_paid_custom_orders()` (hooked
 * to `woocommerce_order_status_processing`) looks for to publish/link a
 * `yp_custom_order` shell. Mint that shell first when requested, thread
 * its ID into every added item, then actually transition the order to
 * 'processing' — that hook does the rest for free, no new linking code.
 *
 * Mixed orders (this revision) — direct request: "customers order
 * custom design items mixed with template items... I need the
 * ability... to order them at the same time." `$payload` no longer
 * carries one `order_type` picking exactly one of Custom Design/Custom
 * Stickers/Template Label; instead it carries an optional `custom_design`/
 * `sticker`/`template` key for each kind of item this order should
 * include — any combination, added onto the same WC_Order. Each present
 * kind still gets its own separate proof-approval shell when
 * `requires_proof` is set, rather than one shell straining to represent
 * two genuinely different design tasks — `link_paid_custom_orders()`
 * already dedupes/publishes by whichever distinct `_yp_custom_order_id`
 * values it finds across an order's items, so multiple shells sharing
 * one order needed no changes there at all. The one other place that
 * silently assumed "at most one shell per order" was the Telegram
 * bot's own order-status reply (class-telegram-order-lookup.php),
 * fixed alongside this to list every shell's status instead of just
 * the first one `get_posts()` happened to return.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Manual_Order_Creator {

	/** Maps a present payload group key to the yp_custom_order shell "type" it needs (YeffoPrint_Custom_Order_Meta::ORDER_TYPES) — same mapping the old single order_type dispatch used, just keyed by group now that more than one can be present at once. */
	private const SHELL_TYPES = [
		'custom_design' => 'label',
		'sticker'       => 'sticker',
		'template'      => 'template',
	];

	/**
	 * @param array $payload {
	 *     @type array  $customer      { @type int $id } OR { @type string $name, @type string $email }.
	 *     @type array  $custom_design Optional — { brand_name, batch: [ { size_id, material_id, quantity,
	 *                                 compound_strength } ], style_notes, instructions, waive_design_fee }.
	 *                                 waive_design_fee: direct request — staff need to be able to waive
	 *                                 the $25 design fee on some manual orders (VIP customer, goodwill,
	 *                                 etc), which has no equivalent on the other two groups (neither has a
	 *                                 flat fee to waive in the first place).
	 *     @type array  $sticker       Optional — { size_id, material_id, sticker_type, shape, quantity,
	 *                                 custom_width_in, custom_height_in, instructions, uploads } — uploads:
	 *                                 attachment IDs, already uploaded via /custom-orders/uploads.
	 *     @type array  $template      Optional — { template_id, size_id, material_id,
	 *                                 variants: [ { quantity, values: { field_id: value } } ], instructions }.
	 *                                 At least one of $custom_design/$sticker/$template is required — direct
	 *                                 request: "customers order custom design items mixed with template
	 *                                 items... I need the ability... to order them at the same time." Any
	 *                                 combination of the three adds its own line item(s) onto this one
	 *                                 order; see the class docblock above for how proof approval handles
	 *                                 more than one group being present at once.
	 *     @type bool   $requires_proof
	 *     @type bool   $send_invoice_email  Direct request: email the customer their order details and a
	 *                                       payment link right on creation, via WooCommerce's own built-in
	 *                                       Order details/Customer Invoice email.
	 *     @type array  $shipping_address  Optional — { first_name, last_name, address_1, address_2, city,
	 *                                     state, postcode, country, phone }. Left out (or every field left
	 *                                     blank) entirely skips setting a shipping address, same as before
	 *                                     this field existed — staff can still add one later from the order
	 *                                     screen. A partially filled address (some but not all of address_1/
	 *                                     city/state/postcode/country) is rejected rather than silently saved
	 *                                     incomplete.
	 *     @type array  $billing_address   Optional, same shape as $shipping_address — only needed when
	 *                                     billing differs from shipping; mirrors $shipping_address otherwise.
	 *     @type array  $shipping         Optional — { carrier_label, amount }, the flat shipping-method
	 *                                     preset staff picked (Settings → Shipping → "Manual order shipping
	 *                                     options" — no live rate-shop). Added as a real shipping line item
	 *                                     on the order, included in calculate_totals() below — this doesn't
	 *                                     purchase a label, just adds the cost to the invoice; the actual
	 *                                     label is purchased later from the order screen's own Shippo panel,
	 *                                     same as any other order.
	 * }
	 * @return array{order:\WC_Order, custom_orders: array<int, array{id:int, order_type:string}>}|\WP_Error
	 */
	public static function create( array $payload ) {
		$groups = self::validate_groups( $payload );
		if ( is_wp_error( $groups ) ) {
			return $groups;
		}

		$shipping_address = self::sanitize_address( $payload['shipping_address'] ?? null );
		if ( is_wp_error( $shipping_address ) ) {
			return $shipping_address;
		}

		$billing_address = null;
		if ( ! empty( $payload['billing_address'] ) ) {
			$billing_address = self::sanitize_address( $payload['billing_address'] );
			if ( is_wp_error( $billing_address ) ) {
				return $billing_address;
			}
		}
		if ( ! $billing_address ) {
			$billing_address = $shipping_address;
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

		if ( $shipping_address ) {
			$order->set_shipping_first_name( $shipping_address['first_name'] ?: $order->get_billing_first_name() );
			$order->set_shipping_last_name( $shipping_address['last_name'] ?: $order->get_billing_last_name() );
			$order->set_shipping_address_1( $shipping_address['address_1'] );
			$order->set_shipping_address_2( $shipping_address['address_2'] );
			$order->set_shipping_city( $shipping_address['city'] );
			$order->set_shipping_state( $shipping_address['state'] );
			$order->set_shipping_postcode( $shipping_address['postcode'] );
			$order->set_shipping_country( $shipping_address['country'] );
			$order->set_shipping_phone( $shipping_address['phone'] );
		}

		if ( $billing_address ) {
			// Only overrides the name defaults set just above when the
			// address form's own name fields were actually filled in —
			// staff commonly leave those blank and just fill in the
			// street/city/state, relying on the customer's own name.
			$order->set_billing_first_name( $billing_address['first_name'] ?: $order->get_billing_first_name() );
			$order->set_billing_last_name( $billing_address['last_name'] ?: $order->get_billing_last_name() );
			$order->set_billing_address_1( $billing_address['address_1'] );
			$order->set_billing_address_2( $billing_address['address_2'] );
			$order->set_billing_city( $billing_address['city'] );
			$order->set_billing_state( $billing_address['state'] );
			$order->set_billing_postcode( $billing_address['postcode'] );
			$order->set_billing_country( $billing_address['country'] );
			$order->set_billing_phone( $billing_address['phone'] );
		}

		// Direct request: "if they dont have one, when I key it into that
		// order, it should update their account with their address for
		// future use." Only ever fills a gap — never overwrites an address
		// already on the customer's account, since staff typing a
		// different one for this particular order (a gift, a one-off
		// destination) isn't necessarily meant to replace their usual one.
		self::maybe_save_address_to_profile( $customer->ID, $shipping_address, $billing_address );

		// Direct request: "customers order custom design items mixed
		// with template items... order them at the same time." $groups
		// can now hold more than one of custom_design/sticker/template
		// at once — every present one is added to this SAME order,
		// rather than the old "exactly one order_type per order" rule.
		// Each still gets its OWN proof-approval shell when requested
		// (see the class docblock above for why one shell per group
		// rather than one shell for the whole order).
		$requires_proof = ! empty( $payload['requires_proof'] );
		$custom_orders  = [];

		foreach ( $groups as $type => $group ) {
			$custom_order_id = 0;

			if ( $requires_proof ) {
				$custom_order_id = YeffoPrint_Custom_Order_Meta::create_shell(
					self::SHELL_TYPES[ $type ],
					self::shell_title( $type, $group ),
					$customer->ID,
					$customer->user_email,
					trim( $customer->first_name . ' ' . $customer->last_name ) ?: $customer->display_name
				);

				if ( ! $custom_order_id ) {
					self::rollback( $order, $custom_orders );
					return new \WP_Error( 'yeffoprint_custom_order_failed', __( "Couldn't create the linked proof-approval record. Please try again.", 'yeffoprint-core' ), [ 'status' => 500 ] );
				}
			}

			if ( 'custom_design' === $type ) {
				$result = self::add_custom_design_rows( $order, $group['batch'], $custom_order_id, $group['waive_design_fee'] );
			} elseif ( 'sticker' === $type ) {
				$result = self::add_sticker_row( $order, $group, $custom_order_id );
			} else {
				$result = self::add_template_row( $order, $group, $custom_order_id );
			}

			if ( is_wp_error( $result ) ) {
				if ( $custom_order_id ) {
					wp_delete_post( $custom_order_id, true );
				}
				self::rollback( $order, $custom_orders );
				return $result;
			}

			if ( $custom_order_id ) {
				self::populate_shell_meta( $custom_order_id, $type, $group );
				$custom_orders[] = [ 'id' => $custom_order_id, 'order_type' => $type ];
			}
		}

		if ( ! empty( $payload['shipping'] ) && is_array( $payload['shipping'] ) ) {
			self::add_shipping_line( $order, $payload['shipping'] );
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

		return [ 'order' => $order, 'custom_orders' => $custom_orders ];
	}

	/** Shared by validate_groups() below — a title for the proof-approval shell about to be created for one present group, same "brand/design name — timestamp" shape every order type already used when only one shell per order was possible. */
	private static function shell_title( string $type, array $group ): string {
		if ( 'custom_design' === $type ) {
			return sprintf( '%s — %s', $group['brand_name'], current_time( 'Y-m-d H:i' ) );
		}
		if ( 'sticker' === $type ) {
			/* translators: %s: submission date/time */
			return sprintf( __( 'Custom Stickers — %s', 'yeffoprint-core' ), current_time( 'Y-m-d H:i' ) );
		}
		return sprintf( '%s — %s', $group['template_title'], current_time( 'Y-m-d H:i' ) );
	}

	/** Writes one group's own type-specific fields onto its own just-created shell — same per-type meta this class always wrote, just factored out so create()'s per-group loop above can call it for however many shells this order ends up with instead of exactly one. */
	private static function populate_shell_meta( int $custom_order_id, string $type, array $group ): void {
		if ( 'custom_design' === $type ) {
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::BRAND_NAME, $group['brand_name'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::BATCH, wp_json_encode( $group['batch'] ) );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::SIZE_ID, $group['batch'][0]['size_id'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::MATERIAL_ID, $group['batch'][0]['material_id'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::QUANTITY, $group['batch'][0]['quantity'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::COMPOUND_STRENGTH, $group['batch'][0]['compound_strength'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STYLE_NOTES, $group['style_notes'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::INSTRUCTIONS, $group['instructions'] );
			return;
		}

		if ( 'sticker' === $type ) {
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::SIZE_ID, $group['size_id'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::MATERIAL_ID, $group['material_id'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::QUANTITY, $group['quantity'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::STICKER_TYPE, $group['sticker_type'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::SHAPE, $group['shape'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOM_WIDTH_IN, $group['custom_width_in'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::CUSTOM_HEIGHT_IN, $group['custom_height_in'] );
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::INSTRUCTIONS, $group['instructions'] );
			// Optional here unlike the customer-facing form's hard
			// requirement — staff placing a phone/email order often
			// haven't received the artwork file yet and shouldn't be
			// blocked from getting the order into the pipeline; the
			// customer's proof-approval flow (once a proof is uploaded)
			// is what actually needs artwork in hand, not this step.
			if ( $group['uploads'] ) {
				update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::ARTWORK_UPLOADS, $group['uploads'] );
			}
			return;
		}

		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::TEMPLATE_ID, $group['template_id'] );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::SIZE_ID, $group['size_id'] );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::MATERIAL_ID, $group['material_id'] );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::TEMPLATE_VARIANTS, wp_json_encode( $group['variants'] ) );
		update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::INSTRUCTIONS, $group['instructions'] );
	}

	/**
	 * Validates every group present in $payload (any combination of
	 * custom_design/sticker/template — direct request: "order them at
	 * the same time") using this class's own existing per-type
	 * validation (validate_batch_rows()/validate_sticker_fields()/
	 * validate_template_fields(), all unchanged — each already reads
	 * from a flat array shaped exactly like one group's own payload
	 * sub-array, so they need no changes to be handed that sub-array
	 * directly instead of the top-level payload a single-group order
	 * used to pass them).
	 *
	 * @return array<string, array>|\WP_Error Keyed by 'custom_design'/'sticker'/'template' — only the
	 *                                        groups actually present, each already validated/sanitized.
	 */
	private static function validate_groups( array $payload ) {
		$groups = [];

		if ( ! empty( $payload['custom_design'] ) && is_array( $payload['custom_design'] ) ) {
			$raw = $payload['custom_design'];

			$brand_name = sanitize_text_field( (string) ( $raw['brand_name'] ?? '' ) );
			if ( '' === $brand_name ) {
				return new \WP_Error( 'yeffoprint_missing_brand_name', __( 'Brand name is required.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}

			$batch = self::validate_batch_rows( $raw['batch'] ?? null );
			if ( is_wp_error( $batch ) ) {
				return $batch;
			}

			$groups['custom_design'] = [
				'brand_name'       => $brand_name,
				'batch'            => $batch,
				'style_notes'      => sanitize_textarea_field( (string) ( $raw['style_notes'] ?? '' ) ),
				'instructions'     => sanitize_textarea_field( (string) ( $raw['instructions'] ?? '' ) ),
				'waive_design_fee' => ! empty( $raw['waive_design_fee'] ),
			];
		}

		if ( ! empty( $payload['sticker'] ) && is_array( $payload['sticker'] ) ) {
			$sticker = self::validate_sticker_fields( $payload['sticker'] );
			if ( is_wp_error( $sticker ) ) {
				return $sticker;
			}
			$groups['sticker'] = $sticker;
		}

		if ( ! empty( $payload['template'] ) && is_array( $payload['template'] ) ) {
			$template = self::validate_template_fields( $payload['template'] );
			if ( is_wp_error( $template ) ) {
				return $template;
			}
			$groups['template'] = $template;
		}

		if ( ! $groups ) {
			return new \WP_Error( 'yeffoprint_empty_order', __( 'Add at least one item — Custom Design, Custom Stickers, or a Template Label — before creating the order.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		return $groups;
	}

	/** Shared cleanup for create()'s per-group loop: an order (and any proof shells already minted for earlier groups in this same attempt) must never survive a later group failing to validate/add — called from both failure points in that loop. */
	private static function rollback( \WC_Order $order, array $custom_orders ): void {
		$order->delete( true );
		foreach ( $custom_orders as $created ) {
			wp_delete_post( $created['id'], true );
		}
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

	/**
	 * Sanitizes a raw shipping/billing address submission. Field names
	 * match WC_Order's own setters (first_name/last_name/address_1/
	 * address_2/city/state/postcode/country/phone) rather than Shippo's
	 * (name/street1/zip/…) — the request/response shape Shippo actually
	 * wants is built separately, right before each Shippo call
	 * (class-admin-manual-order-controller.php's own address_to_shippo()),
	 * since this method's job is only "is this a usable order address."
	 *
	 * Every field blank is a deliberate, valid "no address yet" — staff
	 * can add one later from the order screen, same as before this
	 * feature existed. A *partial* address (some but not all of
	 * address_1/city/state/postcode/country filled in) is rejected
	 * outright rather than silently saved incomplete, since that's far
	 * more likely to be an oversight than an intentional partial address.
	 *
	 * @return array{first_name:string,last_name:string,address_1:string,address_2:string,city:string,state:string,postcode:string,country:string,phone:string}|null|\WP_Error
	 */
	private static function sanitize_address( $raw ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$fields = [
			'first_name' => sanitize_text_field( (string) ( $raw['first_name'] ?? '' ) ),
			'last_name'  => sanitize_text_field( (string) ( $raw['last_name'] ?? '' ) ),
			'address_1'  => sanitize_text_field( (string) ( $raw['address_1'] ?? '' ) ),
			'address_2'  => sanitize_text_field( (string) ( $raw['address_2'] ?? '' ) ),
			'city'       => sanitize_text_field( (string) ( $raw['city'] ?? '' ) ),
			'state'      => sanitize_text_field( (string) ( $raw['state'] ?? '' ) ),
			'postcode'   => sanitize_text_field( (string) ( $raw['postcode'] ?? '' ) ),
			'country'    => strtoupper( sanitize_text_field( (string) ( $raw['country'] ?? '' ) ) ),
			'phone'      => sanitize_text_field( (string) ( $raw['phone'] ?? '' ) ),
		];

		if ( '' === implode( '', $fields ) ) {
			return null;
		}

		foreach ( [ 'address_1', 'city', 'state', 'postcode', 'country' ] as $required_key ) {
			if ( '' === $fields[ $required_key ] ) {
				return new \WP_Error( 'yeffoprint_incomplete_address', __( 'Please fill in a complete address (street, city, state, ZIP/postal code, country) or leave every address field blank.', 'yeffoprint-core' ), [ 'status' => 400 ] );
			}
		}

		return $fields;
	}

	/**
	 * Adds the staff-selected Shippo rate as a real shipping line item —
	 * cost only, no label purchase happens here (that's the order
	 * screen's own existing Shippo panel, once the order exists). Silently
	 * does nothing for a $shipping array with neither a usable amount nor
	 * a carrier/service name, so a stray empty object in the payload never
	 * adds a blank "$0.00 Shipping" line to an order that never actually
	 * had a rate selected.
	 */
	private static function add_shipping_line( \WC_Order $order, array $shipping ): void {
		$amount       = max( 0.0, (float) ( $shipping['amount'] ?? 0 ) );
		$carrier      = sanitize_text_field( (string) ( $shipping['carrier_label'] ?? '' ) );
		$service      = sanitize_text_field( (string) ( $shipping['service'] ?? '' ) );
		$method_title = trim( $carrier . ' ' . $service );

		if ( '' === $method_title && $amount <= 0 ) {
			return;
		}

		$item = new \WC_Order_Item_Shipping();
		$item->set_method_title( '' !== $method_title ? $method_title : __( 'Shipping', 'yeffoprint-core' ) );
		$item->set_method_id( 'yeffoprint_shippo' );
		$item->set_total( $amount );
		$order->add_item( $item );
	}

	/**
	 * Direct request: "if they dont have one, when I key it into that
	 * order, it should update their account with their address for
	 * future use." Fills in whichever half (shipping/billing) of the
	 * customer's own WooCommerce address is currently empty — never
	 * overwrites a half that's already on file, since staff typing a
	 * different address for this one order (a gift, a one-off
	 * destination) isn't necessarily meant to replace the customer's
	 * usual saved address. A brand-new customer (resolve_or_create_
	 * customer() above) always has both halves empty, so this always
	 * saves for them.
	 */
	private static function maybe_save_address_to_profile( int $user_id, ?array $shipping_address, ?array $billing_address ): void {
		if ( ! $shipping_address && ! $billing_address ) {
			return;
		}

		$customer = new \WC_Customer( $user_id );
		$changed  = false;

		if ( $shipping_address && '' === trim( $customer->get_shipping_address_1() ) ) {
			$customer->set_shipping_first_name( $shipping_address['first_name'] );
			$customer->set_shipping_last_name( $shipping_address['last_name'] );
			$customer->set_shipping_address_1( $shipping_address['address_1'] );
			$customer->set_shipping_address_2( $shipping_address['address_2'] );
			$customer->set_shipping_city( $shipping_address['city'] );
			$customer->set_shipping_state( $shipping_address['state'] );
			$customer->set_shipping_postcode( $shipping_address['postcode'] );
			$customer->set_shipping_country( $shipping_address['country'] );
			$customer->set_shipping_phone( $shipping_address['phone'] );
			$changed = true;
		}

		if ( $billing_address && '' === trim( $customer->get_billing_address_1() ) ) {
			$customer->set_billing_first_name( $billing_address['first_name'] );
			$customer->set_billing_last_name( $billing_address['last_name'] );
			$customer->set_billing_address_1( $billing_address['address_1'] );
			$customer->set_billing_address_2( $billing_address['address_2'] );
			$customer->set_billing_city( $billing_address['city'] );
			$customer->set_billing_state( $billing_address['state'] );
			$customer->set_billing_postcode( $billing_address['postcode'] );
			$customer->set_billing_country( $billing_address['country'] );
			$customer->set_billing_phone( $billing_address['phone'] );
			$changed = true;
		}

		if ( $changed ) {
			$customer->save();
		}
	}
}
