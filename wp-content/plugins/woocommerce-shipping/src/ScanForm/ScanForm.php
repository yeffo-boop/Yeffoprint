<?php
/**
 * ScanForm class.
 *
 * @package Automattic\WCShipping\ScanForm
 */

namespace Automattic\WCShipping\ScanForm;

use Automattic\WCShipping\FeatureFlags\FeatureFlags;
use Automattic\WCShipping\Connect\WC_Connect_Functions;
use Automattic\WCShipping\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ScanForm class.
 */
class ScanForm {
	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! FeatureFlags::is_scanform_enabled() ) {
			return;
		}

		add_action( 'woocommerce_order_list_table_extra_tablenav', array( $this, 'add_scan_form_button_on_hpos' ), 10, 2 );
		add_action( 'manage_posts_extra_tablenav', array( $this, 'add_scan_form_button_on_classic' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scan_form_scripts' ) );
	}

	/**
	 * Add ScanForm button for legacy order list page.
	 *
	 * @param string $which The location of the extra table nav: 'top' or 'bottom'.
	 */
	public function add_scan_form_button_on_classic( $which ) {
		$screen = get_current_screen();

		if ( ! $screen || 'edit-shop_order' !== $screen->id ) {
			return;
		}

		$this->add_scan_form_button( $which );
	}

	/**
	 * Add ScanForm button for HPOS order page.
	 *
	 * @param string $order_type Order type.
	 * @param string $which The location of the extra table nav: 'top' or 'bottom'.
	 */
	public function add_scan_form_button_on_hpos( $order_type, $which ) {
		$this->add_scan_form_button( $which );
	}

	/**
	 * Add ScanForm button.
	 *
	 * @param string $which The location of the extra table nav: 'top' or 'bottom'.
	 */
	public function add_scan_form_button( $which ) {
		if ( 'bottom' === $which || WC_Connect_Functions::user_can_manage_labels() === false ) {
			return;
		}

		?>
		<button type="button" class="button action" id="wc-shipping-scanform-trigger"><?php esc_html_e( 'Create USPS® SCAN Form', 'woocommerce-shipping' ); ?></button>
		<?php
	}

	/**
	 * Enqueue ScanForm scripts on order list pages.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_scan_form_scripts( $hook ) {
		// Only load on order list pages.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		if ( ! Utils::is_orders_screen() ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? wc_clean( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'edit' === $action ) {
			return;
		}

		do_action( 'wcshipping_enqueue_script', 'woocommerce-shipping-scanform' );
	}
}
