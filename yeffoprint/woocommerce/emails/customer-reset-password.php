<?php
/**
 * Reset password (customer) email — theme override, copied unchanged from
 * woocommerce/templates/emails/customer-reset-password.php.
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

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

?>

<?php do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<?php /* translators: %s: Customer first name, or username if name is not available */ ?>
<p><?php printf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $user_display_name ) ); ?></p>
<?php /* translators: %s: Store name */ ?>
<p><?php printf( esc_html__( 'Someone has requested a new password for the following account on %s:', 'woocommerce' ), esc_html( $blogname ) ); ?></p>
<?php if ( $email_improvements_enabled ) : ?>
	<div class="hr hr-top"></div>
	<?php /* translators: %s: Username */ ?>
	<p><?php echo wp_kses( sprintf( __( 'Username: <b>%s</b>', 'woocommerce' ), esc_html( $user_login ) ), array( 'b' => array() ) ); ?></p>
	<div class="hr hr-bottom"></div>
	<p><?php esc_html_e( 'If you didn’t make this request, just ignore this email. If you’d like to proceed, reset your password via the link below:', 'woocommerce' ); ?></p>
<?php else : ?>
	<?php /* translators: %s: Customer username */ ?>
	<p><?php printf( esc_html__( 'Username: %s', 'woocommerce' ), esc_html( $user_login ) ); ?></p>
	<p><?php esc_html_e( 'If you didn\'t make this request, just ignore this email. If you\'d like to proceed:', 'woocommerce' ); ?></p>
<?php endif; ?>
<p>
	<a class="link" href="<?php echo esc_url( add_query_arg( array( 'key' => $reset_key, 'id' => $user_id, 'login' => rawurlencode( $user_login ) ), wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) ) ) ); ?>"><?php // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound ?>
		<?php
		if ( $email_improvements_enabled ) {
			esc_html_e( 'Reset your password', 'woocommerce' );
		} else {
			esc_html_e( 'Click here to reset your password', 'woocommerce' );
		}
		?>
	</a>
</p>
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

do_action( 'woocommerce_email_footer', $email );
