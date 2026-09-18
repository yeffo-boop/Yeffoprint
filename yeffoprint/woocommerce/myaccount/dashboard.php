<?php
/**
 * My Account dashboard — theme override of WooCommerce's default
 * (plain "Hello X (not X? Log out)" text) with a branded welcome and
 * quick links to the sections PROJECT_SPEC §16 asks for. WooCommerce
 * locates this automatically at woocommerce/myaccount/dashboard.php
 * in the active theme in place of its own template — standard
 * override mechanism, no code changes needed on the WooCommerce side.
 *
 * The woocommerce_(before|after)_account_dashboard and
 * woocommerce_account_dashboard action hooks are kept so any other
 * installed extension that adds dashboard content still shows up.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_account_dashboard' );

$current_user = wp_get_current_user();
$display_name = $current_user->first_name ?: $current_user->display_name;
?>

<p class="yp-account-welcome">
	<?php
	printf(
		/* translators: %s: customer's first name or display name */
		esc_html__( 'Welcome back, %s.', 'yeffoprint' ),
		esc_html( $display_name )
	);
	?>
</p>

<div class="yp-account-quicklinks">
	<a class="yp-account-quicklink" href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>">
		<strong><?php esc_html_e( 'Orders', 'yeffoprint' ); ?></strong>
		<span><?php esc_html_e( 'Track and review past orders', 'yeffoprint' ); ?></span>
	</a>
	<a class="yp-account-quicklink" href="<?php echo esc_url( wc_get_account_endpoint_url( 'proofs' ) ); ?>">
		<strong><?php esc_html_e( 'Proofs', 'yeffoprint' ); ?></strong>
		<span><?php esc_html_e( 'Custom design requests and proofs', 'yeffoprint' ); ?></span>
	</a>
	<a class="yp-account-quicklink" href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-address' ) ); ?>">
		<strong><?php esc_html_e( 'Addresses', 'yeffoprint' ); ?></strong>
		<span><?php esc_html_e( 'Shipping and billing details', 'yeffoprint' ); ?></span>
	</a>
	<a class="yp-account-quicklink" href="<?php echo esc_url( wc_get_account_endpoint_url( 'telegram' ) ); ?>">
		<strong><?php esc_html_e( 'Connect Telegram', 'yeffoprint' ); ?></strong>
		<span><?php esc_html_e( 'Order updates the moment they happen', 'yeffoprint' ); ?></span>
	</a>
	<a class="yp-account-quicklink" href="<?php echo esc_url( home_url( '/shop-labels/' ) ); ?>">
		<strong><?php esc_html_e( 'Shop Labels', 'yeffoprint' ); ?></strong>
		<span><?php esc_html_e( 'Browse the full design gallery', 'yeffoprint' ); ?></span>
	</a>
</div>

<aside class="yp-abandoned-design-mock" aria-label="<?php esc_attr_e( 'Abandoned design reminder mock', 'yeffoprint' ); ?>">
	<p class="yp-mock-banner"><?php esc_html_e( 'Mock · Abandoned-design nudge', 'yeffoprint' ); ?></p>
	<strong><?php esc_html_e( 'Still want to finish that label?', 'yeffoprint' ); ?></strong>
	<p><?php esc_html_e( 'Prototype for an email / Telegram reminder when a guest stashes a design or leaves a batch in the cart. The real send would deep-link back into the configurator.', 'yeffoprint' ); ?></p>
	<div class="yp-abandoned-design-mock__preview">
		<p class="yp-abandoned-design-mock__email-label"><?php esc_html_e( 'Email preview', 'yeffoprint' ); ?></p>
		<div class="yp-abandoned-design-mock__email">
			<p><?php esc_html_e( 'Hi — you left a label design on YeffoDesign. Pick it back up anytime; your size, material, and text are waiting.', 'yeffoprint' ); ?></p>
			<p><a class="wp-block-button__link is-style-accent" href="<?php echo esc_url( home_url( '/shop-labels/' ) ); ?>"><?php esc_html_e( 'Resume design', 'yeffoprint' ); ?></a></p>
		</div>
	</div>
</aside>

<?php do_action( 'woocommerce_account_dashboard' ); ?>

<?php do_action( 'woocommerce_after_account_dashboard' ); ?>
