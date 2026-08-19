<?php
/**
 * Return Label Email
 *
 * This class handles the return label email notification sent to customers.
 *
 * @package WooCommerce_Shipping
 */

namespace Automattic\WCShipping\Emails;

use WC_Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WC_Return_Label_Email' ) ) {

	/**
	 * Return Label Email
	 *
	 * An email sent to customers when a return label is created for their order.
	 *
	 * @class       WC_Return_Label_Email
	 * @version     1.0.0
	 * @extends     WC_Email
	 */
	class WC_Return_Label_Email extends WC_Email {

		/**
		 * The return label data.
		 *
		 * @var array
		 */
		public $label_data;

		/**
		 * Attachments for this email.
		 *
		 * @var array
		 */
		public $attachments = array();

		/**
		 * Constructor
		 */
		public function __construct() {
			// Email slug/ID.
			$this->id = 'wcshipping_return_label';

			// Title shown in admin.
			$this->title = __( 'Return Label', 'woocommerce-shipping' );

			// Description shown in admin.
			$this->description = __( 'This email is sent to customers when a return label is created for their order.', 'woocommerce-shipping' );

			// Set to true as this is a customer email.
			$this->customer_email = true;

			// Template paths.
			$this->template_base  = WCSHIPPING_PLUGIN_DIR . '/templates/';
			$this->template_html  = 'emails/customer-return-label.php';
			$this->template_plain = 'emails/plain/customer-return-label.php';

			// Placeholders for email content.
			$this->placeholders = array(
				'{order_date}'   => '',
				'{order_number}' => '',
				'{site_title}'   => $this->get_blogname(),
			);

			// Trigger hook - when this action is called, the email will be sent.
			add_action( 'wcshipping_return_label_created', array( $this, 'trigger' ), 10, 3 );

			// Call parent constructor.
			parent::__construct();
		}

		/**
		 * Trigger the sending of this email
		 *
		 * @param int   $order_id The order ID.
		 * @param array $label_data The label data.
		 * @param array $attachments Optional attachments (like PDF label).
		 */
		public function trigger( $order_id, $label_data, $attachments = array() ) {
			$this->setup_locale();

			// Bail if no order ID.
			if ( ! $order_id ) {
				return;
			}

			// Get the order object.
			$this->object = wc_get_order( $order_id );

			if ( ! is_a( $this->object, 'WC_Order' ) ) {
				return;
			}

			// Store label data.
			$this->label_data = $label_data;

			// Set recipient.
			$this->recipient = $this->object->get_billing_email();

			// Replace placeholders.
			$this->placeholders['{order_date}']   = wc_format_datetime( $this->object->get_date_created() );
			$this->placeholders['{order_number}'] = $this->object->get_order_number();

			// Add any provided attachments.
			if ( ! empty( $attachments ) ) {
				$this->attachments = $attachments;
			}

			// Send email if enabled and recipient is set.
			if ( $this->is_enabled() && $this->get_recipient() ) {
				$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}

			$this->restore_locale();
		}

		/**
		 * Get content HTML
		 *
		 * @return string
		 */
		public function get_content_html() {
			return wc_get_template_html(
				$this->template_html,
				array(
					'order'              => $this->object,
					'label_data'         => $this->label_data,
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
					'sent_to_admin'      => false,
					'plain_text'         => false,
					'email'              => $this,
				),
				'',
				$this->template_base
			);
		}

		/**
		 * Get content plain
		 *
		 * @return string
		 */
		public function get_content_plain() {
			return wc_get_template_html(
				$this->template_plain,
				array(
					'order'              => $this->object,
					'label_data'         => $this->label_data,
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
					'sent_to_admin'      => false,
					'plain_text'         => true,
					'email'              => $this,
				),
				'',
				$this->template_base
			);
		}

		/**
		 * Initialize settings form fields
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'            => array(
					'title'   => __( 'Enable/Disable', 'woocommerce-shipping' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable this email notification', 'woocommerce-shipping' ),
					'default' => 'yes',
				),
				'subject'            => array(
					'title'       => __( 'Subject', 'woocommerce-shipping' ),
					'type'        => 'text',
					'desc_tip'    => true,
					/* translators: %s: list of available placeholders */
					'description' => sprintf( __( 'Available placeholders: %s', 'woocommerce-shipping' ), '<code>{site_title}, {order_number}, {order_date}</code>' ),
					'placeholder' => $this->get_default_subject(),
					'default'     => $this->get_default_subject(),
				),
				'heading'            => array(
					'title'       => __( 'Email heading', 'woocommerce-shipping' ),
					'type'        => 'text',
					'desc_tip'    => true,
					/* translators: %s: list of available placeholders */
					'description' => sprintf( __( 'Available placeholders: %s', 'woocommerce-shipping' ), '<code>{site_title}, {order_number}, {order_date}</code>' ),
					'placeholder' => $this->get_default_heading(),
					'default'     => $this->get_default_heading(),
				),
				'additional_content' => array(
					'title'       => __( 'Additional content', 'woocommerce-shipping' ),
					/* translators: %s: list of available placeholders */
					'description' => __( 'Text to appear below the main email content.', 'woocommerce-shipping' ) . ' ' . sprintf( __( 'Available placeholders: %s', 'woocommerce-shipping' ), '<code>{site_title}, {order_number}, {order_date}</code>' ),
					'css'         => 'width:400px; height: 75px;',
					'type'        => 'textarea',
					'default'     => $this->get_default_additional_content(),
					'desc_tip'    => true,
				),
				'email_type'         => array(
					'title'       => __( 'Email type', 'woocommerce-shipping' ),
					'type'        => 'select',
					'description' => __( 'Choose which format of email to send.', 'woocommerce-shipping' ),
					'default'     => 'html',
					'class'       => 'email_type wc-enhanced-select',
					'options'     => $this->get_email_type_options(),
					'desc_tip'    => true,
				),
			);
		}

		/**
		 * Get default email subject
		 *
		 * @return string
		 */
		public function get_default_subject() {
			/* translators: Placeholders: {site_title} - site name, {order_number} - order number */
			return __( '[{site_title}]: Return label for order #{order_number}', 'woocommerce-shipping' );
		}

		/**
		 * Get default email heading
		 *
		 * @return string
		 */
		public function get_default_heading() {
			return __( 'Your return label is ready', 'woocommerce-shipping' );
		}

		/**
		 * Get default additional content
		 *
		 * @return string
		 */
		public function get_default_additional_content() {
			return __( 'If you have any questions, please contact us.', 'woocommerce-shipping' );
		}

		/**
		 * Filter order items to show only products in the return label.
		 *
		 * @param array     $items Order items.
		 * @param \WC_Order $order Order object.
		 * @return array Filtered order items.
		 */
		public function filter_return_label_items( $items, $order ) {
			// Only filter if we have label data with product IDs.
			if ( empty( $this->label_data['product_ids'] ) ) {
				return $items;
			}

			// Get product IDs from the label data.
			$return_product_ids = $this->label_data['product_ids'];

			// Filter items to only include those in the return.
			$filtered_items = array();
			foreach ( $items as $item_id => $item ) {
				if ( $item instanceof \WC_Order_Item_Product ) {
					$product_id = $item->get_product_id();
					if ( in_array( $product_id, $return_product_ids, true ) ) {
						$filtered_items[ $item_id ] = $item;
					}
				}
			}

			return $filtered_items;
		}

		/**
		 * Get email attachments.
		 *
		 * @return array
		 */
		public function get_attachments() {
			return apply_filters( 'woocommerce_email_attachments', $this->attachments, $this->id, $this->object, $this );
		}
	}
}
