<?php
/**
 * New account (customer) email — theme override, copied unchanged from
 * woocommerce/templates/emails/customer-new-account.php.
 *
 * This file's actual content/copy is identical to WooCommerce's own
 * default — every visual change (colors, header/footer band, rounded
 * card) lives entirely in this same directory's email-header.php,
 * email-footer.php, and email-styles.php, which every email type
 * already shares via the woocommerce_email_header/_footer hooks below.
 * This copy exists so this specific email's wording is easy to find
 * and edit later without hunting through the WooCommerce plugin itself
 * — not because anything here needed to change today.
 *
 * @see https://woocommerce.com/document/template-structure/
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

/**
 * Fires to output the email header.
 *
 * @hooked WC_Emails::email_header()
 *
 * @since 3.7.0
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<?php /* translators: %s: Customer first name, or username if name is not available */ ?>
<p><?php printf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $user_display_name ) ); ?></p>
<?php if ( $email_improvements_enabled ) : ?>
	<?php /* translators: %s: Site title */ ?>
	<p><?php printf( esc_html__( 'Thanks for creating an account on %s. Here’s a copy of your user details.', 'woocommerce' ), esc_html( $blogname ) ); ?></p>
	<div class="hr hr-top"></div>
	<?php /* translators: %s: Username */ ?>
	<p><?php echo wp_kses( sprintf( __( 'Username: <b>%s</b>', 'woocommerce' ), esc_html( $user_login ) ), array( 'b' => array() ) ); ?></p>
	<?php if ( $password_generated && $set_password_url ) : ?>
		<?php // If the password has not been set by the user during the sign up process, send them a link to set a new password. ?>
		<p><a href="<?php echo esc_attr( $set_password_url ); ?>"><?php printf( esc_html__( 'Set your new password.', 'woocommerce' ) ); ?></a></p>
	<?php endif; ?>
	<div class="hr hr-bottom"></div>
	<p><?php echo esc_html__( 'You can access your account area to view orders, change your password, and more via the link below:', 'woocommerce' ); ?></p>
	<p><a href="<?php echo esc_attr( wc_get_page_permalink( 'myaccount' ) ); ?>"><?php printf( esc_html__( 'My account', 'woocommerce' ) ); ?></a></p>
<?php else : ?>
	<?php /* translators: %1$s: Site title, %2$s: Username, %3$s: My account link */ ?>
	<p><?php printf( esc_html__( 'Thanks for creating an account on %1$s. Your username is %2$s. You can access your account area to view orders, change your password, and more at: %3$s', 'woocommerce' ), esc_html( $blogname ), '<strong>' . esc_html( $user_login ) . '</strong>', make_clickable( esc_url( wc_get_page_permalink( 'myaccount' ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
	<?php if ( $password_generated && $set_password_url ) : ?>
		<?php // If the password has not been set by the user during the sign up process, send them a link to set a new password. ?>
		<p><a href="<?php echo esc_attr( $set_password_url ); ?>"><?php printf( esc_html__( 'Click here to set your new password.', 'woocommerce' ) ); ?></a></p>
	<?php endif; ?>
<?php endif; ?>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content email-additional-content-aligned">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

/**
 * Fires to output the email footer.
 *
 * @hooked WC_Emails::email_footer()
 *
 * @since 3.7.0
 */
do_action( 'woocommerce_email_footer', $email );
