<?php
/**
 * What an order is worth, described generically.
 *
 * Direct request: "I need the ability to print a proof of value or
 * 'receipt' for UPS claim. But I don't want it to list specifics about
 * the labels, just the cost, the quantity, the generic 'printed labels'
 * text and all of the fees, shipping, etc." Each line item is reduced to
 * a plain kind ("Printed labels", "3D printed item"…) with its quantity
 * and price. Compound names, label text, designs and brands never leave
 * the item, because nothing here reads its visible meta.
 *
 * Also the source of the package value the Shippo panel prefills for
 * insurance (follow-up request: "I need the value of the package sent
 * through shippo… so I can have the option of adding additional
 * insurance"), so the receipt and the insured amount always agree.
 *
 * @package YeffoPrint_Core
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Value {

	/**
	 * One row per line item, with generic wording only.
	 *
	 * qty is the number of labels/stickers for a label batch (some
	 * batches carry WC quantity 1 with the real count in
	 * _yp_batch_quantity), otherwise the item's own quantity. unit and
	 * total are before order discounts, which lines_totals() shows as
	 * their own row, so the rows always add up on paper.
	 *
	 * @return array<int, array{description:string, qty:int, unit:float, total:float}>
	 */
	public static function lines( \WC_Order $order ): array {
		$rows = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$qty   = max( 1, (int) $item->get_quantity(), (int) $item->get_meta( '_yp_batch_quantity', true ) );
			$total = (float) $item->get_subtotal();

			$rows[] = [
				'description' => self::describe( $item ),
				'qty'         => $qty,
				'unit'        => $qty > 0 ? $total / $qty : $total,
				'total'       => $total,
			];
		}
		return $rows;
	}

	/** The generic name for a line: never the product name or any of its meta. */
	public static function describe( \WC_Order_Item_Product $item ): string {
		$product = $item->get_product();

		if ( $product && class_exists( 'YeffoPrint_Web_Design_Package_Product' ) && $product->get_meta( YeffoPrint_Web_Design_Package_Product::META_PACKAGE_ID ) ) {
			return __( 'Website design services', 'yeffoprint-core' );
		}
		if ( $item->get_meta( '_yp_print_snapshot', true )
			|| ( class_exists( 'YeffoPrint_Print_Product' ) && YeffoPrint_Print_Product::get_print_id( (int) $item->get_product_id() ) > 0 ) ) {
			return __( '3D printed item', 'yeffoprint-core' );
		}
		if ( $item->get_meta( '_yp_sticker_type', true ) ) {
			return __( 'Printed stickers', 'yeffoprint-core' );
		}
		if ( $item->get_meta( '_yp_custom_order_id', true ) && ! $item->get_meta( '_yp_batch_quantity', true ) ) {
			return __( 'Custom label design fee', 'yeffoprint-core' );
		}
		return __( 'Printed labels', 'yeffoprint-core' );
	}

	/**
	 * Every charge below the items, in order: discount, shipping, fees,
	 * then refunds. Shipping and fee rows keep their own names (e.g.
	 * "UPS Ground", "Express order"), which say nothing about the labels.
	 *
	 * @return array<int, array{label:string, amount:float}>
	 */
	public static function charges( \WC_Order $order ): array {
		$rows = [];

		$discount = (float) $order->get_total_discount();
		if ( $discount > 0 ) {
			$rows[] = [ 'label' => __( 'Discount', 'yeffoprint-core' ), 'amount' => -$discount ];
		}

		$shipping = $order->get_items( 'shipping' );
		if ( $shipping ) {
			foreach ( $shipping as $line ) {
				$name   = trim( (string) $line->get_name() );
				$rows[] = [
					/* translators: %s: shipping method, e.g. "UPS Ground". */
					'label'  => '' !== $name ? sprintf( __( 'Shipping (%s)', 'yeffoprint-core' ), $name ) : __( 'Shipping', 'yeffoprint-core' ),
					'amount' => (float) $line->get_total(),
				];
			}
		} else {
			$rows[] = [ 'label' => __( 'Shipping', 'yeffoprint-core' ), 'amount' => 0.0 ];
		}

		foreach ( $order->get_fees() as $fee ) {
			$rows[] = [ 'label' => trim( (string) $fee->get_name() ) ?: __( 'Fee', 'yeffoprint-core' ), 'amount' => (float) $fee->get_total() ];
		}

		$tax = (float) $order->get_total_tax();
		if ( $tax > 0 ) {
			$rows[] = [ 'label' => __( 'Tax', 'yeffoprint-core' ), 'amount' => $tax ];
		}

		return $rows;
	}

	/** Items only, after discounts: what the goods in the box are worth. */
	public static function merchandise_value( \WC_Order $order ): float {
		$value = 0.0;
		foreach ( $order->get_items() as $item ) {
			$value += (float) $item->get_total();
		}
		return round( $value, 2 );
	}

	/**
	 * What to prefill the Shippo panel's insurance amount with: the
	 * merchandise value, which is what a carrier claim pays out on.
	 *
	 * @return array{value:float, currency:string}
	 */
	public static function package_value( \WC_Order $order ): array {
		return [
			'value'    => self::merchandise_value( $order ),
			'currency' => $order->get_currency(),
		];
	}
}
