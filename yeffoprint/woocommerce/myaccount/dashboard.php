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

<div class="yp-account-hello">
	<?php
	// The profile picture set on Account details (yeffoprint-core class-profile-photo.php), else the first initial.
	$photo_url = class_exists( 'YeffoPrint_Profile_Photo' ) ? YeffoPrint_Profile_Photo::url( $current_user->ID ) : '';
	?>
	<a class="yp-account-avatar" href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-account' ) . '#profile-photo' ); ?>" aria-label="<?php echo esc_attr( $photo_url ? __( 'Change your profile picture', 'yeffoprint' ) : __( 'Add a profile picture', 'yeffoprint' ) ); ?>">
		<?php if ( $photo_url ) : ?>
			<img src="<?php echo esc_url( $photo_url ); ?>" alt="" width="56" height="56">
		<?php else : ?>
			<?php echo esc_html( strtoupper( mb_substr( trim( (string) $display_name ), 0, 1 ) ) ); ?>
		<?php endif; ?>
	</a>
	<p class="yp-account-welcome">
		<?php
		printf(
			/* translators: %s: customer's first name or display name */
			esc_html__( 'Welcome back, %s.', 'yeffoprint' ),
			esc_html( $display_name )
		);
		?>
	</p>
</div>

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
	<a class="yp-account-quicklink" href="<?php echo esc_url( home_url( '/tracker/' ) ); ?>">
		<strong><?php esc_html_e( 'Dose Tracker', 'yeffoprint' ); ?></strong>
		<span><?php esc_html_e( 'Log doses, vials and reminders', 'yeffoprint' ); ?></span>
	</a>
	<a class="yp-account-quicklink" href="<?php echo esc_url( home_url( '/shop-labels/' ) ); ?>">
		<strong><?php esc_html_e( 'Shop Labels', 'yeffoprint' ); ?></strong>
		<span><?php esc_html_e( 'Browse the full design gallery', 'yeffoprint' ); ?></span>
	</a>
</div>

<aside class="yp-continue-design" aria-label="<?php esc_attr_e( 'Continue a design', 'yeffoprint' ); ?>">
	<strong><?php esc_html_e( 'Still finishing a label?', 'yeffoprint' ); ?></strong>
	<p><?php esc_html_e( 'Saved designs and the gallery are ready whenever you are — pick up a draft or start a new one.', 'yeffoprint' ); ?></p>
	<p>
		<a class="wp-block-button__link is-style-accent" href="<?php echo esc_url( wc_get_account_endpoint_url( 'saved-designs' ) ); ?>"><?php esc_html_e( 'Saved Designs', 'yeffoprint' ); ?></a>
		<a class="wp-block-button__link" href="<?php echo esc_url( home_url( '/shop-labels/' ) ); ?>"><?php esc_html_e( 'Browse gallery', 'yeffoprint' ); ?></a>
	</p>
</aside>

<?php do_action( 'woocommerce_account_dashboard' ); ?>

<?php do_action( 'woocommerce_after_account_dashboard' ); ?>
