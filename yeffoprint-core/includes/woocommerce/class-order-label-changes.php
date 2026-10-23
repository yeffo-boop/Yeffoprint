<?php
/**
 * Label change requests on an order that isn't finished yet. Direct
 * request (Jeff picked "With approval"): a customer fixes a compound
 * name, label color or any other label field on their own order, staff
 * get a push and a "Change requested" banner, and one tap applies it.
 *
 * - Customers: /order-change/?order=ID&key=ORDER_KEY (guest-safe, same
 *   order-key check as /add-to-order/), linked from My Account's order
 *   view and the processing/on-hold emails. Only while the order is
 *   unpaid, on hold or Processing; it locks once it moves to In
 *   Production or gets a shipping label.
 * - Staff: the admin app's order page shows the request with Approve /
 *   Decline, and an Edit button on the Labels card changes the same
 *   fields directly on any order that hasn't shipped
 *   (class-admin-label-changes-controller.php).
 *
 * What can change: each label's own fields on a Template batch (stored
 * in _yp_variants, read through the order's frozen field_schema) and a
 * Custom Labels row's Compound/Strength. Size, material and quantity
 * change the price, so those still go through Add items or a new order.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Label_Changes {

	public const SLUG = 'order-change';

	private const QUERY_VAR = 'yeffoprint_order_change';

	/** JSON: { changes: {item_id: {label_index: {field_id: value}}}, lines: string[], note: string, at: ISO date }. */
	public const REQUEST_META = '_yp_label_change_request';

	/** Statuses a customer can still ask for a change in. */
	private const CUSTOMER_STATUSES = [ 'pending', 'on-hold', 'processing', 'failed' ];

	/** Statuses staff can still edit label details in (everything before it ships). */
	private const ADMIN_STATUSES = [ 'pending', 'on-hold', 'processing', 'failed', 'in-production' ];

	/** The Custom Labels form row's one editable field. */
	private const COMPOUND_FIELD = 'compound_strength';

	private const COMPOUND_MAX_CHARS = 120;

	private const NOTE_MAX_CHARS = 1000;

	public function __construct() {
		add_action( 'init', [ $this, 'register_rewrite' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_serve' ], 0 );
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'render_account_button' ], 20 );
		add_action( 'woocommerce_email_after_order_table', [ $this, 'render_email_notice' ], 12, 4 );
	}

	public function register_rewrite(): void {
		add_rewrite_rule( '^' . self::SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function url( \WC_Order $order ): string {
		return add_query_arg(
			[ 'order' => $order->get_id(), 'key' => $order->get_order_key() ],
			home_url( '/' . self::SLUG . '/' )
		);
	}

	/** "#1234", or the formatted number as is when it already reads like one (YP-27). */
	public static function order_label( \WC_Order $order ): string {
		$number = (string) $order->get_order_number();
		return ctype_digit( $number ) ? '#' . $number : $number;
	}

	/* ---------- Who can change what ---------- */

	public static function customer_can_request( \WC_Order $order ): bool {
		return in_array( $order->get_status(), self::CUSTOMER_STATUSES, true )
			&& ! self::has_shipment( $order )
			&& [] !== self::editable_lines( $order );
	}

	public static function admin_can_edit( \WC_Order $order ): bool {
		return in_array( $order->get_status(), self::ADMIN_STATUSES, true ) && ! self::has_shipment( $order );
	}

	private static function has_shipment( \WC_Order $order ): bool {
		return class_exists( 'YeffoPrint_Order_Tracking' ) && ! empty( YeffoPrint_Order_Tracking::get_shipments( $order ) );
	}

	/**
	 * Every label on the order whose details can be changed, with each
	 * field's current value — what both the customer form and the admin
	 * app's Edit form draw from.
	 *
	 * @return array<int, array{item_id:int, name:string, kind:string, labels:array}>
	 */
	public static function editable_lines( \WC_Order $order ): array {
		$lines = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$schema = self::template_schema( $item );
			if ( $schema ) {
				$labels = [];
				foreach ( self::variants( $item ) as $index => $variant ) {
					$labels[] = [
						'index'    => $index,
						'quantity' => (int) ( $variant['quantity'] ?? 0 ),
						'fields'   => array_values( array_map( static function ( array $field ) use ( $variant ): array {
							return self::field_payload( $field, (string) ( $variant['values'][ $field['id'] ] ?? '' ) );
						}, $schema ) ),
					];
				}
				if ( $labels ) {
					$lines[] = [ 'item_id' => $item->get_id(), 'name' => $item->get_name(), 'kind' => 'template', 'labels' => $labels ];
				}
				continue;
			}

			if ( self::is_custom_labels_row( $item ) ) {
				$lines[] = [
					'item_id' => $item->get_id(),
					'name'    => $item->get_name(),
					'kind'    => 'custom',
					'labels'  => [ [
						'index'    => 0,
						'quantity' => (int) $item->get_meta( '_yp_batch_quantity' ),
						'fields'   => self::custom_fields_payload( $item ),
					] ],
				];
			}
		}

		return $lines;
	}

	/** A Template batch's frozen field_schema, or [] for any other line. */
	private static function template_schema( \WC_Order_Item_Product $item ): array {
		$snapshot = json_decode( (string) $item->get_meta( '_yp_template_snapshot' ), true );
		$schema   = is_array( $snapshot['field_schema'] ?? null ) ? $snapshot['field_schema'] : [];
		return $schema && self::variants( $item ) ? array_values( $schema ) : [];
	}

	private static function variants( \WC_Order_Item_Product $item ): array {
		$variants = json_decode( (string) $item->get_meta( '_yp_variants' ), true );
		return is_array( $variants ) ? array_values( $variants ) : [];
	}

	/**
	 * A Custom Labels form row (one compound per row). Not the design
	 * fee line, not stickers, and not a Label Designer canvas, where the
	 * text lives in the drawn design instead.
	 */
	private static function is_custom_labels_row( \WC_Order_Item_Product $item ): bool {
		return (int) $item->get_meta( '_yp_custom_order_id' )
			&& '' !== (string) $item->get_meta( '_yp_batch_quantity' )
			&& '' === (string) $item->get_meta( '_yp_sticker_type' )
			&& '' === (string) $item->get_meta( '_yp_canvas_width_mm' );
	}

	/**
	 * A Custom Labels row's Label Fields as checked out (Compound Name,
	 * Strength, Batch Number, …), or [] for a row placed before the form
	 * had them — that one still edits its single Compound/Strength text.
	 */
	private static function custom_row_fields( \WC_Order_Item_Product $item ): array {
		$fields = json_decode( (string) $item->get_meta( '_yp_row_fields' ), true );
		if ( ! is_array( $fields ) ) {
			return [];
		}
		return array_values( array_filter( $fields, static function ( $field ) {
			return is_array( $field ) && 'color_choice' !== ( $field['type'] ?? '' );
		} ) );
	}

	private static function custom_row_values( \WC_Order_Item_Product $item ): array {
		$values = json_decode( (string) $item->get_meta( '_yp_row_values' ), true );
		return is_array( $values ) ? $values : [];
	}

	private static function custom_fields_payload( \WC_Order_Item_Product $item ): array {
		$fields = self::custom_row_fields( $item );
		if ( ! $fields ) {
			return [ self::field_payload( self::compound_field(), (string) $item->get_meta( '_yp_compound_strength_snapshot' ) ) ];
		}
		$values = self::custom_row_values( $item );
		return array_map( static function ( array $field ) use ( $values ) {
			return self::field_payload( $field, (string) ( $values[ $field['id'] ] ?? '' ) );
		}, $fields );
	}

	private static function compound_field(): array {
		return array_merge( YeffoPrint_Field_Schema::default_field(), [
			'id'        => self::COMPOUND_FIELD,
			'label'     => __( 'Compound/Strength', 'yeffoprint-core' ),
			'type'      => 'text',
			'max_chars' => self::COMPOUND_MAX_CHARS,
		] );
	}

	private static function field_payload( array $field, string $value ): array {
		$type    = (string) ( $field['type'] ?? 'text' );
		$options = [];
		if ( 'color_choice' === $type ) {
			foreach ( (array) ( $field['options'] ?? [] ) as $option ) {
				$options[] = [ 'value' => (string) $option['hex'], 'label' => (string) $option['name'] ];
			}
		} elseif ( 'corner_style' === $type ) {
			foreach ( YeffoPrint_Field_Schema::CORNER_STYLE_OPTIONS as $key => $label ) {
				$options[] = [ 'value' => $key, 'label' => $label ];
			}
		}

		return [
			'id'        => (string) $field['id'],
			'label'     => (string) $field['label'],
			'type'      => $type,
			'value'     => $value,
			'display'   => '' !== $value ? YeffoPrint_Field_Schema::display_value( $field, $value ) : '',
			'required'  => ! empty( $field['required'] ),
			'max_chars' => (int) ( $field['max_chars'] ?? 0 ),
			'options'   => $options,
			'any_color' => ! empty( $field['any_color'] ),
		];
	}

	/* ---------- Checking and applying changes ---------- */

	/**
	 * Keeps only the submitted values that differ from what's on the
	 * order now, each cleaned the same way checkout cleans it.
	 *
	 * @param mixed $raw {item_id: {label_index: {field_id: value}}}
	 * @return array{changes:array, lines:string[]}|\WP_Error
	 */
	public static function normalize( \WC_Order $order, $raw ) {
		$changes = [];
		$lines   = [];
		$raw     = is_array( $raw ) ? $raw : [];

		foreach ( self::editable_lines( $order ) as $line ) {
			$item_id   = $line['item_id'];
			$submitted = $raw[ $item_id ] ?? $raw[ (string) $item_id ] ?? null;
			if ( ! is_array( $submitted ) ) {
				continue;
			}
			$item   = $order->get_item( $item_id );
			$row_fields = 'custom' === $line['kind'] ? self::custom_row_fields( $item ) : [];
			$schema     = 'template' === $line['kind'] ? self::template_schema( $item ) : ( $row_fields ?: [ self::compound_field() ] );

			foreach ( $line['labels'] as $label ) {
				$values = $submitted[ $label['index'] ] ?? $submitted[ (string) $label['index'] ] ?? null;
				if ( ! is_array( $values ) ) {
					continue;
				}
				foreach ( $schema as $field ) {
					if ( ! array_key_exists( $field['id'], $values ) || ! is_scalar( $values[ $field['id'] ] ) ) {
						continue;
					}
					// A custom row's required fields may have been left blank on an own-design order, so blanks aren't refused there.
					$clean = self::clean_value( $field, (string) $values[ $field['id'] ], 'template' === $line['kind'] || $row_fields, 'template' === $line['kind'] );
					if ( is_wp_error( $clean ) ) {
						return $clean;
					}
					$current = '';
					foreach ( $label['fields'] as $f ) {
						if ( $f['id'] === $field['id'] ) {
							$current = $f['value'];
						}
					}
					if ( $clean === $current ) {
						continue;
					}
					$changes[ $item_id ][ $label['index'] ][ $field['id'] ] = $clean;
					$lines[] = sprintf(
						/* translators: 1: where the label is (item and label number), 2: field name, 3: old value, 4: new value */
						__( '%1$s · %2$s: %3$s → %4$s', 'yeffoprint-core' ),
						count( $line['labels'] ) > 1
							/* translators: 1: item name, 2: label number */
							? sprintf( __( '%1$s, label %2$d', 'yeffoprint-core' ), $line['name'], $label['index'] + 1 )
							: $line['name'],
						$field['label'],
						'' !== $current ? YeffoPrint_Field_Schema::display_value( $field, $current ) : __( '(blank)', 'yeffoprint-core' ),
						'' !== $clean ? YeffoPrint_Field_Schema::display_value( $field, $clean ) : __( '(blank)', 'yeffoprint-core' )
					);
				}
			}
		}

		return [ 'changes' => $changes, 'lines' => $lines ];
	}

	/** @return string|\WP_Error One field's value, cleaned and checked exactly as at checkout. */
	private static function clean_value( array $field, string $raw, bool $is_template, bool $enforce_required = true ) {
		if ( ! $is_template ) {
			$value = sanitize_text_field( $raw );
			if ( mb_strlen( $value ) > self::COMPOUND_MAX_CHARS ) {
				/* translators: %d: max character count */
				return new \WP_Error( 'yeffoprint_field_too_long', sprintf( __( 'Compound/Strength must be %d characters or fewer.', 'yeffoprint-core' ), self::COMPOUND_MAX_CHARS ), [ 'status' => 400 ] );
			}
			return $value;
		}

		$clean = YeffoPrint_Field_Schema::sanitize_variants( [ [ 'quantity' => 1, 'values' => [ $field['id'] => $raw ] ] ], [ $field ], $enforce_required );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		return (string) ( $clean[0]['values'][ $field['id'] ] ?? '' );
	}

	/**
	 * Writes changes onto the order's line items and logs them in an
	 * order note.
	 *
	 * @return string[]|\WP_Error The change lines that were applied.
	 */
	public static function apply( \WC_Order $order, $raw, string $who ) {
		$result = self::normalize( $order, $raw );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $result['changes'] as $item_id => $labels ) {
			$item = $order->get_item( (int) $item_id );
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$schema = self::template_schema( $item );
			if ( $schema ) {
				$variants = self::variants( $item );
				foreach ( $labels as $index => $values ) {
					if ( isset( $variants[ $index ] ) ) {
						$variants[ $index ]['values'] = array_merge( (array) ( $variants[ $index ]['values'] ?? [] ), $values );
					}
				}
				$item->update_meta_data( '_yp_variants', wp_json_encode( $variants ) );
				YeffoPrint_Order_Item_Meta::rewrite_variant_rows( $item, $variants, $schema );
			} elseif ( self::custom_row_fields( $item ) ) {
				self::apply_custom_row_fields( $item, (array) ( $labels[0] ?? [] ) );
			} else {
				$value = (string) ( $labels[0][ self::COMPOUND_FIELD ] ?? '' );
				$item->update_meta_data( '_yp_compound_strength_snapshot', $value );
				$item->delete_meta_data( __( 'Compound/Strength', 'yeffoprint-core' ) );
				if ( '' !== $value ) {
					$item->add_meta_data( __( 'Compound/Strength', 'yeffoprint-core' ), $value, true );
				}
				self::update_custom_order_row( (int) $item->get_meta( '_yp_custom_order_id' ), (int) $item->get_meta( '_yp_batch_row_index' ), $value );
			}
			$item->save();
		}

		if ( $result['lines'] ) {
			$order->add_order_note(
				/* translators: 1: who made the change, 2: one line per changed field */
				sprintf( __( "Label details changed (%1\$s):\n%2\$s", 'yeffoprint-core' ), $who, implode( "\n", $result['lines'] ) )
			);
		}

		return $result['lines'];
	}

	/**
	 * A Custom Labels row with Label Fields: the new answers, the
	 * readable rows, the compound/QR text worked out from them, and the
	 * design request's own copy of the row.
	 */
	private static function apply_custom_row_fields( \WC_Order_Item_Product $item, array $changed ): void {
		$fields = self::custom_row_fields( $item );
		$values = array_merge( self::custom_row_values( $item ), $changed );
		$legacy = YeffoPrint_Custom_Order_Meta::legacy_row_text( $values, $fields );

		YeffoPrint_Order_Item_Meta::write_custom_row_fields( $item, $values, $fields );
		$item->update_meta_data( '_yp_compound_strength_snapshot', $legacy['compound_strength'] );

		$item->delete_meta_data( __( 'QR code', 'yeffoprint-core' ) );
		$item->delete_meta_data( '_yp_qr_url_snapshot' );
		if ( '' !== $legacy['qr_url'] ) {
			$item->add_meta_data( '_yp_qr_url_snapshot', $legacy['qr_url'], true );
			$item->add_meta_data( __( 'QR code', 'yeffoprint-core' ), $legacy['qr_url'], true );
		}

		$custom_order_id = (int) $item->get_meta( '_yp_custom_order_id' );
		$row_index       = (int) $item->get_meta( '_yp_batch_row_index' );
		self::update_custom_order_row( $custom_order_id, $row_index, $legacy['compound_strength'] );
		$batch = json_decode( (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::BATCH, true ), true );
		if ( $custom_order_id && is_array( $batch ) && isset( $batch[ $row_index ] ) && is_array( $batch[ $row_index ] ) ) {
			$batch[ $row_index ]['values'] = $values;
			$batch[ $row_index ]['qr_url'] = $legacy['qr_url'];
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::BATCH, wp_slash( wp_json_encode( $batch ) ) );
		}
	}

	/** Keeps the custom design request's own copy of the row in step, so the design work reads the new compound too. */
	private static function update_custom_order_row( int $custom_order_id, int $row_index, string $value ): void {
		if ( ! $custom_order_id ) {
			return;
		}
		$batch = json_decode( (string) get_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::BATCH, true ), true );
		if ( is_array( $batch ) && isset( $batch[ $row_index ] ) && is_array( $batch[ $row_index ] ) ) {
			$batch[ $row_index ]['compound_strength'] = $value;
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::BATCH, wp_slash( wp_json_encode( $batch ) ) );
		}
		if ( 0 === $row_index ) {
			update_post_meta( $custom_order_id, YeffoPrint_Custom_Order_Meta::COMPOUND_STRENGTH, $value );
		}
	}

	/* ---------- The pending request ---------- */

	public static function get_request( \WC_Order $order ): ?array {
		$request = json_decode( (string) $order->get_meta( self::REQUEST_META ), true );
		return is_array( $request ) ? $request : null;
	}

	public static function clear_request( \WC_Order $order ): void {
		$order->delete_meta_data( self::REQUEST_META );
		$order->save();
	}

	/** Staff approved: apply what the customer asked for and tell them. */
	public static function approve( \WC_Order $order ) {
		$request = self::get_request( $order );
		if ( ! $request ) {
			return new \WP_Error( 'yeffoprint_no_change_request', __( 'This order has no change request waiting.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		$applied = self::apply( $order, $request['changes'] ?? [], __( 'customer request, approved', 'yeffoprint-core' ) );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		self::clear_request( $order );
		$order->add_order_note( __( 'Label change request approved.', 'yeffoprint-core' ) );

		self::email_customer(
			$order,
			/* translators: %s: order number */
			sprintf( __( 'Your label change for order %s is done', 'yeffoprint-core' ), self::order_label( $order ) ),
			__( 'Label change made', 'yeffoprint-core' ),
			'<p>' . esc_html__( "We've updated your order with the change you asked for:", 'yeffoprint-core' ) . '</p>' .
				self::lines_html( $applied ?: (array) ( $request['lines'] ?? [] ) ) .
				( '' !== (string) ( $request['note'] ?? '' ) ? '<p>' . esc_html__( 'We also read your note and will follow up if anything else is needed.', 'yeffoprint-core' ) . '</p>' : '' )
		);

		return $applied;
	}

	/** Staff declined: drop the request and tell the customer why. */
	public static function decline( \WC_Order $order, string $message ) {
		if ( ! self::get_request( $order ) ) {
			return new \WP_Error( 'yeffoprint_no_change_request', __( 'This order has no change request waiting.', 'yeffoprint-core' ), [ 'status' => 400 ] );
		}

		self::clear_request( $order );
		$order->add_order_note(
			'' !== $message
				/* translators: %s: the reason given to the customer */
				? sprintf( __( 'Label change request declined: %s', 'yeffoprint-core' ), $message )
				: __( 'Label change request declined.', 'yeffoprint-core' )
		);

		self::email_customer(
			$order,
			/* translators: %s: order number */
			sprintf( __( "About your label change for order %s", 'yeffoprint-core' ), self::order_label( $order ) ),
			__( "We couldn't make that change", 'yeffoprint-core' ),
			'<p>' . esc_html__( "We weren't able to make the label change you asked for, so your order is going ahead as it was.", 'yeffoprint-core' ) . '</p>' .
				( '' !== $message ? '<p>' . nl2br( esc_html( $message ) ) . '</p>' : '' ) .
				'<p>' . esc_html__( 'Just reply to this email if you have any questions.', 'yeffoprint-core' ) . '</p>'
		);

		return true;
	}

	/** The customer's request: saved on the order, noted, and pushed to staff. */
	private static function save_request( \WC_Order $order, array $result, string $note ): void {
		$order->update_meta_data( self::REQUEST_META, wp_json_encode( [
			'changes' => $result['changes'],
			'lines'   => $result['lines'],
			'note'    => $note,
			'at'      => gmdate( 'c' ),
		] ) );
		$order->save();

		$summary = array_merge( $result['lines'], '' !== $note ? [ __( 'Note:', 'yeffoprint-core' ) . ' ' . $note ] : [] );
		$order->add_order_note(
			/* translators: %s: one line per requested change */
			sprintf( __( "Customer requested a label change (waiting on your OK):\n%s", 'yeffoprint-core' ), implode( "\n", $summary ) )
		);

		YeffoPrint_Telegram_Admin_Alerts::notify(
			implode( "\n", array_filter( [
				/* translators: %s: order number */
				sprintf( __( 'Label change requested on order %s', 'yeffoprint-core' ), self::order_label( $order ) ),
				trim( $order->get_formatted_billing_full_name() ) ?: $order->get_billing_email(),
				implode( "\n", $summary ),
			], 'strlen' ) ),
			[ 'order_id' => $order->get_id() ]
		);
	}

	private static function lines_html( array $lines ): string {
		if ( ! $lines ) {
			return '';
		}
		return '<ul>' . implode( '', array_map( static function ( $line ): string {
			return '<li>' . esc_html( (string) $line ) . '</li>';
		}, $lines ) ) . '</ul>';
	}

	private static function email_customer( \WC_Order $order, string $subject, string $heading, string $body_html ): void {
		$to = $order->get_billing_email();
		if ( ! $to || ! function_exists( 'WC' ) ) {
			return;
		}
		$mailer = WC()->mailer();
		$mailer->send( $to, $subject, $mailer->wrap_message( $heading, $body_html ) );
	}

	/* ---------- Customer entry points ---------- */

	public function render_account_button( \WC_Order $order ): void {
		if ( ! is_wc_endpoint_url( 'view-order' ) || ! self::customer_can_request( $order ) ) {
			return;
		}

		printf(
			'<p class="yp-label-change-cta"><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( self::url( $order ) ),
			esc_html__( 'Request a label change', 'yeffoprint-core' )
		);
	}

	public function render_email_notice( \WC_Order $order, bool $sent_to_admin, bool $plain_text, \WC_Email $email ): void {
		if ( $sent_to_admin || ! in_array( $email->id, [ 'customer_processing_order', 'customer_on_hold_order' ], true ) || ! self::customer_can_request( $order ) ) {
			return;
		}

		$url = self::url( $order );
		if ( $plain_text ) {
			echo esc_html__( 'Spot a typo or want a different color? Request a label change before we print:', 'yeffoprint-core' ) . ' ' . esc_url( $url ) . "\n\n";
			return;
		}

		printf(
			'<p style="margin:16px 0;text-align:center;font-size:14px;">%1$s <a href="%2$s">%3$s</a></p>',
			esc_html__( 'Spot a typo or want a different color?', 'yeffoprint-core' ),
			esc_url( $url ),
			esc_html__( 'Request a label change before we print', 'yeffoprint-core' )
		);
	}

	/* ---------- The /order-change/ page ---------- */

	public function maybe_serve(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		nocache_headers();

		// phpcs:disable WordPress.Security.NonceVerification -- the order key is the access check (same as /add-to-order/), and nothing here is reachable without it.
		$order_id = absint( $_REQUEST['order'] ?? 0 );
		$key      = sanitize_text_field( wp_unslash( $_REQUEST['key'] ?? '' ) );
		$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order || ! YeffoPrint_Order_Addon::owns_order( $order, $key ) ) {
			status_header( 404 );
			$this->render_page( null, [ 'state' => 'not_found' ] );
			exit;
		}

		$view = [ 'state' => self::customer_can_request( $order ) ? 'form' : 'locked', 'error' => '', 'note' => '' ];

		if ( 'form' === $view['state'] && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$note   = mb_substr( sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ), 0, self::NOTE_MAX_CHARS );
			$result = self::normalize( $order, wp_unslash( $_POST['labels'] ?? [] ) );
			if ( is_wp_error( $result ) ) {
				$view['error'] = $result->get_error_message();
				$view['note']  = $note;
			} elseif ( ! $result['changes'] && '' === $note ) {
				$view['error'] = __( 'Nothing was changed. Edit a field or add a note, then send it again.', 'yeffoprint-core' );
			} else {
				self::save_request( $order, $result, $note );
				$view['state'] = 'sent';
			}
		}
		// phpcs:enable

		status_header( 200 );
		$this->render_page( $order, $view );
		exit;
	}

	private function render_page( ?\WC_Order $order, array $view ): void {
		header( 'Content-Type: text/html; charset=utf-8' );

		$request = $order ? self::get_request( $order ) : null;
		$lines   = $order ? self::editable_lines( $order ) : [];

		// A waiting request shows its own values in the form, so sending
		// again starts from what the customer already asked for.
		if ( $request && 'form' === $view['state'] ) {
			foreach ( $lines as &$line ) {
				foreach ( $line['labels'] as &$label ) {
					foreach ( $label['fields'] as &$field ) {
						$asked = $request['changes'][ $line['item_id'] ][ $label['index'] ][ $field['id'] ] ?? null;
						if ( null !== $asked ) {
							$field['value'] = (string) $asked;
						}
					}
				}
			}
			unset( $line, $label, $field );
			if ( '' === $view['note'] ) {
				$view['note'] = (string) ( $request['note'] ?? '' );
			}
		}

		$back_url   = home_url( '/' );
		$back_label = __( 'Back to the shop', 'yeffoprint-core' );
		if ( $order && is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id() ) {
			$back_url   = $order->get_view_order_url();
			$back_label = __( 'Back to your order', 'yeffoprint-core' );
		}

		require YEFFOPRINT_CORE_PATH . 'includes/woocommerce/order-change-page.php';
	}
}
