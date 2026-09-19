<?php
/**
 * Lets a customer supply their own shipping address on WooCommerce's
 * "Pay for order" page — direct request: staff creating a manual order
 * over the phone/email don't always have the customer's address yet,
 * and want to check "customer will provide it" instead of blocking on
 * it, then have the customer fill it in themselves when they click the
 * payment link.
 *
 * WooCommerce's own checkout/form-pay.php has no address collection at
 * all (only line items, totals and payment method) — this adds one via
 * two hooks: `woocommerce_pay_order_before_payment` renders a form
 * inside that same page's `<form id="order_review">` (so it rides along
 * with the normal payment POST, no separate step/AJAX call), and
 * `woocommerce_before_pay_action` — fired by WC_Form_Handler::pay_action()
 * right after it has already verified the order key/nonce, before any
 * payment gateway runs — captures and validates the posted fields,
 * blocking payment with a normal WC error notice if the address is
 * incomplete.
 *
 * Gated entirely by NEEDS_ADDRESS_META, set by class-manual-order-
 * creator.php only when staff explicitly check "customer will provide
 * it" — every other order (the overwhelming majority) never renders or
 * checks anything here.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Pay_Address {

	public const NEEDS_ADDRESS_META = '_yp_needs_customer_address';

	/** Posted field suffixes — same shape as class-manual-order-creator.php's own sanitize_address(), just posted as yp_ship_{field} to avoid colliding with anything else on the pay page. */
	private const FIELDS = [ 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ];

	public function __construct() {
		add_action( 'woocommerce_pay_order_before_payment', [ $this, 'render_form' ] );
		add_action( 'woocommerce_before_pay_action', [ $this, 'capture_address' ] );
	}

	public static function needs_address( \WC_Order $order ): bool {
		if ( ! $order->get_meta( self::NEEDS_ADDRESS_META ) ) {
			return false;
		}

		// Also false once a real address exists on the order, regardless
		// of whether the flag was ever explicitly cleared — e.g. staff
		// added one later from the classic order screen instead of
		// waiting on the customer.
		return '' === trim( $order->get_shipping_address_1() ?: $order->get_billing_address_1() );
	}

	/**
	 * WooCommerce doesn't pass $order to this particular hook (unlike
	 * woocommerce_before_pay_action below) — the pay-for-order endpoint
	 * sets it as the `order-pay` query var, the same place
	 * WC_Form_Handler::pay_action() itself reads the order id from.
	 */
	public function render_form(): void {
		$order_id = absint( get_query_var( 'order-pay' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof \WC_Order || ! self::needs_address( $order ) ) {
			return;
		}

		$fields = self::FIELDS;
		$labels = [
			'first_name' => __( 'First name', 'yeffoprint' ),
			'last_name'  => __( 'Last name', 'yeffoprint' ),
			'address_1'  => __( 'Street address', 'yeffoprint' ),
			'address_2'  => __( 'Apt, suite, etc. (optional)', 'yeffoprint' ),
			'city'       => __( 'City', 'yeffoprint' ),
			'state'      => __( 'State', 'yeffoprint' ),
			'postcode'   => __( 'ZIP / postal code', 'yeffoprint' ),
			'country'    => __( 'Country', 'yeffoprint' ),
			'phone'      => __( 'Phone (optional)', 'yeffoprint' ),
		];
		$values = [
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'country'    => 'US',
			'phone'      => $order->get_billing_phone(),
		];

		?>
		<div class="yp-pay-address">
			<span class="yp-pay-address__title"><?php esc_html_e( 'Shipping address', 'yeffoprint' ); ?></span>
			<p class="yp-pay-address__body"><?php esc_html_e( "We don't have a shipping address on file yet for this order — please add it below before paying.", 'yeffoprint' ); ?></p>
			<div class="yp-pay-address__grid">
				<?php foreach ( $fields as $field ) : ?>
					<div class="yp-pay-address__field yp-pay-address__field--<?php echo esc_attr( $field ); ?>">
						<label for="yp-ship-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $labels[ $field ] ); ?></label>
						<input
							type="text"
							id="yp-ship-<?php echo esc_attr( $field ); ?>"
							name="yp_ship_<?php echo esc_attr( $field ); ?>"
							value="<?php echo esc_attr( $values[ $field ] ?? '' ); ?>"
						/>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Fires after WC_Form_Handler::pay_action() has already verified the
	 * order key + `woocommerce-pay` nonce, before any payment gateway
	 * runs — adding a wc_add_notice( …, 'error' ) here stops payment the
	 * same way an invalid payment method already does (pay_action() only
	 * calls process_payment() when wc_notice_count( 'error' ) is still 0).
	 */
	public function capture_address( \WC_Order $order ): void {
		if ( ! self::needs_address( $order ) ) {
			return;
		}

		$address = $this->sanitize_posted_address();
		if ( is_wp_error( $address ) ) {
			wc_add_notice( $address->get_error_message(), 'error' );
			return;
		}

		$order->set_shipping_first_name( $address['first_name'] ?: $order->get_billing_first_name() );
		$order->set_shipping_last_name( $address['last_name'] ?: $order->get_billing_last_name() );
		$order->set_shipping_address_1( $address['address_1'] );
		$order->set_shipping_address_2( $address['address_2'] );
		$order->set_shipping_city( $address['city'] );
		$order->set_shipping_state( $address['state'] );
		$order->set_shipping_postcode( $address['postcode'] );
		$order->set_shipping_country( $address['country'] );
		$order->set_shipping_phone( $address['phone'] );

		// Billing defaults from the same address when the order has none
		// of its own yet — same "ship = bill unless told otherwise"
		// convention class-manual-order-creator.php's own create() uses.
		if ( '' === trim( $order->get_billing_address_1() ) ) {
			$order->set_billing_first_name( $address['first_name'] ?: $order->get_billing_first_name() );
			$order->set_billing_last_name( $address['last_name'] ?: $order->get_billing_last_name() );
			$order->set_billing_address_1( $address['address_1'] );
			$order->set_billing_address_2( $address['address_2'] );
			$order->set_billing_city( $address['city'] );
			$order->set_billing_state( $address['state'] );
			$order->set_billing_postcode( $address['postcode'] );
			$order->set_billing_country( $address['country'] );
			if ( $address['phone'] ) {
				$order->set_billing_phone( $address['phone'] );
			}
		}

		// Also fills a customer account's own saved address when it has
		// none — same reasoning and "never overwrite an existing half"
		// rule as class-manual-order-creator.php's own
		// maybe_save_address_to_profile().
		if ( $order->get_customer_id() ) {
			$this->maybe_save_to_profile( $order->get_customer_id(), $address );
		}

		$order->update_meta_data( self::NEEDS_ADDRESS_META, '' );
		$order->add_order_note( __( 'Customer provided their shipping address on the payment page.', 'yeffoprint' ) );
		$order->save();
	}

	/** @return array{first_name:string,last_name:string,address_1:string,address_2:string,city:string,state:string,postcode:string,country:string,phone:string}|\WP_Error */
	private function sanitize_posted_address() {
		$fields = [];
		foreach ( self::FIELDS as $field ) {
			$value             = isset( $_POST[ 'yp_ship_' . $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'yp_ship_' . $field ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- covered by pay_action()'s own woocommerce-pay nonce, already verified before this hook fires.
			$fields[ $field ]  = 'country' === $field ? strtoupper( $value ) : $value;
		}

		$required = [
			'address_1' => __( 'street address', 'yeffoprint' ),
			'city'      => __( 'city', 'yeffoprint' ),
			'state'     => __( 'state', 'yeffoprint' ),
			'postcode'  => __( 'ZIP/postal code', 'yeffoprint' ),
			'country'   => __( 'country', 'yeffoprint' ),
		];

		foreach ( $required as $key => $label ) {
			if ( '' === $fields[ $key ] ) {
				return new \WP_Error(
					'yeffoprint_incomplete_pay_address',
					sprintf(
						/* translators: %s: the missing field's label */
						__( 'Please enter your %s to continue.', 'yeffoprint' ),
						$label
					)
				);
			}
		}

		return $fields;
	}

	private function maybe_save_to_profile( int $user_id, array $address ): void {
		$customer = new \WC_Customer( $user_id );
		if ( '' !== trim( $customer->get_shipping_address_1() ) ) {
			return;
		}

		$customer->set_shipping_first_name( $address['first_name'] );
		$customer->set_shipping_last_name( $address['last_name'] );
		$customer->set_shipping_address_1( $address['address_1'] );
		$customer->set_shipping_address_2( $address['address_2'] );
		$customer->set_shipping_city( $address['city'] );
		$customer->set_shipping_state( $address['state'] );
		$customer->set_shipping_postcode( $address['postcode'] );
		$customer->set_shipping_country( $address['country'] );
		$customer->set_shipping_phone( $address['phone'] );
		$customer->save();
	}
}
