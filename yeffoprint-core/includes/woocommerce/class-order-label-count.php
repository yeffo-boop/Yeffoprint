<?php
/**
 * How many labels (and stickers) an order holds, in total.
 *
 * Direct request: "I don't see anywhere in the admin panel (and the
 * telegram bot should really show it too) where it shows the total
 * number of labels in an order." The admin app only showed WooCommerce's
 * own line quantities, and a Template batch often carries WC quantity 1
 * with the real count in `_yp_batch_quantity`, so "× 1" was all it said.
 *
 * Labels = every label batch line (Template batches and Custom Design
 * labels lines). Stickers are counted on their own, the same separate
 * pool their bulk pricing uses (YeffoPrint_Cart_Pricing::combined_
 * sticker_quantity()), so "120 labels · 20 stickers" never mixes the two.
 * Fee lines, 3D prints and web design items aren't labels and are left out.
 *
 * @package YeffoPrint_Core
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Label_Count {

	/** Labels or stickers on one line item; null when the line is neither. */
	public static function for_item( \WC_Order_Item $item ): ?array {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return null;
		}
		$quantity = (int) $item->get_meta( '_yp_batch_quantity', true );
		if ( $quantity <= 0 ) {
			return null;
		}
		return [
			'kind'     => '' !== (string) $item->get_meta( '_yp_sticker_type', true ) ? 'stickers' : 'labels',
			'quantity' => $quantity,
		];
	}

	/**
	 * The quantity to show next to a line in a plain-text list (Telegram,
	 * push alerts): the batch's label/sticker count when it has one, else
	 * WooCommerce's own quantity.
	 */
	public static function item_quantity( \WC_Order_Item $item ): int {
		$count = self::for_item( $item );
		return $count ? $count['quantity'] : (int) $item->get_quantity();
	}

	/** "In total: 120 labels · 20 stickers" for a plain-text message, or '' when the order has none. */
	public static function total_line( \WC_Order $order ): string {
		$summary = self::summary( $order );
		/* translators: %s: e.g. "120 labels · 20 stickers" */
		return '' === $summary ? '' : sprintf( __( 'In total: %s', 'yeffoprint-core' ), $summary );
	}

	/** @return array{labels:int, stickers:int} */
	public static function totals( \WC_Order $order ): array {
		$totals = [ 'labels' => 0, 'stickers' => 0 ];
		foreach ( $order->get_items() as $item ) {
			$count = self::for_item( $item );
			if ( $count ) {
				$totals[ $count['kind'] ] += $count['quantity'];
			}
		}
		return $totals;
	}

	/** "120 labels · 20 stickers", "1 label", or '' when the order has neither. */
	public static function summary( \WC_Order $order ): string {
		return self::format( self::totals( $order ) );
	}

	/** @param array{labels?:int, stickers?:int} $totals */
	public static function format( array $totals ): string {
		$parts  = [];
		$labels = (int) ( $totals['labels'] ?? 0 );
		$stickers = (int) ( $totals['stickers'] ?? 0 );
		if ( $labels ) {
			/* translators: %s: number of labels */
			$parts[] = sprintf( _n( '%s label', '%s labels', $labels, 'yeffoprint-core' ), number_format_i18n( $labels ) );
		}
		if ( $stickers ) {
			/* translators: %s: number of stickers */
			$parts[] = sprintf( _n( '%s sticker', '%s stickers', $stickers, 'yeffoprint-core' ), number_format_i18n( $stickers ) );
		}
		return implode( ' · ', $parts );
	}
}
