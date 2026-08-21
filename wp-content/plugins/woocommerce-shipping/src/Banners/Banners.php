<?php

namespace Automattic\WCShipping\Banners;

use Automattic\WCShipping\Connect\WC_Connect_Service_Schemas_Store;
use Automattic\WCShipping\Connect\WC_Connect_Logger;
use Automattic\WCShipping\Tracks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Banners {

	/**
	 * @var WC_Connect_Service_Schemas_Store
	 */
	protected $service_schemas_store;

	/**
	 * @var WC_Connect_Logger
	 */
	protected $logger;

	/**
	 * User meta key for storing IDs of the dismissed feature banners
	 */
	const DISMISSED_BANNERS_META_KEY = 'wcshipping_feature_banner_notices';

	public function __construct( WC_Connect_Service_Schemas_Store $service_schemas_store, WC_Connect_Logger $logger ) {
		$this->service_schemas_store = $service_schemas_store;
		$this->logger                = $logger;

		add_action( 'admin_init', array( $this, 'init' ) );
	}

	/**
	 * Initialize feature banners
	 */
	public function init() {
		// hook to attach the render function to admin notices
		add_action( 'admin_notices', array( $this, 'render_feature_banners' ) );

		// actions to attach the ajax calls from the renered UI to php function
		add_action( 'wp_ajax_wcshipping_dismiss_feature_banner', array( $this, 'dismiss_feature_banner' ) );
		add_action( 'wp_ajax_wcshipping_track_feature_banner_click', array( $this, 'track_feature_banner_click' ) );
	}

	/**
	 * Check if feature banners should be displayed on the current screen
	 * More flexible than NUX targeting - can be extended for feature banner needs
	 *
	 * @return bool
	 */
	protected function should_show_feature_banners() {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		if ( defined( 'NEXT_ADMIN_PLUGIN_DIR' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		return $this->should_display_feature_banner_on_screen( $screen );
	}

	/**
	 * Determine if feature banners should be shown on a specific screen
	 * Can be extended to include additional screens beyond NUX targeting
	 *
	 * @param \WP_Screen $screen The current screen object
	 * @return bool
	 */
	protected function should_display_feature_banner_on_screen( $screen ) {
		// Define target screens for feature banners
		$target_screens = array(
			// Products management
			'products_list'      => array(
				'post_type' => 'product',
				'base'      => 'edit',
			),
			// Orders management
			'orders_list'        => array(
				'post_type' => 'shop_order',
				'base'      => 'edit',
			),
			'edit_order'         => array(
				'post_type' => 'shop_order',
				'base'      => 'post',
			),
			'orders_hpos'        => array(
				'base' => 'woocommerce_page_wc-orders',
			),
			// WooCommerce settings
			'wc_settings'        => array(
				'base' => 'woocommerce_page_wc-settings',
			),
			// WooCommerce extensions
			'wc_addons_featured' => array(
				'base'    => 'woocommerce_page_wc-addons',
				'section' => 'featured',
			),
			'wc_addons_shipping' => array(
				'base'    => 'woocommerce_page_wc-addons',
				'section' => 'shipping_methods',
			),
			// WordPress plugins page
			'plugins'            => array(
				'base' => 'plugins',
			),
		);

		// Allow filtering of target screens
		$target_screens = apply_filters( 'wcshipping_feature_banner_target_screens', $target_screens );

		// Check if current screen matches any target screen
		foreach ( $target_screens as $target ) {
			if ( $this->screen_matches_target( $screen, $target ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a screen matches a target configuration
	 *
	 * @param \WP_Screen $screen The screen to check
	 * @param array      $target Target configuration
	 * @return bool
	 */
	protected function screen_matches_target( $screen, $target ) {
		// Check base screen
		if ( isset( $target['base'] ) && $screen->base !== $target['base'] ) {
			return false;
		}

		// Check post type
		if ( isset( $target['post_type'] ) && $screen->post_type !== $target['post_type'] ) {
			return false;
		}

		// Check section parameter
		if ( isset( $target['section'] ) ) {
			$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
			if ( $section !== $target['section'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get stored features/banners
	 *
	 * @return object|null The features object or null if not set
	 */
	protected function get_features() {
		$services = $this->service_schemas_store->get_service_schemas();
		if ( isset( $services, $services->features ) && is_object( $services->features ) ) {
			return $services->features;
		}
		return null;
	}

	/**
	 * Get all active (non-dismissed) feature banners for current user
	 *
	 * @return array
	 */
	public function get_active_banners() {
		$features = $this->get_features();
		if ( ! $features || ! is_object( $features ) ) {
			return array();
		}

		$dismissed_banners = $this->get_dismissed_banners();
		$active_banners    = array();

		foreach ( $features as $feature_id => $feature_data ) {
			// Skip banners that are not of type 'announcement'
			if ( ! isset( $feature_data->type ) || $feature_data->type !== 'announcement' ) {
				continue;
			}

			// Skip dismissed banners
			if ( in_array( $feature_id, $dismissed_banners, true ) ) {
				continue;
			}

			$active_banners[ $feature_id ] = $feature_data;
		}

		return $active_banners;
	}

	/**
	 * Get IDs of the banners that the user has already dismissed
	 *
	 * @return array
	 */
	protected function get_dismissed_banners() {
		$user_id           = get_current_user_id();
		$dismissed_banners = get_user_meta( $user_id, self::DISMISSED_BANNERS_META_KEY, true );

		if ( ! is_array( $dismissed_banners ) ) {
			return array();
		}

		return $dismissed_banners;
	}

	/**
	 * Check if a specific banner is dismissed
	 *
	 * @param string $banner_id
	 * @return bool
	 */
	public function is_banner_dismissed( $banner_id ) {
		$dismissed_banners = $this->get_dismissed_banners();
		return in_array( $banner_id, $dismissed_banners, true );
	}

	/**
	 * Once the user clicks the dismiss button, this function stores the ID
	 * to the user meta so it is not shown again
	 *
	 * @param string $banner_id
	 * @return bool
	 */
	public function on_banner_dismissed( $banner_id ) {
		$user_id           = get_current_user_id();
		$dismissed_banners = $this->get_dismissed_banners();

		if ( ! in_array( $banner_id, $dismissed_banners, true ) ) {
			$dismissed_banners[] = $banner_id;
			return update_user_meta( $user_id, self::DISMISSED_BANNERS_META_KEY, $dismissed_banners );
		}

		return true;
	}

	/**
	 * AJAX handler for dismissing feature banners
	 * called by the rendered banner
	 */
	public function dismiss_feature_banner() {
		// Verify nonce
		if ( ! $this->verify_dismiss_nonce() ) {
			wp_send_json_error( array( 'message' => 'Nonce verification failed' ) );
			return; // Ensure we exit after sending error
		}

		$banner_id = sanitize_text_field( wp_unslash( $_POST['banner_id'] ?? '' ) );

		if ( empty( $banner_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid banner ID' ) );
			return; // Ensure we exit after sending error
		}

		$result = $this->on_banner_dismissed( $banner_id );

		if ( $result ) {
			Tracks::feature_banner_dismissed( $banner_id );
			wp_send_json_success( array( 'message' => 'Banner dismissed successfully' ) );
		} else {
			wp_send_json_error( array( 'message' => 'Failed to dismiss banner' ) );
		}
	}

	/**
	 * Verify the nonce for banner dismissal
	 * Separated into its own method for testability
	 *
	 * @return bool
	 */
	protected function verify_dismiss_nonce() {
		try {
			$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
			return wp_verify_nonce( $nonce, 'wcshipping_dismiss_feature_banner' ) !== false;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * AJAX handler for tracking feature banner button clicks
	 * called by the rendered CTAs in the banner
	 */
	public function track_feature_banner_click() {
		check_ajax_referer( 'wcshipping_track_feature_banner_click', 'nonce' );

		$banner_id     = sanitize_text_field( wp_unslash( $_POST['banner_id'] ?? '' ) );
		$button_action = sanitize_text_field( wp_unslash( $_POST['button_action'] ?? '' ) );

		if ( empty( $banner_id ) || empty( $button_action ) ) {
			wp_send_json_error( array( 'message' => 'Invalid parameters' ) );
		}

		Tracks::feature_banner_button_clicked( $banner_id, $button_action );

		wp_send_json_success( array( 'message' => 'Click tracked successfully' ) );
	}

	/**
	 * Get non dismissed banners and enqueue the assets
	 */
	public function render_feature_banners() {
		if ( ! $this->should_show_feature_banners() ) {
			return;
		}

		$active_banners = $this->get_active_banners();

		if ( empty( $active_banners ) ) {
			return;
		}

		foreach ( $active_banners as $banner_id => $banner_data ) {
			$this->render_banner( $banner_id, $banner_data );

			Tracks::feature_banner_viewed( $banner_id );
		}

		$this->enqueue_banner_assets();
	}

	/**
	 * Render a single feature banner
	 *
	 * @param string $banner_id
	 * @param object $banner_data
	 */
	protected function render_banner( $banner_id, $banner_data ) {
		$content       = $banner_data->content ?? new stdClass();
		$label         = $content->label ?? '';
		$title         = $content->title ?? '';
		$description   = $content->description ?? '';
		$illustrations = $content->illustrations ?? null;
		$cta_buttons   = $content->cta ?? array();

		// Process illustration URL
		$image_url = '';
		if ( $illustrations && isset( $illustrations->url ) ) {
			$image_filename = $illustrations->url;

			// Check if it's just a filename (not a full URL)
			if ( ! filter_var( $image_filename, FILTER_VALIDATE_URL ) ) {
				// Construct the full path to the image in the images folder
				$image_path = WCSHIPPING_PLUGIN_DIR . '/images/' . $image_filename;

				// Check if the image file exists
				if ( file_exists( $image_path ) ) {
					// Generate the URL for the image
					$image_url = plugins_url( 'images/' . $image_filename, WCSHIPPING_PLUGIN_FILE );
				} else {
					// Log if image not found (optional)
					$this->logger->log( 'Feature banner image not found: ' . $image_filename, __METHOD__ );
				}
			}
			// if the banner is not a valid file name, we are keeping it as null to skip rendering
			// in future once we have a CDN to serve images, we can add the condition to render via url.
		}

		?>
		<div class="wcshipping-feature-banner notice notice-info is-dismissible" data-banner-id="<?php echo esc_attr( $banner_id ); ?>">
			<div class="wcshipping-feature-banner__content">
				<div class="wcshipping-feature-banner__text">
					<?php if ( $label ) : ?>
						<span class="components-badge wcshipping-feature-banner__label"><?php echo esc_html( $label ); ?></span>
					<?php endif; ?>

					<?php if ( $title ) : ?>
						<h3 class="wcshipping-feature-banner__title"><?php echo esc_html( $title ); ?></h3>
					<?php endif; ?>

					<?php if ( $description ) : ?>
						<p class="wcshipping-feature-banner__description"><?php echo esc_html( $description ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $cta_buttons ) ) : ?>
						<div class="wcshipping-feature-banner__actions">
							<?php
							foreach ( $cta_buttons as $button ) :
								$button_style = $button->style ?? 'primary';
								$button_class = $button_style === 'secondary' ? 'button button-secondary' : 'button button-primary';
								?>
								<a href="#"
									class="wcshipping-feature-banner__button <?php echo esc_attr( $button_class ); ?>"
									data-banner-id="<?php echo esc_attr( $banner_id ); ?>"
									data-button-action="<?php echo esc_attr( $button->title ?? 'Learn More' ); ?>"
									data-url="<?php echo esc_attr( $button->url ?? '' ); ?>"
									<?php
									if ( isset( $button->target ) ) :
										?>
										target="<?php echo esc_attr( $button->target ); ?>"<?php endif; ?>>
									<?php echo esc_html( $button->title ?? 'Learn More' ); ?>
								</a>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>

				<?php if ( $image_url ) : ?>
					<div class="wcshipping-feature-banner__illustration">
						<img src="<?php echo esc_url( $image_url ); ?>"
							alt="<?php echo esc_attr( $illustrations->altText ?? '' ); ?>"
							class="wcshipping-feature-banner__image" />
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue CSS and JavaScript for feature banners
	 */
	protected function enqueue_banner_assets() {
		// Enqueue CSS
		wp_enqueue_style(
			'wcshipping_feature_banners',
			WCSHIPPING_STYLESHEETS_URL . 'connect-banner.css',
			array(),
			WCSHIPPING_VERSION
		);

		// Enqueue JavaScript
		wp_enqueue_script(
			'wcshipping-feature-banners',
			WCSHIPPING_JAVASCRIPT_URL . 'wcshipping-feature-banners.js',
			array( 'jquery' ),
			WCSHIPPING_VERSION,
			true // Load in footer
		);

		// Pass data to JavaScript
		$banner_data = wp_json_encode(
			array(
				'dismissNonce' => wp_create_nonce( 'wcshipping_dismiss_feature_banner' ),
				'trackNonce'   => wp_create_nonce( 'wcshipping_track_feature_banner_click' ),
			)
		);

		wp_add_inline_script(
			'wcshipping-feature-banners',
			"var wcShippingBanners = $banner_data;",
			'before'
		);
	}
}
