<?php
/**
 * Overrides WC_Email_Customer_Processing_Order's own default subject/
 * heading for a Web Design Package order — direct report: "the order
 * payment and 'order received' emails... they get the same one as
 * label design customers and they don't make much sense." The stock
 * default ("Your {site} order has been received!" / "Thank you for
 * your order") is fine as far as it goes, but reads like every other
 * physical order on this store; customer-processing-order.php's own
 * body copy already branches on $is_web_design for the paragraph text,
 * this does the same for the subject/heading so the inbox preview
 * itself sets the right expectation too.
 *
 * Only overrides the two get_default_*() methods, not the trigger hook
 * or template resolution — same reasoning class-email-customer-
 * completed-order.php already documents: a subject/heading a staff
 * member deliberately types into Settings -> Emails still wins.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Email_Customer_Processing_Order extends \WC_Email_Customer_Processing_Order {

	public function get_default_subject() {
		if ( $this->is_web_design_order() ) {
			return __( 'Your web design order has been received — {site_title}', 'yeffoprint-core' );
		}
		return parent::get_default_subject();
	}

	public function get_default_heading() {
		if ( $this->is_web_design_order() ) {
			return __( 'Thanks for your order!', 'yeffoprint-core' );
		}
		return parent::get_default_heading();
	}

	private function is_web_design_order(): bool {
		return $this->object instanceof \WC_Order
			&& class_exists( 'YeffoPrint_Web_Design_Project_Meta' )
			&& YeffoPrint_Web_Design_Project_Meta::is_web_design_order( $this->object );
	}
}
