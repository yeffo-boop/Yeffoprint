<?php
/**
 * Visual order-status stepper — direct request: "a visual order-status
 * stepper... instead of a plain text status pill" on customer-facing
 * order emails and account pages.
 *
 * Two independent state machines back this one visual. WooCommerce's own
 * order status drives payment/fulfillment (Placed → In Production →
 * Shipped → Delivered — class-order-production-status.php/class-order-
 * shipment-status.php/class-order-delivery-status.php). Separately —
 * only for an order with a linked CustomOrder request (Fully Custom
 * Design, Custom Stickers, or a staff-flagged Template order; class-
 * custom-order-meta.php's own docblock, §6) — a Proof Approval stage is
 * inserted before In Production, driven by that CustomOrder's own
 * `_yp_status` instead.
 *
 * steps() always returns stages in position order and derives each
 * one's displayed state *positionally*: whichever stage is the first
 * not yet complete becomes "current", and every stage after it is
 * forced to "upcoming" regardless of what its own underlying status
 * flag says. Deliberate — the two pipelines aren't kept in lockstep
 * with each other (a WC order can show "in-production" while its linked
 * CustomOrder is still "awaiting_approval" if staff update one without
 * the other), and a stepper that jumped stages out of order would be
 * more confusing than one that's briefly a step "behind" the most
 * advanced individual signal.
 *
 * No stepper at all for a cancelled/refunded/failed order — a forward-
 * moving progress bar has nothing honest to say about an order that
 * didn't complete, so both render hooks below simply print nothing and
 * whatever plain status text WooCommerce already shows stands as-is.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Status_Stepper {

	/** WC_Email ids this renders on — same set class-telegram-order-email-badge.php already targets, the customer-facing lifecycle emails. */
	private const EMAIL_IDS = [ 'customer_processing_order', 'customer_shipped_order', 'customer_completed_order', 'customer_invoice' ];

	/** Shippo's own tracking-status enum (class-shippo-client.php's parse_tracking_payload()), mapped to customer-facing words. Anything else (UNKNOWN, empty) falls back to a generic "On the way". */
	private const TRACKING_LABELS = [
		'PRE_TRANSIT' => 'Label created',
		'TRANSIT'     => 'In transit',
		'DELIVERED'   => 'Delivered',
		'RETURNED'    => 'Returned to sender',
		'FAILURE'     => 'Delivery issue — we’re on it',
	];

	public function __construct() {
		add_action( 'woocommerce_order_details_before_order_table', [ $this, 'render_on_view_order' ] );
		// Priority 5: fires before class-telegram-order-email-badge.php's
		// own default-priority callback on the same hook, so the stepper
		// sits right after the greeting and the bot callout still follows
		// right after it, same relative order as before this existed.
		add_action( 'woocommerce_email_before_order_table', [ $this, 'render_on_email' ], 5, 4 );
	}

	public function render_on_view_order( \WC_Order $order ): void {
		$steps = self::steps( $order );
		if ( $steps ) {
			echo self::render_html( $steps ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_html() escapes every value itself.
		}
	}

	public function render_on_email( \WC_Order $order, bool $sent_to_admin, bool $plain_text, \WC_Email $email ): void {
		if ( $sent_to_admin || $plain_text || ! in_array( $email->id, self::EMAIL_IDS, true ) ) {
			return;
		}

		$steps = self::steps( $order );
		if ( $steps ) {
			echo self::render_email_html( $steps ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_email_html() escapes every value itself.
		}
	}

	/**
	 * @return array<int,array{key:string,label:string,sublabel:string,state:string}> Empty for a cancelled/refunded/failed order.
	 */
	public static function steps( \WC_Order $order ): array {
		$status = $order->get_status();

		if ( in_array( $status, [ 'cancelled', 'refunded', 'failed' ], true ) ) {
			return [];
		}

		$custom_order_status = self::linked_custom_order_status( $order->get_id() );
		$has_proof_stage     = null !== $custom_order_status;

		$stages = [
			[ 'key' => 'placed', 'label' => __( 'Order Placed', 'yeffoprint-core' ) ],
		];

		if ( $has_proof_stage ) {
			$stages[] = [ 'key' => 'proof', 'label' => __( 'Proof Approval', 'yeffoprint-core' ) ];
		}

		$stages[] = [ 'key' => 'production', 'label' => __( 'In Production', 'yeffoprint-core' ) ];
		$stages[] = [ 'key' => 'shipped', 'label' => __( 'Shipped', 'yeffoprint-core' ) ];
		$stages[] = [ 'key' => 'delivered', 'label' => __( 'Delivered', 'yeffoprint-core' ) ];

		// Whether each stage is independently done — the render loop below
		// still won't trust these blindly past the first incomplete one;
		// see this class's own docblock.
		$complete = [
			'placed'     => ! in_array( $status, [ 'pending', 'on-hold' ], true ),
			'proof'      => in_array( $custom_order_status, [ 'approved', 'printing', 'shipped' ], true ),
			'production' => in_array( $status, [ 'shipped', 'completed' ], true ),
			'shipped'    => 'completed' === $status,
			'delivered'  => 'completed' === $status,
		];

		$sublabels = [
			'placed'     => in_array( $status, [ 'pending', 'on-hold' ], true ) ? __( 'Awaiting payment', 'yeffoprint-core' ) : '',
			'proof'      => self::proof_sublabel( $custom_order_status ),
			'production' => 'in-production' === $status
				? __( 'Your labels are being printed', 'yeffoprint-core' )
				: __( 'We’re getting your order ready to print', 'yeffoprint-core' ),
			'shipped'    => self::shipped_sublabel( $order ),
			'delivered'  => '',
		];

		$current_assigned = false;
		$last_index       = array_key_last( $stages );

		foreach ( $stages as $index => &$stage ) {
			$key = $stage['key'];

			if ( $current_assigned ) {
				$stage['state']    = 'upcoming';
				$stage['sublabel'] = '';
				continue;
			}

			if ( ! empty( $complete[ $key ] ) ) {
				$stage['state']    = 'complete';
				$stage['sublabel'] = '';
				continue;
			}

			// First incomplete stage — unless it's also the last one, in
			// which case there's nothing left to look forward to, so a
			// fully "done" terminal look reads better than a "current"
			// one that implies more is still coming.
			$stage['state']   = $index === $last_index ? 'complete' : 'current';
			$stage['sublabel'] = $sublabels[ $key ];
			$current_assigned  = true;
		}
		unset( $stage );

		return $stages;
	}

	/**
	 * A WC order can, in principle, have more than one linked CustomOrder
	 * (e.g. two separate Custom Design submissions paid in one checkout)
	 * — the least-advanced one's status is what the order as a whole
	 * should show, since the order isn't past a stage until every one of
	 * its requests is.
	 */
	private static function linked_custom_order_status( int $wc_order_id ): ?string {
		$posts = get_posts( [
			'post_type'   => 'yp_custom_order',
			'post_status' => 'any',
			'numberposts' => -1,
			'meta_key'    => YeffoPrint_Custom_Order_Meta::WC_ORDER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one order's own lookup, not a listing screen.
			'meta_value'  => $wc_order_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'      => 'ids',
		] );

		if ( ! $posts ) {
			return null;
		}

		$rank           = array_flip( array_keys( YeffoPrint_Custom_Order_Meta::STATUSES ) );
		$least_advanced = null;

		foreach ( $posts as $post_id ) {
			$status = (string) get_post_meta( $post_id, YeffoPrint_Custom_Order_Meta::STATUS, true );
			if ( '' === $status || ! isset( $rank[ $status ] ) ) {
				continue;
			}
			if ( null === $least_advanced || $rank[ $status ] < $rank[ $least_advanced ] ) {
				$least_advanced = $status;
			}
		}

		return $least_advanced;
	}

	private static function proof_sublabel( ?string $custom_order_status ): string {
		if ( 'awaiting_approval' === $custom_order_status ) {
			return __( 'We sent a proof — check your email to approve it', 'yeffoprint-core' );
		}

		return __( 'Our designer is working on your proof', 'yeffoprint-core' );
	}

	/** Last known-good live tracking status (the hourly sweep/webhook's own stored meta — no live API call here) across every shipment, or a generic fallback if none is recorded yet. */
	private static function shipped_sublabel( \WC_Order $order ): string {
		if ( ! class_exists( 'YeffoPrint_Order_Tracking' ) || ! class_exists( 'YeffoPrint_Order_Delivery_Status' ) ) {
			return __( 'On the way', 'yeffoprint-core' );
		}

		foreach ( YeffoPrint_Order_Tracking::get_shipments( $order ) as $shipment ) {
			$status = YeffoPrint_Order_Delivery_Status::get_status( $order, $shipment['tracking_number'] );
			if ( $status && isset( self::TRACKING_LABELS[ $status['status'] ] ) ) {
				return self::TRACKING_LABELS[ $status['status'] ];
			}
		}

		return __( 'On the way', 'yeffoprint-core' );
	}

	/** Web renderer (flexbox, theme CSS) — used on My Account → View Order. The public /track-order/ page renders the same steps() data client-side instead (assets/js/track-order.js), since that page is already JSON/REST-driven. */
	public static function render_html( array $steps ): string {
		if ( ! $steps ) {
			return '';
		}

		$items = '';
		foreach ( $steps as $index => $step ) {
			$dot      = 'complete' === $step['state'] ? '&#10003;' : (string) ( $index + 1 );
			$sublabel = $step['sublabel']
				? sprintf( '<span class="yp-order-stepper__sublabel">%s</span>', esc_html( $step['sublabel'] ) )
				: '';

			$items .= sprintf(
				'<li class="yp-order-stepper__step is-%1$s"><span class="yp-order-stepper__dot" aria-hidden="true">%2$s</span><span class="yp-order-stepper__label">%3$s</span>%4$s</li>',
				esc_attr( $step['state'] ),
				$dot, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- either a literal HTML entity or a cast digit, never user data.
				esc_html( $step['label'] ),
				$sublabel // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above.
			);
		}

		return '<ol class="yp-order-stepper">' . $items . '</ol>';
	}

	/**
	 * Email renderer — a plain table of circles-and-connectors, no sub-
	 * text under each label (an inbox at 600px wide across 4-5 columns
	 * has no room for a second line, and every one of these templates
	 * already explains the moment in its own prose). Circles use
	 * border-radius, which Outlook's desktop rendering engine ignores —
	 * they degrade to squares there, same tradeoff this theme's other
	 * rounded email cards (email-styles.php's .yp-payment-cta, .yp-bot-
	 * callout) already accept.
	 */
	public static function render_email_html( array $steps ): string {
		if ( ! $steps ) {
			return '';
		}

		$cells = '';
		foreach ( $steps as $index => $step ) {
			if ( $index > 0 ) {
				$active = 'upcoming' !== $step['state'];
				// A fixed pixel width="32" (as an HTML attribute, not just
				// the matching CSS rule in email-styles.php — Outlook
				// desktop's Word rendering engine ignores CSS width on
				// table cells but respects the attribute) rather than a
				// percentage: an empty <td> with no width collapses to
				// zero (the connecting line disappears entirely), and a
				// percentage width fights unpredictably with the other
				// percentage-free step cells in the same row across email
				// clients' differing table-layout algorithms. A fixed
				// width sidesteps both failure modes.
				//
				// The colored line itself lives in a nested single-cell
				// table with an explicit height="2" HTML attribute rather
				// than directly on this outer <td> — a bare <td>'s
				// background otherwise stretches to match this row's full
				// height (set by the much taller circle+label cells next
				// to it), painting a thick block instead of a thin line.
				// valign="middle" on the outer cell centers that short
				// nested table within the row.
				$cells .= sprintf(
					'<td class="yp-stepper-connector-cell" width="32" valign="middle"><table role="presentation" cellpadding="0" cellspacing="0" width="100%%"><tr><td class="yp-stepper-connector%1$s" height="2"></td></tr></table></td>',
					$active ? ' is-active' : ''
				);
			}

			$dot_class   = 'complete' === $step['state'] ? 'is-complete' : ( 'current' === $step['state'] ? 'is-current' : 'is-upcoming' );
			$dot_content = 'complete' === $step['state'] ? '&#10003;' : (string) ( $index + 1 );
			$label_class = 'current' === $step['state'] ? 'is-current' : '';

			$cells .= sprintf(
				'<td class="yp-stepper-step"><table role="presentation" cellpadding="0" cellspacing="0" align="center"><tr><td class="yp-stepper-dot %1$s">%2$s</td></tr></table><span class="yp-stepper-label %3$s">%4$s</span></td>',
				esc_attr( $dot_class ),
				$dot_content, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- either a literal HTML entity or a cast digit, never user data.
				esc_attr( $label_class ),
				esc_html( $step['label'] )
			);
		}

		return '<table class="yp-order-stepper-email" role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr>' . $cells . '</tr></table>';
	}
}
