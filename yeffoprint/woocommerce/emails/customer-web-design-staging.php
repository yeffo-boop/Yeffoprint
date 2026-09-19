<?php
/**
 * "Your staging site is ready" email — theme override rendered via
 * class-email-web-design-staging-notice.php. Reuses this theme's
 * existing branded components wholesale: .yp-payment-cta (the "here's
 * the one thing to click" card, email-styles.php) for the staging link,
 * .yp-email-fields (the same structured key/value box
 * class-order-item-meta.php's render_customization_email_fields() and
 * the payment-instructions callout already use) for the preview login,
 * and .yp-order-stepper-email for the lifecycle progress row — no new
 * CSS needed for any of it.
 *
 * One button only (to the staging-review page), not raw "Approve"/
 * "Request changes" links directly in the email — same reasoning
 * class-proof-approval-controller.php already established: an approval
 * has to be a real authenticated POST, not a bare GET a mail scanner or
 * link-preview bot could trigger just by prefetching the email.
 *
 * @see https://woocommerce.com/document/template-structure/
 */

defined( 'ABSPATH' ) || exit;

/**
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php if ( ! empty( $stepper_html ) ) : ?>
	<?php echo $stepper_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built server-side by YeffoPrint_Order_Status_Stepper::render_email_html(). ?>
<?php endif; ?>

<p><?php
/* translators: %s: customer's first name or "there" */
printf( esc_html__( 'Hi %s,', 'yeffoprint-core' ), esc_html( $name ) );
?></p>
<p><?php esc_html_e( "Great news — your staging site is live and ready for your review. Take a look, jot down anything you'd like changed, and let us know using the button below.", 'yeffoprint-core' ); ?></p>

<table class="yp-payment-cta" role="presentation" cellpadding="0" cellspacing="0" width="100%">
	<tr><td>
		<span class="yp-payment-cta-label"><?php esc_html_e( 'Staging site ready', 'yeffoprint-core' ); ?></span>
		<a class="yp-payment-cta-button" href="<?php echo esc_url( $staging_url ); ?>"><?php esc_html_e( 'View your staging site →', 'yeffoprint-core' ); ?></a>
	</td></tr>
</table>

<table class="yp-email-fields" role="presentation" cellpadding="0" cellspacing="0" width="100%">
	<tr><td class="yp-email-fields-box">
		<span class="yp-email-fields-heading"><?php esc_html_e( 'Your preview login', 'yeffoprint-core' ); ?></span>
		<table class="yp-email-fields-rows" role="presentation" cellpadding="0" cellspacing="0" width="100%">
			<tr>
				<td class="yp-email-field-label"><?php esc_html_e( 'Username', 'yeffoprint-core' ); ?></td>
				<td class="yp-email-field-value"><?php echo esc_html( $preview_user ); ?></td>
			</tr>
			<tr>
				<td class="yp-email-field-label"><?php esc_html_e( 'Password', 'yeffoprint-core' ); ?></td>
				<td class="yp-email-field-value"><?php echo esc_html( $preview_password ); ?></td>
			</tr>
		</table>
	</td></tr>
</table>

<h3><?php esc_html_e( 'How to request changes', 'yeffoprint-core' ); ?></h3>
<ol>
	<li><?php esc_html_e( 'Browse every page on the staging site above.', 'yeffoprint-core' ); ?></li>
	<li><?php esc_html_e( 'Make a list of anything to add, remove, or change.', 'yeffoprint-core' ); ?></li>
	<li><?php esc_html_e( "Click the button below and tell us what to update, or let us know you're ready to go live.", 'yeffoprint-core' ); ?></li>
</ol>

<?php if ( '' !== $note ) : ?>
	<p><em><?php echo esc_html( $note ); ?></em></p>
<?php endif; ?>

<p style="text-align:center;">
	<a class="yp-email-button" href="<?php echo esc_url( $cta_review_url ); ?>"><?php esc_html_e( 'Approve or Request Changes →', 'yeffoprint-core' ); ?></a>
</p>

<?php if ( '' !== $revisions_due ) : ?>
<p><?php
/* translators: %s: the revisions-due date */
printf( esc_html__( 'Revisions are due by %s. Need more time, or an extra round? Just reply to this email.', 'yeffoprint-core' ), esc_html( $revisions_due ) );
?></p>
<?php endif; ?>

<p>
<?php esc_html_e( 'Thanks,', 'yeffoprint-core' ); ?><br />
<?php echo esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>
</p>

<?php
/**
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
