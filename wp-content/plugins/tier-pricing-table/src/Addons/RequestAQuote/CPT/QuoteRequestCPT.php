<?php namespace TierPricingTable\Addons\RequestAQuote\CPT;

use Automattic\WooCommerce\Admin\PageController;

class QuoteRequestCPT {

	public const POST_TYPE = 'tpt-quote-request';

	public function __construct() {
		add_action( 'init', array( $this, 'registerPostType' ) );
		add_action( 'admin_menu', array( $this, 'addAdminMenu' ), 99 );
		add_action( 'before_delete_post', array( $this, 'deleteAssociatedFiles' ) );
	}

	public function deleteAssociatedFiles( $postId ) {
		if ( get_post_type( $postId ) !== self::POST_TYPE ) {
			return;
		}

		$quote = \TierPricingTable\Addons\RequestAQuote\Models\QuoteRequest::get( $postId );
		if ( ! $quote ) {
			return;
		}

		$customFields = $quote->getCustomFields();
		if ( is_array( $customFields ) ) {
			foreach ( $customFields as $field ) {
				if ( ! empty( $field['file_path'] ) && file_exists( $field['file_path'] ) ) {
					unlink( $field['file_path'] );
				}
			}
		}
	}

	public function registerPostType() {
		$labels = array(
			'name'               => _x( 'Quote Requests', 'post type general name', 'tier-pricing-table' ),
			'singular_name'      => _x( 'Quote Request', 'post type singular name', 'tier-pricing-table' ),
			'menu_name'          => _x( 'Quote Requests', 'admin menu', 'tier-pricing-table' ),
			'name_admin_bar'     => _x( 'Quote Request', 'add new on admin bar', 'tier-pricing-table' ),
			'add_new'            => _x( 'Add New', 'quote request', 'tier-pricing-table' ),
			'add_new_item'       => __( 'Add New Quote Request', 'tier-pricing-table' ),
			'new_item'           => __( 'New Quote Request', 'tier-pricing-table' ),
			'edit_item'          => __( 'Edit Quote Request', 'tier-pricing-table' ),
			'view_item'          => __( 'View Quote Request', 'tier-pricing-table' ),
			'all_items'          => __( 'All Quote Requests', 'tier-pricing-table' ),
			'search_items'       => __( 'Search Quote Requests', 'tier-pricing-table' ),
			'not_found'          => __( 'No quote requests found.', 'tier-pricing-table' ),
			'not_found_in_trash' => __( 'No quote requests found in Trash.', 'tier-pricing-table' ),
		);

		$args = array(
			'labels'             => $labels,
			'description'        => __( 'Quote Requests.', 'tier-pricing-table' ),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => false, // We will add it manually as a WooCommerce submenu
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'tpt-quote-request' ),
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => null,
			'supports'           => array( 'title', 'custom-fields' ),
		);

		register_post_type( self::POST_TYPE, $args );
		
		$this->registerPostStatuses();

		// Add this CPT to WC Navigation
		if ( class_exists( PageController::class ) ) {
			PageController::get_instance()->connect_page(
				array(
					'id'        => 'tpt-quote-request',
					'title'     => __( 'Quote Requests', 'tier-pricing-table' ),
					'screen_id' => 'edit-tpt-quote-request',
					'path'      => 'edit.php?post_type=' . self::POST_TYPE,
				)
			);
		}
	}
	
	private function registerPostStatuses() {
		register_post_status( 'unread', array(
			'label'                     => _x( 'Unread', 'quote request status', 'tier-pricing-table' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			// translators: %s: number of requests.
			'label_count'               => _n_noop( 'Unread <span class="count">(%s)</span>', 'Unread <span class="count">(%s)</span>', 'tier-pricing-table' ),
		) );

		register_post_status( 'read', array(
			'label'                     => _x( 'Read', 'quote request status', 'tier-pricing-table' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			// translators: %s: number of requests.
			'label_count'               => _n_noop( 'Read <span class="count">(%s)</span>', 'Read <span class="count">(%s)</span>', 'tier-pricing-table' ),
		) );
		
		register_post_status( 'converted', array(
			'label'                     => _x( 'Converted', 'quote request status', 'tier-pricing-table' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			// translators: %s: number of requests.
			'label_count'               => _n_noop( 'Converted <span class="count">(%s)</span>', 'Converted <span class="count">(%s)</span>', 'tier-pricing-table' ),
		) );

		register_post_status( 'rejected', array(
			'label'                     => _x( 'Rejected', 'quote request status', 'tier-pricing-table' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			// translators: %s: number of requests.
			'label_count'               => _n_noop( 'Rejected <span class="count">(%s)</span>', 'Rejected <span class="count">(%s)</span>', 'tier-pricing-table' ),
		) );
	}

	public function addAdminMenu() {
		$unreadCount = wp_count_posts( self::POST_TYPE )->unread ?? 0;

		$menuTitle = __( 'Quote Requests', 'tier-pricing-table' );
		
		if ( $unreadCount > 0 ) {
			// translators: %s: number of unread requests.
			$menuTitle .= ' <span class="update-plugins count-' . esc_attr( $unreadCount ) . '"><span class="plugin-count" aria-hidden="true">' . number_format_i18n( $unreadCount ) . '</span><span class="screen-reader-text">' . sprintf( _n( '%s unread request', '%s unread requests', $unreadCount, 'tier-pricing-table' ), number_format_i18n( $unreadCount ) ) . '</span></span>';
		}

		add_submenu_page(
			'woocommerce',
			__( 'Quote Requests', 'tier-pricing-table' ),
			$menuTitle,
			'manage_woocommerce',
			'edit.php?post_type=' . self::POST_TYPE
		);
	}
}
