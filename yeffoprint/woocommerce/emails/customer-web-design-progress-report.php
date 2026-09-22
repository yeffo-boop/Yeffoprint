<?php
/**
 * "Progress report" email — theme override rendered via
 * class-email-web-design-progress-report.php. Same reused-components
 * approach as customer-web-design-staging.php: .yp-order-stepper-email
 * for the lifecycle row, .yp-email-button for the single CTA. No new
 * CSS needed.
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
	<?php echo $stepper_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built server-side by YeffoPrint_Web_Design_Project_Meta::get_email_progress_html(). ?>
<?php endif; ?>

<p><?php
/* translators: %s: customer's first name or "there" */
printf( esc_html__( 'Hi %s,', 'yeffoprint-core' ), esc_html( $name ) );
?></p>

<?php if ( '' !== $message ) : ?>
	<p><?php echo nl2br( esc_html( $message ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped just above, nl2br only adds <br> tags. ?></p>
<?php endif; ?>

<p style="text-align:center;">
	<a class="yp-email-button" href="<?php echo esc_url( $cta_url ); ?>"><?php esc_html_e( 'View your project dashboard →', 'yeffoprint-core' ); ?></a>
</p>

<p><?php esc_html_e( 'That page also has a running Site Activity feed — every change we ship to your site, updated automatically.', 'yeffoprint-core' ); ?></p>

<p>
<?php esc_html_e( 'Thanks,', 'yeffoprint-core' ); ?><br />
<?php echo esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>
</p>

<?php
/**
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
