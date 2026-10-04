<?php
/**
 * The store policy pages (templates/legal-page.html): Privacy Policy,
 * Terms of Service, Refund & Returns and Shipping. Direct request: "I
 * need a privacy notice drafted and available on the site" plus
 * "whatever other pages payment processors usually look for". Jeff
 * approved the wording in mockups/privacy-notice/ before this was built.
 *
 * The text lives in docs/<page slug>.html so every change to a legal
 * statement goes through the repo, not the page editor. Two values are
 * filled in at render time so they stay right when the settings change:
 * {{email}} is the Contact form's recipient (admin Settings), and
 * {{address}} is the store address in WooCommerce > Settings > General
 * (Jeff's PO Box). The pages themselves are created by yeffoprint-core's
 * class-legal-pages.php, which also owns the slug list.
 */

defined( 'ABSPATH' ) || exit;

$docs = [
	'privacy-policy'  => [ __( 'Privacy Policy', 'yeffoprint' ), '2026-10-04' ],
	'terms'           => [ __( 'Terms of Service', 'yeffoprint' ), '2026-10-04' ],
	'refund-policy'   => [ __( 'Refund & Returns Policy', 'yeffoprint' ), '2026-10-04' ],
	'shipping-policy' => [ __( 'Shipping Policy', 'yeffoprint' ), '2026-10-04' ],
];

$slug = (string) get_post_field( 'post_name', get_queried_object_id() );
if ( ! isset( $docs[ $slug ] ) ) {
	return;
}
$file = __DIR__ . '/docs/' . $slug . '.html';
if ( ! is_readable( $file ) ) {
	return;
}

$email = class_exists( 'YeffoPrint_Admin_Menu' )
	? (string) get_option( YeffoPrint_Admin_Menu::CONTACT_RECIPIENT_EMAIL_OPTION, YeffoPrint_Admin_Menu::CONTACT_RECIPIENT_EMAIL_DEFAULT )
	: (string) get_option( 'admin_email' );
if ( ! is_email( $email ) ) {
	$email = (string) get_option( 'admin_email' );
}

$address = '';
if ( function_exists( 'WC' ) && WC()->countries ) {
	$countries = WC()->countries;
	$address   = implode( ', ', array_filter( [
		$countries->get_base_address(),
		$countries->get_base_address_2(),
		trim( $countries->get_base_city() . ', ' . $countries->get_base_state() . ' ' . $countries->get_base_postcode(), ', ' ),
	] ) );
}
if ( '' === $address ) {
	$address = __( 'the address on our contact page', 'yeffoprint' );
}

$html = str_replace(
	[ '{{email}}', '{{address}}' ],
	[ esc_html( $email ), esc_html( $address ) ],
	(string) file_get_contents( $file ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
);

[ $title, $updated ] = $docs[ $slug ];
?>
<div class="yp-legal">
	<header class="yp-legal__head">
		<p class="yp-legal__eyebrow"><?php esc_html_e( 'Legal', 'yeffoprint' ); ?></p>
		<h1><?php echo esc_html( $title ); ?></h1>
		<p class="yp-legal__date">
			<?php
			/* translators: %s: date the policy was last changed */
			printf( esc_html__( 'Last updated %s', 'yeffoprint' ), esc_html( wp_date( 'F j, Y', strtotime( $updated . ' 12:00:00' ) ) ) );
			?>
		</p>
	</header>
	<div class="yp-legal__grid">
		<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- repo-owned static HTML; the two filled-in values are escaped above. ?>
	</div>
</div>
