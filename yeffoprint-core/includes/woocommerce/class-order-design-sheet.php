<?php
/**
 * The production screen's data (admin app `#/design-sheet/{id}`): every
 * label on an order as plain field/value pairs, plus which ones are
 * already designed.
 *
 * Direct request: on a big order (35 lines, 870 labels) the order page
 * made Jeff open each label's Details one by one while designing. This
 * screen sits beside the design software instead, and each label's
 * "designed" check is saved on the order, so stopping halfway and coming
 * back (or opening it on another computer) still shows where he was.
 *
 * Labels come from YeffoPrint_Order_Label_Changes::editable_lines(), the
 * same list the Edit labels form uses, so a label edited there shows its
 * new text here. That list leaves the Label Color picks off a Custom
 * Labels row (they aren't editable there), so they're added back here.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Design_Sheet {

	/** Order meta: { "itemId:labelIndex": unix time it was checked }. */
	public const META_KEY = '_yp_designed_labels';

	/**
	 * @return array{labels: array, other_items: array}
	 */
	public static function sheet( \WC_Order $order ): array {
		$labels  = [];
		$covered = [];

		foreach ( YeffoPrint_Order_Label_Changes::editable_lines( $order ) as $line ) {
			$item = $order->get_item( (int) $line['item_id'] );
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$covered[ $item->get_id() ] = true;

			$extra_fields = 'custom' === $line['kind'] ? self::custom_row_colors( $item ) : [];
			$product      = $item->get_product();

			foreach ( $line['labels'] as $label ) {
				$fields = [];
				foreach ( array_merge( $label['fields'], $extra_fields ) as $field ) {
					if ( 'qr_code' === $field['type'] ) {
						$field['display'] = '' !== $field['value'] ? __( 'Yes', 'yeffoprint-core' ) : '';
					}
					$fields[] = [
						'label'   => (string) $field['label'],
						'type'    => (string) $field['type'],
						// Hex for a color pick, so the screen can show a swatch.
						'value'   => 'color_choice' === $field['type'] ? (string) $field['value'] : '',
						'display' => (string) $field['display'],
					];
				}

				$labels[] = [
					'key'       => $item->get_id() . ':' . (int) $label['index'],
					'item_id'   => $item->get_id(),
					'name'      => $item->get_name(),
					'size'      => self::meta_text( $item, __( 'Size', 'yeffoprint-core' ) ),
					'material'  => self::meta_text( $item, __( 'Material', 'yeffoprint-core' ) ),
					'brand'     => self::meta_text( $item, '_yp_brand_name' ),
					'quantity'  => (int) $label['quantity'],
					'image_url' => $product ? ( wp_get_attachment_image_url( $product->get_image_id(), 'medium' ) ?: null ) : null,
					'fields'    => $fields,
				];
			}
		}

		return [
			'labels'      => $labels,
			'other_items' => self::other_items( $order, $covered ),
		];
	}

	/** @return array<string, int> */
	public static function designed( \WC_Order $order ): array {
		$saved = json_decode( (string) $order->get_meta( self::META_KEY ), true );
		return is_array( $saved ) ? array_map( 'intval', $saved ) : [];
	}

	/**
	 * Checks or unchecks labels. Only keys that are labels on the order
	 * now are kept, so a label removed by Edit labels drops out.
	 *
	 * @param string[] $keys
	 * @return array<string, int>
	 */
	public static function set_designed( \WC_Order $order, array $keys, bool $done ): array {
		$valid = [];
		foreach ( self::sheet( $order )['labels'] as $label ) {
			$valid[ $label['key'] ] = true;
		}

		$designed = array_intersect_key( self::designed( $order ), $valid );
		foreach ( $keys as $key ) {
			$key = (string) $key;
			if ( ! isset( $valid[ $key ] ) ) {
				continue;
			}
			if ( $done ) {
				$designed[ $key ] = $designed[ $key ] ?? time();
			} else {
				unset( $designed[ $key ] );
			}
		}

		$order->update_meta_data( self::META_KEY, wp_json_encode( (object) $designed ) );
		$order->save();

		return $designed;
	}

	/**
	 * "9 of 35" for the order page, or null on an order with no labels
	 * to design (3D prints, web design, stickers only).
	 *
	 * @return array{done:int, total:int}|null
	 */
	public static function progress( \WC_Order $order ): ?array {
		$labels = self::sheet( $order )['labels'];
		if ( ! $labels ) {
			return null;
		}
		$designed = self::designed( $order );
		$done     = 0;
		foreach ( $labels as $label ) {
			if ( isset( $designed[ $label['key'] ] ) ) {
				$done++;
			}
		}
		return [ 'done' => $done, 'total' => count( $labels ) ];
	}

	/** A Custom Labels row's Background/Text picks, which editable_lines() leaves out. */
	private static function custom_row_colors( \WC_Order_Item_Product $item ): array {
		$fields = json_decode( (string) $item->get_meta( '_yp_row_fields' ), true );
		$values = json_decode( (string) $item->get_meta( '_yp_row_values' ), true );
		if ( ! is_array( $fields ) || ! is_array( $values ) ) {
			return [];
		}

		$colors = [];
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || 'color_choice' !== ( $field['type'] ?? '' ) || ! isset( $field['id'] ) ) {
				continue;
			}
			$value    = trim( (string) ( $values[ $field['id'] ] ?? '' ) );
			$colors[] = [
				'label'   => (string) ( $field['label'] ?? '' ),
				'type'    => 'color_choice',
				'value'   => $value,
				'display' => '' !== $value ? YeffoPrint_Field_Schema::display_value( $field, $value ) : '',
			];
		}
		return $colors;
	}

	/** Everything else on the order (stickers, Label Designer artwork, 3D prints), as name, quantity and plain-text details. */
	private static function other_items( \WC_Order $order, array $covered ): array {
		$fee_product = class_exists( 'YeffoPrint_Custom_Design_Fee_Product' ) ? (int) YeffoPrint_Custom_Design_Fee_Product::get_product_id() : 0;
		$items       = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || isset( $covered[ $item->get_id() ] ) ) {
				continue;
			}
			if ( $fee_product && $item->get_product_id() === $fee_product ) {
				continue;
			}

			$count   = YeffoPrint_Order_Label_Count::for_item( $item );
			$details = [];
			YeffoPrint_Order_Item_Meta::mark_admin_app_context();
			$formatted = $item->get_formatted_meta_data();
			YeffoPrint_Order_Item_Meta::unmark_admin_app_context();
			foreach ( $formatted as $meta ) {
				$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( str_replace( [ '<br>', '<br/>', '<br />', '</div>', '</li>', '</tr>' ], ' · ', (string) $meta->display_value ) ) ) );
				$text = trim( preg_replace( '/(\s*·\s*)+/', ' · ', $text ), ' ·' );
				if ( '' !== $text ) {
					$details[] = [ 'label' => wp_strip_all_tags( (string) $meta->display_key ), 'value' => $text ];
				}
			}

			$items[] = [
				'name'     => $item->get_name(),
				'quantity' => $count ? (int) $count['quantity'] : (int) $item->get_quantity(),
				'details'  => $details,
			];
		}

		return $items;
	}

	private static function meta_text( \WC_Order_Item_Product $item, string $key ): string {
		$value = trim( wp_strip_all_tags( (string) $item->get_meta( $key ) ) );
		return '—' === $value ? '' : $value;
	}
}
