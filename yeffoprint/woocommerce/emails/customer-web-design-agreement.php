<?php
/**
 * "Please review & sign your agreement" email — theme override rendered
 * via class-email-web-design-agreement-notice.php, same header/footer/
 * styles (email-header.php, email-footer.php, email-styles.php) every
 * other branded notice in this theme already uses. Reuses the existing
 * .yp-proof-cta callout card (email-styles.php) rather than adding a
 * new one — same "eyebrow + title + button" shape already fits.
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
	<?php echo $stepper_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built server-side by YeffoPrint_Order_Status_Stepper::render_email_html(), every value inside it already escaped there. ?>
<?php endif; ?>

<p><?php
/* translators: %s: customer's first name or "there" */
printf( esc_html__( 'Hi %s,', 'yeffoprint-core' ), esc_html( $name ) );
?></p>
<p><?php
/* translators: %s: web design package name */
printf( esc_html__( 'Before we start building your staging site for %s, please take a minute to review and sign your project agreement — it covers your timeline, milestones, included add-ons, and how revisions work.', 'yeffoprint-core' ), esc_html( $package_name ) );
?></p>

<table class="yp-proof-cta" role="presentation" cellpadding="0" cellspacing="0" width="100%">
	<tr><td>
		<span class="yp-proof-cta-label"><?php esc_html_e( 'Agreement ready', 'yeffoprint-core' ); ?></span>
		<span class="yp-proof-cta-title"><?php esc_html_e( 'Web Design Service Agreement', 'yeffoprint-core' ); ?></span>
		<a class="yp-proof-cta-button" href="<?php echo esc_url( $cta_url ); ?>"><?php esc_html_e( 'Review & Sign →', 'yeffoprint-core' ); ?></a>
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
