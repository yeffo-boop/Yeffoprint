<?php
/**
 * Proof-ready / proof-reminder (customer) email — direct report, with a
 * screenshot: this used to send as a bare wp_mail() string with no
 * styling at all, unlike every other outgoing email. Shares the exact
 * same header/footer/styles (email-header.php, email-footer.php,
 * email-styles.php) every WooCommerce order email in this theme already
 * uses — see class-email-proof-notice.php for how a non-WC_Order email
 * gets routed through that same machinery.
 *
 * $proof_image_url is only ever set when the latest proof is an image
 * (wp_attachment_is_image() — the same check the public proof-approval
 * page itself already makes, proof-approval.js's renderProof()); a PDF
 * proof just skips straight to the title/button below, no broken/empty
 * <img>.
 *
 * @see https://woocommerce.com/document/template-structure/
 */

defined( 'ABSPATH' ) || exit;

/**
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php
/* translators: %s: customer's first name or "there" */
printf( esc_html__( 'Hi %s,', 'yeffoprint-core' ), esc_html( $name ) );
?></p>
<p><?php echo esc_html( $intro ); ?></p>

<table class="yp-proof-cta" role="presentation" cellpadding="0" cellspacing="0" width="100%">
	<tr><td>
		<span class="yp-proof-cta-label"><?php echo esc_html( $eyebrow ); ?></span>
		<?php if ( '' !== $proof_image_url ) : ?>
			<img class="yp-proof-cta-image" src="<?php echo esc_url( $proof_image_url ); ?>" alt="<?php esc_attr_e( 'Your custom label proof', 'yeffoprint-core' ); ?>" />
		<?php endif; ?>
		<span class="yp-proof-cta-title"><?php esc_html_e( 'Custom Label Proof', 'yeffoprint-core' ); ?></span>
		<a class="yp-proof-cta-button" href="<?php echo esc_url( $cta_url ); ?>"><?php esc_html_e( 'Review & Approve →', 'yeffoprint-core' ); ?></a>
		<span class="yp-proof-cta-sub"><?php esc_html_e( "No account needed — this link is yours alone, so don't share it.", 'yeffoprint-core' ); ?></span>
	</td></tr>
</table>

<p>
<?php esc_html_e( 'Thanks,', 'yeffoprint-core' ); ?><br />
<?php echo esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>
</p>

<?php
/**
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
