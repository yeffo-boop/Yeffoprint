<?php
/**
 * Address and shipping-method step on WooCommerce's "Pay for order"
 * page, for orders staff create in the admin app.
 *
 * First version — direct request: staff creating a manual order over
 * the phone/email don't always have the customer's address yet, and
 * want to check "customer will provide it" instead of blocking on it,
 * then have the customer fill it in themselves when they click the
 * payment link.
 *
 * This revision — direct request: "When I create an order, and the link
 * gets sent to the customer to pay, can they edit the billing/shipping
 * address that's already filled out? Can they also choose a shipping
 * option at that point so I don't need to? We also need to restrict
 * international shipping to just international customers and the other
 * 2 to domestic customers." So every admin-created order now shows its
 * shipping address (prefilled, editable) plus an optional separate
 * billing address, and an order staff left on "Customer picks" shipping
 * also shows the saved manual order shipping options (Settings →
 * Shipping) that fit the address's country, with the page's totals
 * updating live as one is picked (assets/frontend/pay-order-address.js).
 *
 * WooCommerce's own checkout/form-pay.php has no address collection at
 * all (only line items, totals and payment method) — this adds one via
 * two hooks: `woocommerce_pay_order_before_payment` renders the fields
 * inside that same page's `<form id="order_review">` (so they ride along
 * with the normal payment POST, no separate step), and
 * `woocommerce_before_pay_action` — fired by WC_Form_Handler::pay_action()
 * right after it has already verified the order key/nonce, before any
 * payment gateway runs — validates and saves them, blocking payment with
 * a normal WC error notice when something is missing. Hooked at priority
 * 5 so the shipping line is already on the order when class-card-
 * surcharge.php's own before_pay_action handler (priority 10) sizes its
 * fee from the order total.
 *
 * The fields use WooCommerce's own shipping_ and billing_ field names and
 * wrappers, so the country-select script WooCommerce already loads on
 * this page turns State into a dropdown for countries that have states.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Order_Pay_Address {

	public const NEEDS_ADDRESS_META           = '_yp_needs_customer_address';
	public const CUSTOMER_PICKS_SHIPPING_META = '_yp_customer_picks_shipping';

	/** Flags the shipping line the customer picked, so a later pick replaces it. */
	private const CHOSEN_SHIPPING_ITEM_META = '_yp_customer_chosen_shipping';

	private const FIELDS = [ 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ];

	public function __construct() {
		add_action( 'woocommerce_pay_order_before_payment', [ $this, 'render_form' ] );
		add_action( 'woocommerce_before_pay_action', [ $this, 'capture' ], 5 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_script' ] );
		add_action( 'wp_ajax_yeffoprint_pay_order_shipping', [ $this, 'ajax_select_shipping' ] );
		add_action( 'wp_ajax_nopriv_yeffoprint_pay_order_shipping', [ $this, 'ajax_select_shipping' ] );
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

	/** Admin-created orders only — an order the customer placed through the normal checkout already had its address and shipping chosen there. */
	public static function is_editable( \WC_Order $order ): bool {
		return $order->needs_payment()
			&& ( 'yeffoprint-admin' === $order->get_created_via() || (bool) $order->get_meta( self::NEEDS_ADDRESS_META ) );
	}

	public static function customer_picks_shipping( \WC_Order $order ): bool {
		return (bool) $order->get_meta( self::CUSTOMER_PICKS_SHIPPING_META );
	}

	private static function pay_page_order(): ?\WC_Order {
		$order_id = absint( get_query_var( 'order-pay' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		return $order instanceof \WC_Order ? $order : null;
	}

	public function enqueue_script(): void {
		if ( ! function_exists( 'is_checkout_pay_page' ) || ! is_checkout_pay_page() ) {
			return;
		}

		$order = self::pay_page_order();
		if ( ! $order || ! self::is_editable( $order ) ) {
			return;
		}

		$path = 'assets/frontend/pay-order-address.js';
		wp_enqueue_script(
			'yeffoprint-pay-order-address',
			YEFFOPRINT_CORE_URL . $path,
			[ 'jquery' ],
			yeffoprint_core_asset_version( $path ),
			true
		);

		wp_localize_script( 'yeffoprint-pay-order-address', 'yeffoprintPayOrderAddress', [
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'orderId'         => $order->get_id(),
			'nonce'           => wp_create_nonce( 'yeffoprint_pay_order_shipping' ),
			'domesticCountry' => YeffoPrint_Shippo_Settings::domestic_country(),
		] );
	}

	/**
	 * WooCommerce doesn't pass $order to this particular hook (unlike
	 * woocommerce_before_pay_action below) — the pay-for-order endpoint
	 * sets it as the `order-pay` query var, the same place
	 * WC_Form_Handler::pay_action() itself reads the order id from.
	 */
	public function render_form(): void {
		$order = self::pay_page_order();
		if ( ! $order || ! self::is_editable( $order ) ) {
			return;
		}

		$shipping = self::order_address( $order, 'shipping' );
		$billing  = self::order_address( $order, 'billing' );

		// An order with only a billing address on file ships there too.
		if ( '' === $shipping['address_1'] && '' !== $billing['address_1'] ) {
			$shipping = array_merge( $billing, [ 'phone' => $shipping['phone'] ?: $billing['phone'] ] );
		}
		if ( '' === $shipping['first_name'] ) {
			$shipping['first_name'] = $order->get_billing_first_name();
			$shipping['last_name']  = $order->get_billing_last_name();
		}
		if ( '' === $shipping['country'] ) {
			$shipping['country'] = YeffoPrint_Shippo_Settings::domestic_country();
		}
		if ( '' === $billing['country'] ) {
			$billing['country'] = $shipping['country'];
		}

		$billing_differs = '' !== $billing['address_1'] && self::addresses_differ( $shipping, $billing );
		$posted_differs  = isset( $_POST['woocommerce_pay'] ) ? ! empty( $_POST['yp_bill_to_different'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- only re-shows what the customer just posted after a failed submission.
		if ( null !== $posted_differs ) {
			$billing_differs = $posted_differs;
		}

		$body = self::needs_address( $order )
			? __( "We don't have a shipping address on file yet for this order. Please add it below before paying.", 'yeffoprint' )
			: __( 'Please check where this order ships. You can change it below.', 'yeffoprint' );

		?>
		<div class="yp-pay-address" data-yp-pay-address>
			<span class="yp-pay-address__title"><?php esc_html_e( 'Shipping address', 'yeffoprint' ); ?></span>
			<p class="yp-pay-address__body"><?php echo esc_html( $body ); ?></p>
			<div class="woocommerce-shipping-fields">
				<div class="yp-pay-address__grid">
					<?php $this->render_fields( 'shipping', $shipping ); ?>
				</div>
			</div>

			<p class="yp-pay-address__toggle">
				<label>
					<input type="checkbox" name="yp_bill_to_different" value="1" data-yp-bill-toggle<?php checked( $billing_differs ); ?> />
					<?php esc_html_e( 'Use a different billing address', 'yeffoprint' ); ?>
				</label>
			</p>

			<div class="woocommerce-billing-fields" data-yp-billing-fields<?php echo $billing_differs ? '' : ' hidden'; ?>>
				<span class="yp-pay-address__title"><?php esc_html_e( 'Billing address', 'yeffoprint' ); ?></span>
				<div class="yp-pay-address__grid">
					<?php $this->render_fields( 'billing', $billing ); ?>
				</div>
			</div>

			<?php $this->render_shipping_options( $order ); ?>

			<?php $this->render_express( $order ); ?>
		</div>
		<?php
	}

	/** WooCommerce's own address fields for $country (its labels, required flags and ordering), minus company and email — the order already has the customer's email. */
	private function render_fields( string $type, array $values ): void {
		$posted = isset( $_POST['woocommerce_pay'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- display only.
		$fields = WC()->countries->get_address_fields( $values['country'], $type . '_' );

		unset( $fields[ $type . '_company' ], $fields[ $type . '_email' ] );

		if ( ! isset( $fields[ $type . '_phone' ] ) ) {
			$fields[ $type . '_phone' ] = [
				'label'    => __( 'Phone', 'yeffoprint' ),
				'type'     => 'tel',
				'required' => false,
				'class'    => [ 'form-row-wide' ],
				'priority' => 100,
			];
		}
		// Required on the shipping address, since some carriers need it
		// (direct request). A separate billing address may leave it blank
		// and falls back to the shipping phone in capture().
		$fields[ $type . '_phone' ]['required'] = 'shipping' === $type;

		foreach ( $fields as $key => $field ) {
			$short = substr( $key, strlen( $type ) + 1 );
			$value = $values[ $short ] ?? '';
			if ( $posted && isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$value = wc_clean( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
			if ( 'state' === $short ) {
				$field['country'] = $posted && isset( $_POST[ $type . '_country' ] ) ? wc_clean( wp_unslash( $_POST[ $type . '_country' ] ) ) : $values['country']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
			woocommerce_form_field( $key, $field, $value );
		}
	}

	private function render_shipping_options( \WC_Order $order ): void {
		if ( ! self::customer_picks_shipping( $order ) ) {
			return;
		}

		$options = YeffoPrint_Shippo_Settings::get_manual_order_shipping_options();
		if ( ! $options ) {
			return;
		}

		$chosen = self::chosen_option_index( $order );
		if ( isset( $_POST['yp_pay_shipping'] ) && '' !== $_POST['yp_pay_shipping'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- display only.
			$chosen = absint( $_POST['yp_pay_shipping'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		?>
		<div class="yp-pay-shipping" data-yp-pay-shipping>
			<span class="yp-pay-address__title"><?php esc_html_e( 'Shipping method', 'yeffoprint' ); ?></span>
			<ul class="yp-pay-shipping__list">
				<?php foreach ( $options as $index => $option ) : ?>
					<li data-yp-region="<?php echo esc_attr( $option['region'] ); ?>">
						<label>
							<input type="radio" name="yp_pay_shipping" value="<?php echo esc_attr( (string) $index ); ?>"<?php checked( $chosen, $index ); ?> />
							<span class="yp-pay-shipping__label"><?php echo esc_html( $option['label'] ); ?></span>
							<span class="yp-pay-shipping__price"><?php echo wp_kses_post( wc_price( $option['amount'], [ 'currency' => $order->get_currency() ] ) ); ?></span>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="yp-pay-shipping__empty" data-yp-shipping-empty hidden><?php esc_html_e( "We don't have a shipping option for this country yet. Please contact us and we'll sort it out.", 'yeffoprint' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Direct request: "Can we add the ability for a customer to 'express'
	 * their order during this stage?" Same checkbox, fee and label as the
	 * regular checkout (class-express-order.php). Hidden while Express is
	 * off or paused for Away Mode, unless the order already carries the
	 * fee, so it can still be unticked.
	 */
	private function render_express( \WC_Order $order ): void {
		$on_order = YeffoPrint_Express_Order::is_express( $order );
		if ( ! $on_order && ! YeffoPrint_Express_Order::is_enabled() ) {
			return;
		}

		$checked = $on_order;
		if ( isset( $_POST['woocommerce_pay'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- display only, re-shows a failed submission.
			$checked = ! empty( $_POST['yp_pay_express'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		?>
		<div class="yp-pay-express" data-yp-pay-express>
			<?php
			echo YeffoPrint_Express_Order::option_html( 'name="yp_pay_express" value="1"' . ( $checked ? ' checked' : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- option_html() escapes every substitution.
			?>
		</div>
		<?php
	}

	/** Index into the saved shipping options of the line the customer already picked (a live pick on this page, or an earlier visit), or null. */
	private static function chosen_option_index( \WC_Order $order ): ?int {
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( ! $item->get_meta( self::CHOSEN_SHIPPING_ITEM_META ) ) {
				continue;
			}
			foreach ( YeffoPrint_Shippo_Settings::get_manual_order_shipping_options() as $index => $option ) {
				if ( $option['label'] === $item->get_method_title() ) {
					return $index;
				}
			}
		}

		return null;
	}

	/**
	 * Fires after WC_Form_Handler::pay_action() has already verified the
	 * order key + `woocommerce-pay` nonce, before any payment gateway
	 * runs — adding a wc_add_notice( …, 'error' ) here stops payment the
	 * same way an invalid payment method already does (pay_action() only
	 * calls process_payment() when wc_notice_count( 'error' ) is still 0).
	 * Everything is validated first; nothing is saved unless it all passes.
	 */
	public function capture( \WC_Order $order ): void {
		if ( ! self::is_editable( $order ) ) {
			return;
		}

		$errors = [];

		$shipping = $this->posted_address( 'shipping' );
		$errors   = array_merge( $errors, $this->validate_address( $shipping, __( 'Shipping', 'yeffoprint' ), true ) );

		$billing_differs = ! empty( $_POST['yp_bill_to_different'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- covered by pay_action()'s own woocommerce-pay nonce, already verified before this hook fires.
		if ( $billing_differs ) {
			$billing = $this->posted_address( 'billing' );
			$errors  = array_merge( $errors, $this->validate_address( $billing, __( 'Billing', 'yeffoprint' ), false ) );
		}

		$picked = null;
		if ( ! $errors ) {
			if ( self::customer_picks_shipping( $order ) ) {
				$index   = isset( $_POST['yp_pay_shipping'] ) && '' !== $_POST['yp_pay_shipping'] ? absint( $_POST['yp_pay_shipping'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$options = YeffoPrint_Shippo_Settings::get_manual_order_shipping_options();
				$picked  = null !== $index ? ( $options[ $index ] ?? null ) : null;

				if ( ! $picked ) {
					$errors[] = __( 'Please choose a shipping method.', 'yeffoprint' );
				} elseif ( ! YeffoPrint_Shippo_Settings::option_ships_to( $picked, $shipping['country'] ) ) {
					$errors[] = sprintf(
						/* translators: %s: shipping method name */
						__( '%s isn’t available for this address. Please choose another shipping method.', 'yeffoprint' ),
						$picked['label']
					);
				}
			} else {
				// Staff already chose the shipping. Changing the address to
				// another country can make that choice wrong (domestic
				// shipping to an international address, or the reverse).
				foreach ( $order->get_items( 'shipping' ) as $item ) {
					$option = YeffoPrint_Shippo_Settings::find_manual_order_shipping_option( $item->get_method_title() );
					if ( $option && ! YeffoPrint_Shippo_Settings::option_ships_to( $option, $shipping['country'] ) ) {
						$errors[] = sprintf(
							/* translators: %s: shipping method name */
							__( 'This order ships by %s, which isn’t available for this address. Please contact us to update your order before paying.', 'yeffoprint' ),
							$item->get_method_title()
						);
					}
				}
			}
		}

		if ( $errors ) {
			foreach ( $errors as $error ) {
				wc_add_notice( $error, 'error' );
			}
			return;
		}

		if ( ! $billing_differs ) {
			$billing = $shipping;
		} elseif ( '' === $billing['phone'] ) {
			$billing['phone'] = $shipping['phone'];
		}

		$before = self::order_address( $order, 'shipping' ) + [ 'billing' => self::order_address( $order, 'billing' ) ];

		self::apply_address( $order, 'shipping', $shipping );
		self::apply_address( $order, 'billing', $billing );

		if ( $picked ) {
			self::set_chosen_shipping( $order, $picked );
		}

		$after = self::order_address( $order, 'shipping' ) + [ 'billing' => self::order_address( $order, 'billing' ) ];

		if ( self::needs_address( $order ) || $order->get_meta( self::NEEDS_ADDRESS_META ) ) {
			$order->update_meta_data( self::NEEDS_ADDRESS_META, '' );
			$order->add_order_note( __( 'Customer provided their shipping address on the payment page.', 'yeffoprint' ) );
		} elseif ( $before !== $after ) {
			$order->add_order_note( __( 'Customer updated their address on the payment page.', 'yeffoprint' ) );
		}

		$express = ! empty( $_POST['yp_pay_express'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- covered by pay_action()'s own woocommerce-pay nonce.
		if ( $express !== YeffoPrint_Express_Order::is_express( $order ) ) {
			YeffoPrint_Express_Order::set_on_order( $order, $express );
			if ( YeffoPrint_Express_Order::is_express( $order ) ) {
				$order->add_order_note( __( 'Customer added Express production on the payment page.', 'yeffoprint' ) );
			}
		}

		if ( $picked ) {
			$order->add_order_note( sprintf(
				/* translators: %s: shipping method name */
				__( 'Customer chose %s on the payment page.', 'yeffoprint' ),
				$picked['label']
			) );
		}

		// Also fills a customer account's own saved address when it has
		// none — same "never overwrite an existing one" rule as
		// class-manual-order-creator.php's own maybe_save_address_to_profile().
		if ( $order->get_customer_id() ) {
			$this->maybe_save_to_profile( $order->get_customer_id(), $shipping );
		}

		$order->calculate_totals();
		$order->save();
	}

	/**
	 * Live totals as the customer picks a shipping method or ticks
	 * Express, before they
	 * submit — same order-key ownership check as class-card-surcharge.php's
	 * own AJAX handler, which pay-order-address.js re-runs right after this
	 * so the card fee is sized from the new total.
	 */
	public function ajax_select_shipping(): void {
		check_ajax_referer( 'yeffoprint_pay_order_shipping', 'nonce' );

		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$order     = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof \WC_Order || '' === $order_key || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Order not found.', 'yeffoprint-core' ) ], 404 );
		}

		if ( ! self::is_editable( $order ) ) {
			wp_send_json_error( [ 'message' => __( 'This order no longer needs payment.', 'yeffoprint-core' ) ], 400 );
		}

		// Shipping pick — only sent (and only honored) on a "customer picks" order.
		if ( isset( $_POST['option'] ) && self::customer_picks_shipping( $order ) ) {
			$country = isset( $_POST['country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ) ) ) : '';
			$index   = '' !== $_POST['option'] ? absint( $_POST['option'] ) : null;
			$options = YeffoPrint_Shippo_Settings::get_manual_order_shipping_options();
			$option  = null !== $index ? ( $options[ $index ] ?? null ) : null;

			if ( $option && YeffoPrint_Shippo_Settings::option_ships_to( $option, $country ) ) {
				self::set_chosen_shipping( $order, $option );
			} else {
				self::remove_chosen_shipping( $order );
			}
		}

		// Express checkbox — only sent when the page shows it.
		if ( isset( $_POST['express'] ) ) {
			YeffoPrint_Express_Order::set_on_order( $order, '1' === $_POST['express'] );
		}

		$order->calculate_totals();
		$order->save();

		wp_send_json_success( [
			'totalsHtml' => YeffoPrint_Card_Surcharge::order_totals_rows_html( $order ),
		] );
	}

	private static function set_chosen_shipping( \WC_Order $order, array $option ): void {
		self::remove_chosen_shipping( $order );

		$item = new \WC_Order_Item_Shipping();
		$item->set_method_title( $option['label'] );
		$item->set_method_id( 'yeffoprint_shippo' ); // Same method id class-manual-order-creator.php's own add_shipping_line() uses.
		$item->set_total( $option['amount'] );
		$item->add_meta_data( self::CHOSEN_SHIPPING_ITEM_META, '1', true );
		$order->add_item( $item );
	}

	/** Only ever runs on a "customer picks" order, where every shipping line is the customer's own pick — staff never added one. */
	private static function remove_chosen_shipping( \WC_Order $order ): void {
		foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
			$order->remove_item( $item_id );
		}
	}

	/** @return array<string,string> */
	private static function order_address( \WC_Order $order, string $type ): array {
		$address = [];
		foreach ( self::FIELDS as $field ) {
			$getter            = "get_{$type}_{$field}";
			$address[ $field ] = (string) $order->{$getter}();
		}

		return $address;
	}

	private static function apply_address( \WC_Order $order, string $type, array $address ): void {
		foreach ( self::FIELDS as $field ) {
			$setter = "set_{$type}_{$field}";
			$order->{$setter}( $address[ $field ] );
		}
	}

	private static function addresses_differ( array $a, array $b ): bool {
		foreach ( [ 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ] as $field ) {
			if ( strtolower( trim( $a[ $field ] ?? '' ) ) !== strtolower( trim( $b[ $field ] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/** @return array<string,string> */
	private function posted_address( string $type ): array {
		$address = [];
		foreach ( self::FIELDS as $field ) {
			$key               = $type . '_' . $field;
			$address[ $field ] = isset( $_POST[ $key ] ) ? wc_clean( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- covered by pay_action()'s own woocommerce-pay nonce.
		}
		$address['country'] = strtoupper( $address['country'] );

		return $address;
	}

	/**
	 * Required fields per WooCommerce's own address locale for the chosen
	 * country (e.g. State isn't required everywhere), a real state for
	 * countries with a state list, and a valid postcode. Normalizes
	 * $address in place (state code, postcode format).
	 *
	 * @return string[] error messages
	 */
	private function validate_address( array &$address, string $which, bool $is_shipping ): array {
		$countries = $is_shipping ? WC()->countries->get_shipping_countries() : WC()->countries->get_allowed_countries();
		if ( '' === $address['country'] || ! isset( $countries[ $address['country'] ] ) ) {
			/* translators: %s: Shipping or Billing */
			return [ sprintf( __( '%s address: please choose a country.', 'yeffoprint' ), $which ) ];
		}

		$errors = [];
		$fields = WC()->countries->get_address_fields( $address['country'], '' );

		if ( $is_shipping && '' === $address['phone'] ) {
			/* translators: %s: Shipping */
			$errors[] = sprintf( __( '%s address: Phone is required.', 'yeffoprint' ), $which );
		}

		foreach ( $fields as $key => $field ) {
			if ( ! array_key_exists( $key, $address ) || empty( $field['required'] ) || 'phone' === $key ) {
				continue;
			}
			if ( '' === $address[ $key ] ) {
				$errors[] = sprintf(
					/* translators: 1: Shipping or Billing, 2: field label */
					__( '%1$s address: %2$s is required.', 'yeffoprint' ),
					$which,
					wp_strip_all_tags( (string) ( $field['label'] ?? $key ) )
				);
			}
		}

		$states = WC()->countries->get_states( $address['country'] );
		if ( is_array( $states ) && $states && '' !== $address['state'] ) {
			$code = strtoupper( $address['state'] );
			if ( ! isset( $states[ $code ] ) ) {
				$match = array_search( strtolower( $address['state'] ), array_map( 'strtolower', array_map( 'html_entity_decode', $states ) ), true );
				$code  = false !== $match ? (string) $match : '';
			}
			if ( '' === $code ) {
				/* translators: %s: Shipping or Billing */
				$errors[] = sprintf( __( '%s address: please choose a valid state.', 'yeffoprint' ), $which );
			} else {
				$address['state'] = $code;
			}
		}

		if ( '' !== $address['postcode'] ) {
			$address['postcode'] = wc_format_postcode( $address['postcode'], $address['country'] );
			if ( ! \WC_Validation::is_postcode( $address['postcode'], $address['country'] ) ) {
				/* translators: %s: Shipping or Billing */
				$errors[] = sprintf( __( '%s address: please enter a valid ZIP / postal code.', 'yeffoprint' ), $which );
			}
		}

		return $errors;
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
