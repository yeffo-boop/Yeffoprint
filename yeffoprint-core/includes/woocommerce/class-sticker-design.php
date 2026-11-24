<?php
/**
 * The Sticker Designer's own choices on a Custom Stickers line, beyond
 * the type/shape/size/material/quantity every sticker already has:
 * border width, rounded-corner amount, "remove white background",
 * "print white under my art" (clear vinyl), the finished cut size the
 * live preview measured, and the preview picture itself (a PNG of the
 * sticker with its cut line, uploaded through the same
 * /custom-orders/uploads endpoint as the artwork).
 *
 * Stored as one array on the cart item (YeffoPrint_Cart_Item_Keys::
 * STICKER_DESIGN), on the yp_custom_order (YeffoPrint_Custom_Order_Meta::
 * STICKER_DESIGN, JSON) and on the order line (_yp_sticker_design, JSON,
 * plus readable rows), so production sees exactly what the customer saw.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Sticker_Design {

	/** Order line item meta: the sanitized design array, JSON. */
	public const ITEM_META = '_yp_sticker_design';

	/** Order line item meta: the preview PNG's attachment id. */
	public const ITEM_PREVIEW_META = '_yp_sticker_preview_id';

	public const MAX_BORDER_IN = 0.5;

	/**
	 * Only known keys, clamped to sane ranges. Returns [] when nothing
	 * was sent (an older form, the admin's Create Order, a reorder link).
	 *
	 * @param mixed $raw
	 */
	public static function sanitize( $raw ): array {
		if ( ! is_array( $raw ) || ! $raw ) {
			return [];
		}

		$clamp = static function ( $value, float $min, float $max ): float {
			return round( min( $max, max( $min, (float) $value ) ), 4 );
		};

		$design = [
			'border_in'      => $clamp( $raw['border_in'] ?? 0, 0, self::MAX_BORDER_IN ),
			'corner_pct'     => (int) $clamp( $raw['corner_pct'] ?? 0, 0, 50 ),
			'remove_bg'      => ! empty( $raw['remove_bg'] ),
			'white_underlay' => ! empty( $raw['white_underlay'] ),
			'width_in'       => $clamp( $raw['width_in'] ?? 0, 0, 24 ),
			'height_in'      => $clamp( $raw['height_in'] ?? 0, 0, 24 ),
			'preview_id'     => 0,
		];

		$preview_id = absint( $raw['preview_id'] ?? 0 );
		if ( $preview_id && 'attachment' === get_post_type( $preview_id ) && wp_attachment_is_image( $preview_id ) ) {
			$design['preview_id'] = $preview_id;
		}

		return $design;
	}

	/** "1/8″" for the common steps, else "0.15″". */
	public static function inches_label( float $inches ): string {
		$fractions = [
			'0'      => __( 'None', 'yeffoprint-core' ),
			'0.0313' => '1/32″',
			'0.0625' => '1/16″',
			'0.125'  => '1/8″',
			'0.1875' => '3/16″',
			'0.25'   => '1/4″',
			'0.375'  => '3/8″',
			'0.5'    => '1/2″',
		];
		foreach ( $fractions as $value => $label ) {
			if ( abs( (float) $value - $inches ) < 0.004 ) {
				return $label;
			}
		}

		return rtrim( rtrim( number_format( $inches, 2 ), '0' ), '.' ) . '″';
	}

	public static function size_label( float $width, float $height ): string {
		$fmt = static function ( float $v ): string {
			return rtrim( rtrim( number_format( $v, 2 ), '0' ), '.' );
		};
		return $fmt( $width ) . '″ × ' . $fmt( $height ) . '″';
	}

	/**
	 * Readable rows for the cart, checkout, emails and the order screen.
	 *
	 * @return array<int,array{key:string,value:string}>
	 */
	public static function display_rows( array $design, string $shape, int $material_id ): array {
		if ( ! $design ) {
			return [];
		}

		$rows = [];
		if ( ! empty( $design['width_in'] ) && ! empty( $design['height_in'] ) ) {
			$rows[] = [ 'key' => __( 'Cut size', 'yeffoprint-core' ), 'value' => self::size_label( (float) $design['width_in'], (float) $design['height_in'] ) ];
		}
		$rows[] = [ 'key' => __( 'Border', 'yeffoprint-core' ), 'value' => self::inches_label( (float) $design['border_in'] ) ];
		if ( 'rounded_square' === $shape ) {
			$rows[] = [ 'key' => __( 'Corners', 'yeffoprint-core' ), 'value' => (int) $design['corner_pct'] . '%' ];
		}
		if ( ! empty( $design['remove_bg'] ) ) {
			$rows[] = [ 'key' => __( 'Background', 'yeffoprint-core' ), 'value' => __( 'White background removed', 'yeffoprint-core' ) ];
		}
		if ( $material_id && self::is_clear( $material_id ) ) {
			$rows[] = [
				'key'   => __( 'White under art', 'yeffoprint-core' ),
				'value' => ! empty( $design['white_underlay'] ) ? __( 'Yes', 'yeffoprint-core' ) : __( 'No (see-through)', 'yeffoprint-core' ),
			];
		}

		return $rows;
	}

	public static function preview_url( array $design, string $size = 'full' ): string {
		$id = (int) ( $design['preview_id'] ?? 0 );
		if ( ! $id ) {
			return '';
		}
		$url = 'full' === $size ? wp_get_attachment_url( $id ) : wp_get_attachment_image_url( $id, $size );
		return $url ? (string) $url : '';
	}

	/** The design array saved on an order line, or []. */
	public static function from_item( \WC_Order_Item $item ): array {
		$decoded = json_decode( (string) $item->get_meta( self::ITEM_META, true ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	public static function is_clear( int $material_id ): bool {
		$material = get_post( $material_id );
		return $material
			&& 'yp_material' === $material->post_type
			&& class_exists( 'YeffoPrint_Commerce_Record_Meta' )
			&& 'clear' === YeffoPrint_Commerce_Record_Meta::swatch_finish( $material );
	}
}
