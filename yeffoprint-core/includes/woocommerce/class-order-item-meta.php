<?php
/**
 * Freezes a batch's structured data onto the order line item at
 * checkout — Architecture §5: order metadata must be able to
 * re-render the exact configurator state (future "Edit"/"Reorder")
 * and preserve which PricingRule version priced the order, even after
 * Templates/Sizes/Materials/pricing change later. Also handles the
 * custom design fee line item (PROJECT_SPEC §13), which just needs a
 * back-reference to its yp_custom_order record — the actual linking/
 * status-advancing happens on payment, see class-custom-order-payment.php.
 *
 * Deliberately separate, named meta keys per fact (template snapshot,
 * size snapshot, material snapshot, pricing snapshot, variants) rather
 * than one blob — PROJECT_SPEC §14: "never one opaque serialized
 * blob." `_yp_variants` is itself one JSON array because a batch's
 * variant list is inherently one repeating structured unit — same
 * reasoning already applied to field_schema in Phase 4.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Item_Meta {

	/**
	 * True only while WC is actually building the HTML variant of a
	 * transactional email (set on woocommerce_email_header, cleared on
	 * woocommerce_email_footer — WC's plain-text templates never call
	 * either, so this stays false for the whole of a plain-text send).
	 * format_customization_display() below reads this because, unlike
	 * render_customization_email_fields()'s woocommerce_email_after_
	 * order_table hook, the woocommerce_order_item_get_formatted_meta_data
	 * filter it runs on isn't handed any html-vs-plain-text context of
	 * its own.
	 */
	private bool $rendering_html_email = false;

	public function __construct() {
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'snapshot' ], 10, 4 );
		add_filter( 'woocommerce_hidden_order_itemmeta', [ $this, 'hide_internal_keys' ] );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', [ $this, 'add_qr_download_links' ], 10, 2 );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', [ $this, 'format_customization_display' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_order_screen_assets' ] );
		add_action( 'woocommerce_email_header', [ $this, 'mark_html_email' ] );
		add_action( 'woocommerce_email_footer', [ $this, 'unmark_html_email' ] );
		add_action( 'woocommerce_email_after_order_table', [ $this, 'render_customization_email_fields' ], 10, 4 );
	}

	public function mark_html_email(): void {
		$this->rendering_html_email = true;
	}

	public function unmark_html_email(): void {
		$this->rendering_html_email = false;
	}

	public function snapshot( \WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order ): void {
		self::apply( $item, $values );
	}

	/**
	 * The actual snapshot logic — split out from snapshot() above so it's
	 * callable outside the `woocommerce_checkout_create_order_line_item`
	 * hook, which only ever fires inside `WC_Checkout::create_order()`.
	 * Never read `$cart_item_key`/`$order` to begin with, so this is a
	 * pure extraction: same behavior, just usable by code building an
	 * order directly (e.g. class-manual-order-creator.php) instead of
	 * through checkout.
	 *
	 * $tier_quantity: left null for the checkout hook (unchanged
	 * behavior — YeffoPrint_Cart_Pricing::calculate_for_cart_item()
	 * defaults it to the live cart's own combined quantity, correct
	 * there since checkout always runs against a real, fully-populated
	 * cart). A caller with no cart at all — the manual-order creator,
	 * which never touches WC()->cart — passes the combined quantity of
	 * its own batch explicitly instead, so the price snapshotted here
	 * matches the price it actually charged rather than silently
	 * defaulting to an empty/irrelevant admin session cart.
	 */
	public static function apply( \WC_Order_Item_Product $item, array $values, ?int $tier_quantity = null ): void {
		$custom_order_id = (int) ( $values[ YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ID ] ?? 0 );

		// Checked first, same reason as class-cart-pricing.php's
		// apply_price(): a sticker item also carries CUSTOM_ORDER_ID and
		// TOTAL_QTY, same shape as a Custom Design labels item, but
		// needs its own snapshot fields (type/shape/custom dimensions).
		if ( $custom_order_id && ! empty( $values[ YeffoPrint_Cart_Item_Keys::STICKER_TYPE ] ) ) {
			self::snapshot_custom_sticker( $item, $custom_order_id, $values, $tier_quantity );
			return;
		}

		if ( $custom_order_id && empty( $values[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ) ) {
			self::snapshot_custom_order_fee( $item, $custom_order_id );
			return;
		}

		if ( empty( $values[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ) ) {
			return;
		}

		$size_id     = (int) ( $values[ YeffoPrint_Cart_Item_Keys::SIZE_ID ] ?? 0 );
		$material_id = (int) ( $values[ YeffoPrint_Cart_Item_Keys::MATERIAL_ID ] ?? 0 );
		$quantity    = (int) $values[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ];
		$pricing     = YeffoPrint_Cart_Pricing::calculate_for_cart_item( $values, $tier_quantity );
		$template_id = (int) ( $values[ YeffoPrint_Cart_Item_Keys::TEMPLATE_ID ] ?? 0 );

		// TEMPLATE_ID is checked first: a manually-created Template order
		// requiring proof approval (class-manual-order-creator.php) also
		// carries CUSTOM_ORDER_ID, same as a Custom Design labels row, but
		// it's a real Template + field_schema/variants underneath, not a
		// Custom Design batch row — the customer-facing checkout flow
		// never sets both at once (a Template add-to-cart never has a
		// CUSTOM_ORDER_ID; a Custom Design labels row never has a
		// TEMPLATE_ID), so this only ever branches for that one new path.
		if ( $custom_order_id && ! $template_id ) {
			// A Custom Order's own labels: same Size/Material/pricing
			// snapshot shape as a Template batch, but there's no
			// template/field_schema/variants behind it (Architecture §2).
			// row_index/compound_strength are only ever present for a
			// batched Custom Design order (one cart item per row) — see
			// class-cart-item-keys.php's own doc comment for why a batch
			// splits across multiple line items here specifically.
			$row_index         = (int) ( $values[ YeffoPrint_Cart_Item_Keys::CUSTOM_ORDER_ROW_INDEX ] ?? 0 );
			$compound_strength = (string) ( $values[ YeffoPrint_Cart_Item_Keys::COMPOUND_STRENGTH ] ?? '' );
			self::snapshot_custom_order_labels( $item, $custom_order_id, $size_id, $material_id, $quantity, $pricing, $row_index, $compound_strength );
			return;
		}

		$variants     = (array) ( $values[ YeffoPrint_Cart_Item_Keys::VARIANTS ] ?? [] );
		$field_schema = $template_id ? YeffoPrint_Field_Schema::get( $template_id ) : [];

		if ( $custom_order_id ) {
			// Only ever set here for a manually-created Template order
			// with proof approval requested — an ordinary checkout-driven
			// Template batch never has one. Same _yp_custom_order_id key
			// link_paid_custom_orders() already scans every line item for,
			// generically, regardless of product — no new linking code
			// needed for this to work.
			$item->add_meta_data( '_yp_custom_order_id', $custom_order_id, true );
		}

		$item->add_meta_data( '_yp_template_snapshot', wp_json_encode( [
			'id'           => $template_id,
			'title'        => $template_id ? get_the_title( $template_id ) : '',
			'field_schema' => $field_schema,
		] ), true );

		$item->add_meta_data( '_yp_size_snapshot', wp_json_encode( self::record_snapshot( $size_id ) ), true );
		$item->add_meta_data( '_yp_material_snapshot', wp_json_encode( self::record_snapshot( $material_id ) ), true );
		$item->add_meta_data( '_yp_variants', wp_json_encode( $variants ), true );
		$item->add_meta_data( '_yp_batch_quantity', $quantity, true );
		$item->add_meta_data( '_yp_pricing_snapshot', wp_json_encode( $pricing ), true );

		// Human-readable rows for the admin order screen / customer
		// emails, alongside the machine-readable snapshots above.
		$item->add_meta_data( __( 'Size', 'yeffoprint-core' ), $size_id ? get_the_title( $size_id ) : '—', true );
		$item->add_meta_data( __( 'Material', 'yeffoprint-core' ), $material_id ? get_the_title( $material_id ) : '—', true );
		$item->add_meta_data( __( 'Labels in this batch', 'yeffoprint-core' ), count( $variants ), true );

		self::add_variant_rows( $item, $variants, $field_schema );
	}

	private static function snapshot_custom_order_labels( \WC_Order_Item_Product $item, int $custom_order_id, int $size_id, int $material_id, int $quantity, ?array $pricing, int $row_index = 0, string $compound_strength = '' ): void {
		$item->add_meta_data( '_yp_custom_order_id', $custom_order_id, true );
		$item->add_meta_data( '_yp_size_snapshot', wp_json_encode( self::record_snapshot( $size_id ) ), true );
		$item->add_meta_data( '_yp_material_snapshot', wp_json_encode( self::record_snapshot( $material_id ) ), true );
		$item->add_meta_data( '_yp_batch_quantity', $quantity, true );
		$item->add_meta_data( '_yp_pricing_snapshot', wp_json_encode( $pricing ), true );
		// Batching-only fields — a pre-batching order's single labels item
		// still gets row_index 0 and an empty compound_strength, harmlessly.
		$item->add_meta_data( '_yp_batch_row_index', $row_index, true );
		$item->add_meta_data( '_yp_compound_strength_snapshot', $compound_strength, true );

		$item->add_meta_data( __( 'Size', 'yeffoprint-core' ), $size_id ? get_the_title( $size_id ) : '—', true );
		$item->add_meta_data( __( 'Material', 'yeffoprint-core' ), $material_id ? get_the_title( $material_id ) : '—', true );
		$item->add_meta_data( __( 'Quantity', 'yeffoprint-core' ), $quantity, true );
		if ( '' !== $compound_strength ) {
			$item->add_meta_data( __( 'Compound/Strength', 'yeffoprint-core' ), $compound_strength, true );
		}
	}

	/** Custom Stickers' own line item — same shape as snapshot_custom_order_labels() above, this flow's own fields (type/shape/size, including the custom-dimensions tier) instead. */
	private static function snapshot_custom_sticker( \WC_Order_Item_Product $item, int $custom_order_id, array $values, ?int $tier_quantity = null ): void {
		$size_id           = (int) ( $values[ YeffoPrint_Cart_Item_Keys::SIZE_ID ] ?? 0 );
		$material_id       = (int) ( $values[ YeffoPrint_Cart_Item_Keys::MATERIAL_ID ] ?? 0 );
		$sticker_type      = (string) ( $values[ YeffoPrint_Cart_Item_Keys::STICKER_TYPE ] ?? '' );
		$shape             = (string) ( $values[ YeffoPrint_Cart_Item_Keys::SHAPE ] ?? '' );
		$custom_width_in   = (float) ( $values[ YeffoPrint_Cart_Item_Keys::CUSTOM_WIDTH_IN ] ?? 0 );
		$custom_height_in  = (float) ( $values[ YeffoPrint_Cart_Item_Keys::CUSTOM_HEIGHT_IN ] ?? 0 );
		$quantity          = (int) ( $values[ YeffoPrint_Cart_Item_Keys::TOTAL_QTY ] ?? 0 );
		$is_custom_size    = $size_id && (bool) get_post_meta( $size_id, YeffoPrint_Sticker_Size_Meta::IS_CUSTOM, true );
		$pricing           = YeffoPrint_Cart_Pricing::calculate_sticker_for_cart_item( $values, $tier_quantity );

		$item->add_meta_data( '_yp_custom_order_id', $custom_order_id, true );
		$item->add_meta_data( '_yp_size_snapshot', wp_json_encode( self::record_snapshot( $size_id ) ), true );
		$item->add_meta_data( '_yp_material_snapshot', wp_json_encode( self::record_snapshot( $material_id ) ), true );
		$item->add_meta_data( '_yp_sticker_type', $sticker_type, true );
		$item->add_meta_data( '_yp_shape', $shape, true );
		$item->add_meta_data( '_yp_batch_quantity', $quantity, true );
		$item->add_meta_data( '_yp_pricing_snapshot', wp_json_encode( $pricing ), true );

		$item->add_meta_data( __( 'Type', 'yeffoprint-core' ), YeffoPrint_Sticker_Pricing::TYPES[ $sticker_type ] ?? '—', true );
		$item->add_meta_data( __( 'Shape', 'yeffoprint-core' ), YeffoPrint_Sticker_Pricing::SHAPES[ $shape ] ?? '—', true );
		$item->add_meta_data(
			__( 'Size', 'yeffoprint-core' ),
			$is_custom_size
				? sprintf( /* translators: 1: width in inches, 2: height in inches */ __( 'Custom: %1$s" × %2$s"', 'yeffoprint-core' ), $custom_width_in, $custom_height_in )
				: ( $size_id ? get_the_title( $size_id ) : '—' ),
			true
		);
		$item->add_meta_data( __( 'Material', 'yeffoprint-core' ), $material_id ? get_the_title( $material_id ) : '—', true );
		$item->add_meta_data( __( 'Quantity', 'yeffoprint-core' ), $quantity, true );
	}

	/**
	 * The actual per-label customization (compound, strength, brand
	 * name — whatever the Template's field_schema defines) was
	 * previously only stored in the hidden _yp_variants JSON, invisible
	 * on the order screen and in customer emails even though it's the
	 * one thing staff actually need to know what to print.
	 */
	private static function add_variant_rows( \WC_Order_Item_Product $item, array $variants, array $field_schema ): void {
		$multiple = count( $variants ) > 1;

		foreach ( $variants as $index => $variant ) {
			$summary = YeffoPrint_Field_Schema::format_variant_summary( $variant, $field_schema );
			if ( '' === $summary ) {
				continue;
			}

			$row_label = $multiple
				? sprintf(
					/* translators: 1: label number within the batch, 2: that label's own quantity */
					__( 'Label %1$d (qty %2$d)', 'yeffoprint-core' ),
					$index + 1,
					(int) ( $variant['quantity'] ?? 0 )
				)
				: __( 'Customization', 'yeffoprint-core' );

			$item->add_meta_data( $row_label, $summary, true );
		}
	}

	private static function snapshot_custom_order_fee( \WC_Order_Item_Product $item, int $custom_order_id ): void {
		$item->add_meta_data( '_yp_custom_order_id', $custom_order_id, true );
		$item->add_meta_data( __( 'Custom Design Request', 'yeffoprint-core' ), $custom_order_id ? get_the_title( $custom_order_id ) : '—', true );
	}

	private static function record_snapshot( int $post_id ): array {
		if ( ! $post_id ) {
			return [];
		}

		return [
			'id'               => $post_id,
			'name'             => get_the_title( $post_id ),
			'price_adjustment' => (float) get_post_meta( $post_id, YeffoPrint_Commerce_Record_Meta::PRICE_ADJUSTMENT, true ),
		];
	}

	/**
	 * Admin-only: appends a "Download PNG / PDF" row per qr_code field
	 * that has a value on this order item, so staff producing the order
	 * can pull a print-ready file straight from the order screen
	 * (direct request: "so I don't need to use a 3rd party site")
	 * instead of retyping the URL into some other QR generator.
	 *
	 * Reuses the same public /qr REST endpoint the configurator's live
	 * preview already calls (class-qr-controller.php) — QR rendering is
	 * a pure function of the text, so there's no order-specific
	 * generation logic to duplicate here, just a link built from data
	 * already on the item. Reads field types from the order's own
	 * frozen `_yp_template_snapshot.field_schema`, not the Template's
	 * current (possibly since-edited) schema — same "what was actually
	 * purchased" snapshot principle as pricing/size/material above.
	 */
	public function add_qr_download_links( array $formatted_meta, \WC_Order_Item $item ): array {
		if ( ! $this->is_order_edit_screen() ) {
			return $formatted_meta;
		}

		$batch = $this->get_batch_data( $item );
		if ( ! $batch ) {
			return $formatted_meta;
		}

		$qr_fields = array_filter( $batch['field_schema'], static function ( $field ) {
			return 'qr_code' === ( $field['type'] ?? '' );
		} );

		if ( ! $qr_fields ) {
			return $formatted_meta;
		}

		$multiple = count( $batch['variants'] ) > 1;
		$suffix   = 0;

		foreach ( $batch['variants'] as $index => $variant ) {
			foreach ( $qr_fields as $field ) {
				$url = trim( (string) ( $variant['values'][ $field['id'] ] ?? '' ) );
				if ( '' === $url ) {
					continue;
				}

				$png_url = add_query_arg( [ 'text' => rawurlencode( $url ), 'format' => 'png', 'download' => 1 ], rest_url( 'yeffoprint-core/v1/qr' ) );
				$pdf_url = add_query_arg( [ 'text' => rawurlencode( $url ), 'format' => 'pdf', 'download' => 1 ], rest_url( 'yeffoprint-core/v1/qr' ) );

				$label = $multiple
					? sprintf(
						/* translators: 1: field label, 2: label number within the batch */
						__( '%1$s (Label %2$d)', 'yeffoprint-core' ),
						$field['label'],
						$index + 1
					)
					: $field['label'];

				$suffix++;
				$entry               = new \stdClass();
				$entry->key          = '_yp_qr_download_' . $suffix;
				$entry->value        = $url;
				$entry->display_key  = $label;
				$entry->display_value = sprintf(
					'%s &mdash; <a href="%s">%s</a> / <a href="%s">%s</a>',
					esc_html( $url ),
					esc_url( $png_url ),
					esc_html__( 'Download PNG', 'yeffoprint-core' ),
					esc_url( $pdf_url ),
					esc_html__( 'Download PDF', 'yeffoprint-core' )
				);

				$formatted_meta[ 'yp_qr_' . $suffix ] = $entry;
			}
		}

		return $formatted_meta;
	}

	/**
	 * Reformats the "Customization"/"Label N (qty M)" row(s) add_variant_
	 * rows() adds — direct report: crammed into one meta value ("Compound
	 * Name: X — Strength: Y — Color: Z — …"), that row is unreadable on
	 * the order screen, exactly the field-by-field detail staff most need
	 * clear when producing labels.
	 *
	 * Deliberately doesn't touch the *stored* meta value (still the
	 * plain " — "-joined string add_variant_rows() wrote) — only
	 * display_value, only on this one screen. That value is also what
	 * plain-text customer emails and the migration plugin's export both
	 * read verbatim; reformatting it as HTML would leave literal markup
	 * showing in both. Rebuilding the list from the item's own
	 * `_yp_variants`/`_yp_template_snapshot` (the same source
	 * add_qr_download_links() above already reads) instead of parsing
	 * the joined string back apart also sidesteps a real ambiguity a
	 * regex split would hit: nothing stops a field's own value from
	 * containing " — " or ": " itself.
	 *
	 * In an HTML email, the row is dropped instead of reformatted — WC
	 * core's own email-order-items.php runs display_value through
	 * wp_kses() with a whitelist of only <br>/<span>(no attrs)/<a>, so
	 * nothing built here would survive it anyway. The real replacement
	 * is rendered separately, unconstrained, by
	 * render_customization_email_fields() below.
	 */
	public function format_customization_display( array $formatted_meta, \WC_Order_Item $item ): array {
		$is_order_screen = $this->is_order_edit_screen();
		$is_html_email   = $this->rendering_html_email;

		if ( ! $is_order_screen && ! $is_html_email ) {
			return $formatted_meta;
		}

		$batch = $this->get_batch_data( $item );
		if ( ! $batch ) {
			return $formatted_meta;
		}

		$multiple = count( $batch['variants'] ) > 1;

		foreach ( $batch['variants'] as $index => $variant ) {
			// Same label add_variant_rows() computed when it originally
			// wrote this meta row — matching on it (rather than a new,
			// separately-tracked key) finds the right entry without
			// needing to change what gets stored.
			$row_label = $multiple
				? sprintf(
					/* translators: 1: label number within the batch, 2: that label's own quantity */
					__( 'Label %1$d (qty %2$d)', 'yeffoprint-core' ),
					$index + 1,
					(int) ( $variant['quantity'] ?? 0 )
				)
				: __( 'Customization', 'yeffoprint-core' );

			foreach ( $formatted_meta as $key => $entry ) {
				if ( $entry->key !== $row_label ) {
					continue;
				}

				if ( $is_html_email ) {
					unset( $formatted_meta[ $key ] );
				} else {
					$html = $this->variant_fields_html( $variant, $batch['field_schema'] );
					if ( '' !== $html ) {
						$entry->display_value = $html;
					}
				}
				break;
			}
		}

		return $formatted_meta;
	}

	/**
	 * The HTML-email counterpart of format_customization_display() above
	 * — same "Customization"/"Label N (qty M)" field-by-field detail, but
	 * printed as fresh, richly-styled markup straight into the email
	 * template rather than through WC's wp_kses()-constrained meta-row
	 * pipeline. Uses the same woocommerce_email_after_order_table hook
	 * class-order-tracking.php's "Track your order" button already relies
	 * on for exactly this reason. Skipped for plain-text (the joined-
	 * string meta row format_customization_display() leaves untouched
	 * there is still what shows, unchanged on purpose).
	 */
	public function render_customization_email_fields( \WC_Order $order, bool $sent_to_admin, bool $plain_text, \WC_Email $email ): void {
		if ( $plain_text ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$html = $this->variant_fields_email_html( $item );
			if ( '' !== $html ) {
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built entirely from esc_html()'d pieces, see variant_field_pairs().
			}
		}
	}

	/** email-styles.php's .yp-email-fields* rules — same visual language as its .yp-email-callout, table-based for Outlook. */
	private function variant_fields_email_html( \WC_Order_Item_Product $item ): string {
		$batch = $this->get_batch_data( $item );
		if ( ! $batch ) {
			return '';
		}

		$multiple = count( $batch['variants'] ) > 1;
		$sections = '';

		foreach ( $batch['variants'] as $index => $variant ) {
			$rows = $this->variant_fields_email_rows( $variant, $batch['field_schema'] );
			if ( '' === $rows ) {
				continue;
			}

			$heading = $multiple
				? sprintf(
					/* translators: 1: label number within the batch, 2: that label's own quantity */
					__( 'Label %1$d (qty %2$d)', 'yeffoprint-core' ),
					$index + 1,
					(int) ( $variant['quantity'] ?? 0 )
				)
				: __( 'Customization', 'yeffoprint-core' );

			$sections .= sprintf(
				'<tr><td class="yp-email-fields-box"><span class="yp-email-fields-heading">%s</span><table class="yp-email-fields-rows" cellspacing="0" cellpadding="0" width="100%%">%s</table></td></tr>',
				esc_html( $heading ),
				$rows
			);
		}

		if ( '' === $sections ) {
			return '';
		}

		return '<table class="yp-email-fields" cellspacing="0" cellpadding="0" width="100%">' . $sections . '</table>';
	}

	private function variant_fields_email_rows( array $variant, array $field_schema ): string {
		$rows = array_map(
			static function ( array $pair ): string {
				return sprintf(
					'<tr><td class="yp-email-field-label">%s</td><td class="yp-email-field-value">%s%s</td></tr>',
					esc_html( $pair['label'] ),
					self::color_swatch_html( $pair, 'yp-email-color-swatch' ),
					esc_html( $pair['value'] )
				);
			},
			$this->variant_field_pairs( $variant, $field_schema )
		);

		return implode( '', $rows );
	}

	/** One row per field, label above value — see format_customization_display() above for why this is built from the variant/field_schema directly rather than reformatting the joined summary string. */
	private function variant_fields_html( array $variant, array $field_schema ): string {
		$rows = array_map(
			static function ( array $pair ): string {
				return sprintf(
					'<div class="yp-order-field"><span class="yp-order-field__label">%s</span><span class="yp-order-field__value">%s%s</span></div>',
					esc_html( $pair['label'] ),
					self::color_swatch_html( $pair, 'yp-order-field__swatch' ),
					esc_html( $pair['value'] )
				);
			},
			$this->variant_field_pairs( $variant, $field_schema )
		);

		return $rows ? '<div class="yp-order-fields">' . implode( '', $rows ) . '</div>' : '';
	}

	/**
	 * A small colored square before a `color`-type field's own hex value
	 * — direct report: staff/customers could read "#2F6FED" but not see
	 * what it actually looks like without pasting it somewhere else.
	 * Same class-plus-inline-background-color technique email-header.php's
	 * own wordmark dots (`.yp-dot`) already use for exactly this reason:
	 * a per-value color can't live in a static stylesheet rule, only the
	 * swatch's static size/shape can.
	 *
	 * Re-validates the hex format here (class-field-schema.php's own
	 * `sanitize_hex_color()` already guarantees this at save time) since
	 * this value is about to go straight into a `style` attribute.
	 */
	private static function color_swatch_html( array $pair, string $class ): string {
		if ( 'color' !== $pair['type'] || ! preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $pair['value'] ) ) {
			return '';
		}

		return sprintf(
			'<span class="%s" style="background-color:%s;"></span>',
			esc_attr( $class ),
			esc_attr( $pair['value'] )
		);
	}

	/** @return array<int, array{label:string, value:string, type:string}> Non-empty fields only, in field_schema order — shared by variant_fields_html() (admin div markup) and variant_fields_email_rows() (email table markup) above. */
	private function variant_field_pairs( array $variant, array $field_schema ): array {
		$values = (array) ( $variant['values'] ?? [] );
		$pairs  = [];

		foreach ( $field_schema as $field ) {
			$value = trim( (string) ( $values[ $field['id'] ] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}

			$pairs[] = [
				'label' => (string) ( $field['label'] ?? '' ),
				'value' => YeffoPrint_Field_Schema::display_value( $field, $value ),
				'type'  => (string) ( $field['type'] ?? '' ),
			];
		}

		return $pairs;
	}

	/**
	 * Decodes the _yp_variants/_yp_template_snapshot pair a Template-flow
	 * batch line item carries — shared by add_qr_download_links(),
	 * format_customization_display(), and variant_fields_email_html()
	 * above, all three of which need the same "is this a batch item, and
	 * if so what are its variants/field_schema" answer.
	 *
	 * @return array{variants: array, field_schema: array}|null Null if
	 *   this item isn't a Template-flow batch item at all, or its data
	 *   doesn't decode.
	 */
	private function get_batch_data( \WC_Order_Item $item ): ?array {
		$variants_json = $item->get_meta( '_yp_variants' );
		$snapshot_json = $item->get_meta( '_yp_template_snapshot' );
		if ( ! $variants_json || ! $snapshot_json ) {
			return null;
		}

		$variants     = json_decode( $variants_json, true );
		$snapshot     = json_decode( $snapshot_json, true );
		$field_schema = is_array( $snapshot['field_schema'] ?? null ) ? $snapshot['field_schema'] : [];

		if ( ! is_array( $variants ) || ! $field_schema ) {
			return null;
		}

		return [
			'variants'     => $variants,
			'field_schema' => $field_schema,
		];
	}

	/** admin.css's .yp-order-field* rules above — order-edit screen only, same screen-id check as is_order_edit_screen(). */
	public function enqueue_order_screen_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}

		wp_enqueue_style(
			'yeffoprint-core-admin',
			YEFFOPRINT_CORE_URL . 'assets/admin/admin.css',
			[],
			yeffoprint_core_asset_version( 'assets/admin/admin.css' )
		);
	}

	/**
	 * `is_admin()` alone isn't "staff looking at the order screen" — it's
	 * also true for admin-post.php/admin-ajax.php requests, which is
	 * exactly how a manual order-status change (the "Update" button on
	 * this same Edit Order screen) or the "Resend order emails" bulk
	 * action actually fire WooCommerce's transactional emails. Gating on
	 * plain `is_admin()` meant those staff-only download links — meant
	 * only for the order screen's own meta box — were leaking straight
	 * into the customer's real order email whenever the email happened
	 * to be triggered from inside wp-admin, while staying correctly
	 * hidden for the same status change made from checkout or the
	 * Venmo/Zelle payment webhook. Scoping to the actual order-edit
	 * screen id (classic `shop_order`, HPOS `woocommerce_page_wc-orders`)
	 * closes that gap: the orders *list* screen (where the resend bulk
	 * action runs) has a different screen id, so it no longer matches.
	 */
	private function is_order_edit_screen(): bool {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen && in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true );
	}

	public function hide_internal_keys( array $hidden ): array {
		return array_merge( $hidden, [
			'_yp_template_snapshot',
			'_yp_size_snapshot',
			'_yp_material_snapshot',
			'_yp_variants',
			'_yp_batch_quantity',
			'_yp_pricing_snapshot',
			'_yp_custom_order_id',
			'_yp_sticker_type',
			'_yp_shape',
			'_yp_batch_row_index',
			'_yp_compound_strength_snapshot',
		] );
	}
}
