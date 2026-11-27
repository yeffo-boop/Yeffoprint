<?php
/**
 * Ships an add-on group as one box — direct request: "When I ship an
 * order that has a linked order (because a customer added to their
 * completed order) can the status for all linked orders change to
 * shipped automatically? The customer shouldn't receive any additional
 * shipping emails (just the original) and the tracking number should
 * be linked to all linked orders."
 *
 * A group is the root order plus every add-on pointing at it
 * (YeffoPrint_Order_Addon::SHIP_WITH_ORDER_ID_META). The moment any one
 * of them turns Shipped, by any path (a Shippo or WC Shipping label,
 * which class-order-shipment-status.php turns into Shipped, or a manual
 * status change), every other paid order in the group still in
 * Processing / In Production / Curing is marked Shipped too and gets
 * SHIPPED_WITH_META pointing at the order that holds the label. Unpaid
 * add-ons are left alone: they haven't been paid for, so they aren't in
 * the box.
 *
 * Tracking isn't copied. YeffoPrint_Order_Tracking::get_shipments()
 * follows SHIPPED_WITH_META and reads the label order's own shipments
 * live, so every linked order shows the same tracking number, and a
 * voided or replaced label updates all of them at once.
 *
 * One customer email per box: is_email_sender() picks the original
 * (root) order when it's in the box, otherwise the order the label was
 * bought on. The shipped email, the Telegram "has shipped" message and
 * the delivered (Completed) email all go out for that order only, and
 * the shipped email lists the other order numbers that are in the box.
 *
 * Delivery: linked orders skip their own carrier lookups in the hourly
 * sweep (class-order-delivery-status.php) and move to Completed when the
 * label order does.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Addon_Shipping {

	/** On a linked order: the id of the order in the same group whose label it shipped under. */
	public const SHIPPED_WITH_META = '_yp_shipped_with_order_id';

	private static bool $propagating = false;

	public function __construct() {
		// Before the shipped email (priority 10), so the email already
		// knows which orders went in the box.
		add_action( 'woocommerce_order_status_' . YeffoPrint_Order_Shipment_Status::STATUS, [ $this, 'ship_linked' ], 5, 2 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'complete_linked' ], 5, 2 );
		add_filter( 'woocommerce_email_enabled_customer_completed_order', [ $this, 'filter_completed_email' ], 10, 2 );
	}

	/** The order whose label this one shipped under, or null when it holds its own (or none). */
	public static function label_order_for( \WC_Order $order ): ?\WC_Order {
		$label_id = (int) $order->get_meta( self::SHIPPED_WITH_META, true );
		if ( ! $label_id || $label_id === $order->get_id() ) {
			return null;
		}
		$label_order = wc_get_order( $label_id );
		return $label_order instanceof \WC_Order ? $label_order : null;
	}

	/** @return int[] Root first, then its add-ons. */
	public static function group_ids( \WC_Order $order ): array {
		if ( ! class_exists( 'YeffoPrint_Order_Addon' ) ) {
			return [ $order->get_id() ];
		}
		$root_id = YeffoPrint_Order_Addon::root_id_for( $order );
		$ids     = array_map( 'intval', YeffoPrint_Order_Addon::addon_ids_for_root( $root_id ) );
		array_unshift( $ids, $root_id );
		return array_values( array_unique( $ids ) );
	}

	/** Statuses where an order in a group no longer counts: it won't be made or shipped. */
	private const DEAD_STATUSES = [ 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft' ];

	/**
	 * Direct report: "I didn't see the second order in the list and
	 * didn't know it existed because I prepped the first order and didn't
	 * see any mention of a second order, so went right to shipping."
	 * Every other live order in $order's add-on group, for the order page,
	 * Ship screen and production view banners.
	 *
	 * 'needs_printing' — paid, still Processing (not sent to the printer):
	 * its labels haven't been made yet but it will go in this box.
	 * 'unpaid' — won't ship with the box until it's paid.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function linked_payload( \WC_Order $order ): array {
		$ids = self::group_ids( $order );
		if ( count( $ids ) < 2 ) {
			return [];
		}

		$root_id = $ids[0];
		$rows    = [];
		foreach ( $ids as $id ) {
			if ( $id === $order->get_id() ) {
				continue;
			}
			$other = wc_get_order( $id );
			if ( ! $other instanceof \WC_Order || in_array( $other->get_status(), self::DEAD_STATUSES, true ) ) {
				continue;
			}
			$status = $other->get_status();
			$unpaid = in_array( $status, [ 'pending', 'on-hold' ], true );
			$rows[] = [
				'id'             => $other->get_id(),
				'number'         => $other->get_order_number(),
				'status'         => $status,
				'status_label'   => wc_get_order_status_name( $status ),
				'role'           => $id === $root_id ? 'original' : 'add-on',
				'unpaid'         => $unpaid,
				'needs_printing' => 'processing' === $status,
				'label_summary'  => class_exists( 'YeffoPrint_Order_Label_Count' ) ? YeffoPrint_Order_Label_Count::summary( $other ) : '',
				'designed'       => class_exists( 'YeffoPrint_Order_Design_Sheet' ) ? YeffoPrint_Order_Design_Sheet::progress( $other ) : null,
			];
		}

		return $rows;
	}

	/**
	 * Every other order that went out in the same box as $order.
	 *
	 * @return \WC_Order[]
	 */
	public static function shipped_together( \WC_Order $order ): array {
		$label_id = (int) $order->get_meta( self::SHIPPED_WITH_META, true ) ?: $order->get_id();
		$others   = [];

		foreach ( self::group_ids( $order ) as $id ) {
			if ( $id === $order->get_id() ) {
				continue;
			}
			$other = wc_get_order( $id );
			if ( ! $other instanceof \WC_Order ) {
				continue;
			}
			$is_label_order = $id === $label_id;
			$is_linked      = (int) $other->get_meta( self::SHIPPED_WITH_META, true ) === $label_id;
			if ( $is_label_order || $is_linked ) {
				$others[] = $other;
			}
		}

		return $others;
	}

	/**
	 * Whether this order's shipped/delivered customer messages should go
	 * out. False only for the orders in a shared box that aren't the one
	 * picked to speak for it: the root when it's in the box, otherwise the
	 * label order.
	 */
	public static function is_email_sender( \WC_Order $order ): bool {
		if ( class_exists( 'YeffoPrint_Order_Addon' ) ) {
			$root_id = YeffoPrint_Order_Addon::root_id_for( $order );
		} else {
			$root_id = $order->get_id();
		}

		if ( (int) $order->get_meta( self::SHIPPED_WITH_META, true ) ) {
			// Linked into someone else's box: only the root speaks for it.
			return $root_id === $order->get_id();
		}

		if ( $root_id === $order->get_id() ) {
			return true;
		}

		// An add-on holding the label: stays quiet when the root rode along.
		$root = wc_get_order( $root_id );
		return ! ( $root instanceof \WC_Order && (int) $root->get_meta( self::SHIPPED_WITH_META, true ) === $order->get_id() );
	}

	/**
	 * @param int             $order_id
	 * @param \WC_Order|false $order
	 */
	public function ship_linked( $order_id, $order = false ): void {
		if ( self::$propagating ) {
			return;
		}
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof \WC_Order || (int) $order->get_meta( self::SHIPPED_WITH_META, true ) ) {
			return;
		}

		$active  = YeffoPrint_Order_Addon::active_statuses();
		$targets = [];
		foreach ( self::group_ids( $order ) as $id ) {
			if ( $id === $order->get_id() ) {
				continue;
			}
			$other = wc_get_order( $id );
			if ( $other instanceof \WC_Order && in_array( $other->get_status(), $active, true ) ) {
				$targets[] = $other;
			}
		}
		if ( ! $targets ) {
			return;
		}

		self::$propagating = true;
		try {
			// Link every order first, so whichever one's shipped email
			// fires during the loop below already sees the whole box.
			foreach ( $targets as $other ) {
				$other->update_meta_data( self::SHIPPED_WITH_META, $order->get_id() );
				$other->save_meta_data();
			}

			$numbers = [];
			foreach ( $targets as $other ) {
				$other->set_status(
					YeffoPrint_Order_Shipment_Status::STATUS,
					sprintf(
						/* translators: %s: order number */
						__( 'Shipped together with order #%s — same box and tracking number, no separate shipped email.', 'yeffoprint-core' ),
						$order->get_order_number()
					)
				);
				$other->save();
				$numbers[] = '#' . $other->get_order_number();
			}

			$order->add_order_note(
				sprintf(
					/* translators: %s: list of order numbers */
					__( 'Linked orders marked Shipped in the same box: %s.', 'yeffoprint-core' ),
					implode( ', ', $numbers )
				)
			);
		} finally {
			self::$propagating = false;
		}
	}

	/**
	 * @param int             $order_id
	 * @param \WC_Order|false $order
	 */
	public function complete_linked( $order_id, $order = false ): void {
		if ( self::$propagating ) {
			return;
		}
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof \WC_Order || (int) $order->get_meta( self::SHIPPED_WITH_META, true ) ) {
			return;
		}

		self::$propagating = true;
		try {
			foreach ( self::shipped_together( $order ) as $other ) {
				if ( YeffoPrint_Order_Shipment_Status::STATUS !== $other->get_status() ) {
					continue;
				}
				YeffoPrint_Order_Shipment_Status::record_current_fingerprint( $other );
				$other->update_status(
					'completed',
					sprintf(
						/* translators: %s: order number */
						__( 'Completed together with order #%s (shipped in the same box).', 'yeffoprint-core' ),
						$order->get_order_number()
					)
				);
			}
		} finally {
			self::$propagating = false;
		}
	}

	/**
	 * @param bool                 $enabled
	 * @param \WC_Order|mixed|null $order
	 */
	public function filter_completed_email( $enabled, $order = null ) {
		if ( $enabled && $order instanceof \WC_Order && ! self::is_email_sender( $order ) ) {
			return false;
		}
		return $enabled;
	}
}
