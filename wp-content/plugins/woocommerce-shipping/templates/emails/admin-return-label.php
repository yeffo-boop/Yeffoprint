<?php
/**
 * Admin return label notification email
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/admin-return-label.php.
 *
 * @package WooCommerce_Shipping/Templates/Emails
 * @version 1.0.0
 *
 * @phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php esc_html_e( 'A return label has been created and sent to the customer.', 'woocommerce-shipping' ); ?></p>

<?php if ( ! empty( $label_data ) ) : ?>
<h2><?php esc_html_e( 'Return Label Details', 'woocommerce-shipping' ); ?></h2>

<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;" border="1">
	<tbody>
		<?php if ( ! empty( $label_data['tracking'] ) ) : ?>
		<tr>
			<th class="td" scope="row" style="text-align:left;"><?php esc_html_e( 'Tracking Number:', 'woocommerce-shipping' ); ?></th>
			<td class="td"><?php echo esc_html( $label_data['tracking'] ); ?></td>
		</tr>
		<?php endif; ?>

		<?php if ( ! empty( $label_data['carrier_id'] ) ) : ?>
		<tr>
			<th class="td" scope="row" style="text-align:left;"><?php esc_html_e( 'Carrier:', 'woocommerce-shipping' ); ?></th>
			<td class="td"><?php echo esc_html( strtoupper( $label_data['carrier_id'] ) ); ?></td>
		</tr>
		<?php endif; ?>

		<?php if ( ! empty( $label_data['service_name'] ) ) : ?>
		<tr>
			<th class="td" scope="row" style="text-align:left;"><?php esc_html_e( 'Service:', 'woocommerce-shipping' ); ?></th>
			<td class="td"><?php echo esc_html( $label_data['service_name'] ); ?></td>
		</tr>
		<?php endif; ?>

		<?php if ( ! empty( $label_data['refundable_amount'] ) ) : ?>
		<tr>
			<th class="td" scope="row" style="text-align:left;"><?php esc_html_e( 'Cost:', 'woocommerce-shipping' ); ?></th>
			<td class="td"><?php echo wp_kses_post( wc_price( $label_data['refundable_amount'] ) ); ?></td>
		</tr>
		<?php endif; ?>

		<?php if ( ! empty( $label_data['created'] ) ) : ?>
		<tr>
			<th class="td" scope="row" style="text-align:left;"><?php esc_html_e( 'Created:', 'woocommerce-shipping' ); ?></th>
			<td class="td"><?php echo esc_html( date_i18n( wc_date_format() . ' ' . wc_time_format(), $label_data['created'] ) ); ?></td>
		</tr>
		<?php endif; ?>
	</tbody>
</table>
<?php endif; ?>

<?php if ( ! empty( $label_data['tracking'] ) ) : ?>
<p>
	<?php
	$tracking_url = '';
	if ( ! empty( $label_data['carrier_id'] ) ) {
		switch ( $label_data['carrier_id'] ) {
			case 'fedex':
				$tracking_url = 'https://www.fedex.com/apps/fedextrack/?action=track&tracknumbers=' . $label_data['tracking'];
				break;
			case 'usps':
				$tracking_url = 'https://tools.usps.com/go/TrackConfirmAction.action?tLabels=' . $label_data['tracking'];
				break;
			case 'ups':
				$tracking_url = 'https://www.ups.com/track?loc=en_US&tracknum=' . $label_data['tracking'];
				break;
			case 'dhlexpress':
				$tracking_url = 'https://www.dhl.com/en/express/tracking.html?AWB=' . $label_data['tracking'];
				break;
		}
	}

	if ( $tracking_url ) :
		printf(
			wp_kses(
				/* translators: %s: tracking URL */
				__( 'Track return shipment: <a href="%1$s">%2$s</a>', 'woocommerce-shipping' ),
				array( 'a' => array( 'href' => array() ) )
			),
			esc_url( $tracking_url ),
			esc_html( $tracking_url )
		);
	endif;
	?>
</p>
<?php endif; ?>

<h2><?php esc_html_e( 'Returned Items', 'woocommerce-shipping' ); ?></h2>

<?php
// Filter order items to show only returned items.
add_filter( 'woocommerce_order_get_items', array( $email, 'filter_return_label_items' ), 10, 2 );

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 2.5.0
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

// Remove the filter after use.
remove_filter( 'woocommerce_order_get_items', array( $email, 'filter_return_label_items' ), 10 );

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
