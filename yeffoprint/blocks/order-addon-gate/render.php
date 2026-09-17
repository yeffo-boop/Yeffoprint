<?php
/**
 * The /add-to-order/ page's only dynamic content — direct request: let
 * a customer add a second order onto one that's still Processing/In
 * Production without paying for shipping twice. Reached two ways: a
 * link (order id + order_key) in an order email or the customer's own
 * My Account → Orders view (class-order-addon-account.php, plugin
 * side), or — with neither in the URL — the manual order-number +
 * email form below, matching the guest-safe pattern every other
 * order-lookup surface on this site already uses.
 *
 * All of the actual eligibility/ownership logic lives in
 * YeffoPrint_Order_Addon (yeffoprint-core) — this file only ever asks
 * that one class "is this allowed" and renders whichever state it
 * returns; same "theme consumes a plugin API" split as every other
 * dynamic block in this theme.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only lookup gated by the order's own key (or account ownership) below, not a nonce-protected mutation.
$order_id = absint( $_GET['order'] ?? 0 );
$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
// phpcs:enable

$order = ( $order_id && function_exists( 'wc_get_order' ) ) ? wc_get_order( $order_id ) : false;
$verified = $order instanceof \WC_Order
	&& function_exists( 'yeffoprint_core_order_addon_owns_order' )
	&& yeffoprint_core_order_addon_owns_order( $order, $key );

$eligibility = null;
if ( $verified ) {
	$eligibility = function_exists( 'yeffoprint_core_order_addon_eligibility' ) ? yeffoprint_core_order_addon_eligibility( $order ) : null;
	if ( $eligibility && $eligibility['eligible'] && function_exists( 'yeffoprint_core_order_addon_start_session' ) ) {
		yeffoprint_core_order_addon_start_session( $eligibility['root']->get_id() );
	}
}
?>
<div class="yp-addon-gate">
	<?php if ( $verified && $eligibility ) : ?>
		<?php $root = $eligibility['root']; ?>
		<div class="yp-addon-gate__card">
			<div class="yp-addon-gate__meta">
				<span class="yp-addon-gate__label"><?php esc_html_e( 'Adding on to', 'yeffoprint' ); ?></span>
				<span class="yp-addon-gate__order"><?php echo esc_html( sprintf( /* translators: %s: order number */ __( 'Order %s', 'yeffoprint-core' ), $root->get_order_number() ) ); ?></span>
			</div>
			<span class="yp-addon-gate__status-pill yp-addon-gate__status-pill--<?php echo esc_attr( $eligibility['eligible'] ? 'good' : 'warn' ); ?>">
				<?php echo esc_html( wc_get_order_status_name( $root->get_status() ) ); ?>
			</span>
		</div>

		<?php if ( $eligibility['eligible'] ) : ?>
			<div class="yp-addon-gate__banner yp-addon-gate__banner--good">
				<strong><?php esc_html_e( "This order hasn't shipped yet", 'yeffoprint' ); ?></strong>
				<p><?php echo esc_html( sprintf(
					/* translators: %s: the order number this add-on will ship with */
					__( "Add anything below and we'll combine it with %s — one box, one shipping charge, already paid.", 'yeffoprint' ),
					$root->get_order_number()
				) ); ?></p>
			</div>
			<p class="yp-addon-gate__cta">
				<a class="wp-block-button__link is-style-accent" href="<?php echo esc_url( home_url( '/shop-labels/' ) ); ?>"><?php esc_html_e( 'Browse designs', 'yeffoprint' ); ?> &rarr;</a>
			</p>
		<?php else : ?>
			<div class="yp-addon-gate__banner yp-addon-gate__banner--warn">
				<strong><?php esc_html_e( "This order can't take an add-on right now", 'yeffoprint' ); ?></strong>
				<p><?php echo esc_html( $eligibility['reason'] ); ?></p>
			</div>
		<?php endif; ?>

	<?php else : ?>
		<div class="yp-addon-gate__banner yp-addon-gate__banner--neutral">
			<strong><?php esc_html_e( 'Find your order', 'yeffoprint' ); ?></strong>
			<p><?php esc_html_e( "Enter your order number and the email you used at checkout — we'll check whether it can still take an add-on.", 'yeffoprint' ); ?></p>
		</div>
		<form class="yp-addon-gate__form" data-yp-addon-form>
			<div class="yp-field">
				<label for="yp-addon-order-ref"><?php esc_html_e( 'Order number', 'yeffoprint' ); ?></label>
				<input type="text" id="yp-addon-order-ref" name="order_ref" placeholder="YP-1042" required />
			</div>
			<div class="yp-field">
				<label for="yp-addon-email"><?php esc_html_e( 'Email used at checkout', 'yeffoprint' ); ?></label>
				<input type="email" id="yp-addon-email" name="email" required />
			</div>
			<p class="yp-form__error" data-yp-addon-error hidden></p>
			<button type="submit" class="wp-block-button__link is-style-accent"><?php esc_html_e( 'Check my order', 'yeffoprint' ); ?></button>
		</form>
	<?php endif; ?>
</div>
