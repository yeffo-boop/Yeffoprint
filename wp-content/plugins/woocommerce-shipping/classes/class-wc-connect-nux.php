<?php

namespace Automattic\WCShipping\Connect;

use Automattic\WCShipping\LabelPurchase\ViewService;
use Jetpack_Connection_Banner;
use Automattic\WCShipping\Utils;
use WP_Screen;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Connect_Nux {
	/**
	 * Jetpack status constants.
	 */
	const JETPACK_NOT_CONNECTED = 'not-connected';
	const JETPACK_DEV           = 'dev';
	const JETPACK_CONNECTED     = 'connected';

	/**
	 * Option name for dismissing success banner
	 * after the JP connection flow
	 */
	const SHOULD_SHOW_AFTER_CXN_BANNER = 'should_display_nux_after_jp_cxn_banner';

	const TOS_ACCEPTED = 'tos_accepted';

	/**
	 * The GET parameter used to recognize returns after successful connection authorization.
	 */
	const AUTH_SUCCESS_SOURCE_RETURN_PARAM = 'wcshipping-auth-successful';

	/**
	 * The name of the nonce GET parameter after successful connection authorization.
	 */
	const AUTH_SUCCESS_NONCE_RETURN_PARAM = '_wcshipping-auth-successful-nonce';

	/**
	 * The name of the nonce action used to recognize successful connection authorization.
	 */
	const AUTH_SUCCESS_NONCE_ACTION = 'wcshipping_auth_successful';

	/**
	 * @var ViewService
	 */
	private $view_service;

	/**
	 * WC_Connect_Nux constructor.
	 *
	 * @param ViewService $view_service
	 *
	 * @return void
	 */
	function __construct( ViewService $view_service ) {
		$this->view_service = $view_service;

		add_action( 'admin_enqueue_scripts', array( $this, 'load_scripts_and_styles' ) );
	}

	private function get_notice_states() {
		$states = get_user_meta( get_current_user_id(), 'wcshipping_nux_notices', true );

		if ( ! is_array( $states ) ) {
			return array();
		}

		return $states;
	}

	public function is_notice_dismissed( $notice ) {
		$notices = $this->get_notice_states();

		return isset( $notices[ $notice ] ) && $notices[ $notice ];
	}

	public function dismiss_notice( $notice ) {
		$notices            = $this->get_notice_states();
		$notices[ $notice ] = true;
		update_user_meta( get_current_user_id(), 'wcshipping_nux_notices', $notices );
	}

	public function ajax_dismiss_notice() {
		if ( empty( $_POST['dismissible_id'] ) ) {
			return;
		}

		check_ajax_referer( 'wcshipping_dismiss_notice', 'nonce' );
		$this->dismiss_notice( sanitize_key( $_POST['dismissible_id'] ) );
		wp_die();
	}

	/**
	 * Check that the current user is the owner of the Jetpack connection
	 * - Only that person can accept the TOS
	 *
	 * @return bool
	 * @uses self::get_jetpack_install_status()
	 */
	public function can_accept_tos() {
		$jetpack_status = $this->get_jetpack_install_status();

		// Developer case
		if ( self::JETPACK_DEV === $jetpack_status ) {
			return true;
		}

		$can_accept = WC_Connect_Jetpack::is_current_user_connected();

		return $can_accept;
	}

	public static function get_banner_type_to_display( $status = array() ) {
		if ( ! isset( $status['jetpack_connection_status'] ) ) {
			return false;
		}

		/*
		The NUX Flow:
		- Case 1: Jetpack not connected (with TOS or no TOS accepted):
			1. show_banner_before_connection()
			2. connect to JP
			3. show_banner_after_connection(), which sets the TOS acceptance in options
		- Case 2: Jetpack connected, no TOS
			1. show_tos_only_banner(), which accepts TOS on button click
		- Case 3: Jetpack connected, and TOS accepted
			This is an existing user. Do nothing.
		*/
		switch ( $status['jetpack_connection_status'] ) {
			case self::JETPACK_NOT_CONNECTED:
				return 'before_jetpack_connection';
			case self::JETPACK_CONNECTED:
			case self::JETPACK_DEV:
				// Has the user just gone through our NUX connection flow?
				if ( isset( $status['should_display_after_cxn_banner'] ) && $status['should_display_after_cxn_banner'] ) {
					return 'after_jetpack_connection';
				}

				// Has the user already accepted our TOS? Then do nothing.
				// Note: TOS is accepted during the after_connection banner
				if (
					isset( $status[ self::TOS_ACCEPTED ] )
					&& ! $status[ self::TOS_ACCEPTED ]
					&& isset( $status['can_accept_tos'] )
					&& $status['can_accept_tos']
				) {
					return 'tos_only_banner';
				}

				return false;
			default:
				return false;
		}
	}

	public function get_jetpack_install_status() {
		$status = self::JETPACK_CONNECTED;

		if ( defined( 'JETPACK_DEV_DEBUG' ) && true === JETPACK_DEV_DEBUG ) {
			// activated, and dev mode on
			$status = self::JETPACK_DEV;
		}
		// dev mode off, check if connected.
		elseif ( ! WC_Connect_Jetpack::is_connected() ) {
			$status = self::JETPACK_NOT_CONNECTED;
		}

		/**
		 * Filter the derived Jetpack install/connection status used by NUX checks.
		 *
		 * Internal hook used by WooCommerce Shipping test/bootstrap infrastructure.
		 * It is not designed for third-party plugin integrations and may change or
		 * be removed in future releases without notice.
		 *
		 * @internal
		 *
		 * @param string $status Calculated Jetpack status.
		 */
		$filtered_status = apply_filters( 'wcshipping_jetpack_install_status', $status );
		if ( ! is_string( $filtered_status ) ) {
			return $status;
		}

		$allowed_statuses = array(
			self::JETPACK_NOT_CONNECTED,
			self::JETPACK_DEV,
			self::JETPACK_CONNECTED,
		);

		if ( ! in_array( $filtered_status, $allowed_statuses, true ) ) {
			return $status;
		}

		return $filtered_status;
	}

	public function should_display_nux_notice_on_screen( $screen ) {
		if ( // Display if on any of these admin pages.
			( // Products list.
				'product' === $screen->post_type
				&& 'edit' === $screen->base
			)
			|| ( // Orders list.
				'shop_order' === $screen->post_type
				&& 'edit' === $screen->base
			)
			|| ( // Edit order page.
				'shop_order' === $screen->post_type
				&& 'post' === $screen->base
			)
			|| ( // Orders list && Edit order page ( HPOS ).
				'woocommerce_page_wc-orders' === $screen->base
			)
			|| ( // WooCommerce settings.
				'woocommerce_page_wc-settings' === $screen->base
			)
			|| ( // WooCommerce featured extension page.
				'woocommerce_page_wc-addons' === $screen->base
				&& isset( $_GET['section'] ) && 'featured' === sanitize_text_field( wp_unslash( $_GET['section'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended --- Ignoring this as no DB operation
			)
			|| ( // WooCommerce shipping extension page.
				'woocommerce_page_wc-addons' === $screen->base
				&& isset( $_GET['section'] ) && 'shipping_methods' === sanitize_text_field( wp_unslash( $_GET['section'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended --- Ignoring this as no DB operation
			)
			|| 'plugins' === $screen->base
		) {
			return true;
		}

		return false;
	}

	/**
	 * Check if the current page is the WC Shipping settings page.
	 *
	 * @param WP_Screen|null $current_screen The object that represents the current page.
	 * @return bool
	 */
	public function is_wcshipping_settings_page( ?WP_Screen $current_screen ) {
		if ( ! $current_screen instanceof WP_Screen ) {
			$current_screen = get_current_screen();
		}

		$settings_section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (
			'woocommerce_page_wc-settings' === $current_screen->base
			&& 'woocommerce-shipping-settings' === $settings_section
		) {
			return true;
		}

		return false;
	}

	public function get_feature_list_for_country( $country ) {
		$feature_list    = false;
		$supports_labels = ( 'US' === $country );

		$is_ppec_active    = is_plugin_active( 'woocommerce-gateway-paypal-express-checkout/woocommerce-gateway-paypal-express-checkout.php' );
		$ppec_settings     = get_option( 'woocommerce_ppec_paypal_settings', array() );
		$supports_payments = $is_ppec_active && ( ! isset( $ppec_settings['enabled'] ) || 'yes' === $ppec_settings['enabled'] );

		if ( $supports_payments && $supports_labels ) {
			$feature_list = __( 'shipping label printing, and smoother payment setup', 'woocommerce-shipping' );
		} elseif ( $supports_payments ) {
			$feature_list = __( 'smoother payment setup', 'woocommerce-shipping' );
		} elseif ( $supports_labels ) {
			$feature_list = __( 'shipping label printing', 'woocommerce-shipping' );
		} elseif ( $supports_payments && $supports_labels ) {
			$feature_list = __( 'shipping label printing and smoother payment setup', 'woocommerce-shipping' );
		} elseif ( $supports_payments ) {
			$feature_list = __( 'smoother payment setup', 'woocommerce-shipping' );
		} elseif ( $supports_labels ) {
			$feature_list = __( 'shipping label printing', 'woocommerce-shipping' );
		}

		return $feature_list;
	}

	public function get_jetpack_redirect_url() {
		$full_path = add_query_arg( array() );
		// Remove [...]/wp-admin so we can use admin_url().
		$new_index = strpos( $full_path, '/wp-admin' ) + strlen( '/wp-admin' );
		$path      = substr( $full_path, $new_index );

		return esc_url( admin_url( $path ) );
	}

	public static function is_tos_accepted() {
		return WC_Connect_Options::get_option( self::TOS_ACCEPTED );
	}

	/**
	 * Register Terms of Service acceptance.
	 *
	 * @param string $source Where the acceptance originates from.
	 * @return void
	 */
	public static function accept_tos( $source = '' ) {
		if ( self::is_tos_accepted() ) {
			/**
			 * Fires if the user has already accepted our ToS.
			 *
			 * @since 1.0.5
			 *
			 * @param string $source The source of the ToS acceptance.
			 */
			do_action( 'wcshipping_tos_already_accepted', $source );

			return;
		}

		WC_Connect_Options::update_option( self::TOS_ACCEPTED, true );

		/**
		 * Fires when the user accepts our ToS.
		 *
		 * @since 1.0.0
		 *
		 * @param string $source The source of the ToS acceptance.
		 */
		do_action( 'wcshipping_tos_accepted', $source );
	}

	public function set_up_nux_notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Check for plugin install and activate permissions to handle Jetpack on multisites:
		// Admins might not be able to install or activate plugins, but Jetpack might already have been installed by a superadmin.
		// If this is the case, the admin can connect the site on their own, and should be able to use WCS as ususal
		$jetpack_install_status = $this->get_jetpack_install_status();

		$banner_to_display = self::get_banner_type_to_display(
			array(
				'jetpack_connection_status'       => $jetpack_install_status,
				'tos_accepted'                    => self::is_tos_accepted(),
				'can_accept_tos'                  => $this->can_accept_tos(),
				'should_display_after_cxn_banner' => WC_Connect_Options::get_option( self::SHOULD_SHOW_AFTER_CXN_BANNER ),
			)
		);

		switch ( $banner_to_display ) {
			case 'before_jetpack_connection':
				wp_enqueue_style( 'wcshipping_connect_banner' );

				add_action(
					'admin_post_register_wcshipping_jetpack',
					array( $this, 'register_wcshipping_jetpack' )
				);

				add_action( 'admin_notices', array( $this, 'show_banner_before_connection' ), 9 );
				break;
			case 'tos_only_banner':
				wp_enqueue_style( 'wcshipping_connect_banner' );
				add_action( 'admin_notices', array( $this, 'show_tos_banner' ) );
				break;
			case 'after_jetpack_connection':
				wp_enqueue_style( 'wcshipping_connect_banner' );
				add_action( 'admin_notices', array( $this, 'show_banner_after_connection' ) );
				break;
		}

		$this->register_callback_listeners();

		add_action( 'wp_ajax_wcshipping_dismiss_notice', array( $this, 'ajax_dismiss_notice' ) );
	}

	/**
	 * Register callback listeners.
	 *
	 * @return void
	 */
	private function register_callback_listeners() {
		if (
			isset( $_GET[ self::AUTH_SUCCESS_NONCE_RETURN_PARAM ] )
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_GET[ self::AUTH_SUCCESS_NONCE_RETURN_PARAM ] ) ), self::AUTH_SUCCESS_NONCE_ACTION )
			&& isset( $_GET[ self::AUTH_SUCCESS_SOURCE_RETURN_PARAM ] )
		) {
			$source = sanitize_text_field( wp_unslash( $_GET[ self::AUTH_SUCCESS_SOURCE_RETURN_PARAM ] ) );

			$allowed_sources = array(
				'connection_banner',
				'onboarding-connect-button',
			);

			if ( ! in_array( $source, $allowed_sources, true ) ) {
				return;
			}

			/**
			 * Fires when we have a successful authorization return.
			 *
			 * @since 1.0.5
			 *
			 * @param string $source The source of the return value.
			 */
			do_action( 'wcshipping_wpcom_connect_site_connected', $source );
		}
	}

	public function show_banner_before_connection() {
		if ( ! $this->view_service->is_store_eligible_for_shipping_label_creation() ) {
			return;
		}

		$current_screen = get_current_screen();
		if ( ! $this->should_display_nux_notice_on_screen( $current_screen ) ) {
			return;
		}

		// The settings page itself will display a separate component with the same purpose,
		// so there is no need for us to show duplicate messages.
		if ( $this->is_wcshipping_settings_page( $current_screen ) ) {
			return;
		}

		// Remove Jetpack's connect banners since we're showing our own.
		if ( class_exists( 'Jetpack_Connection_Banner' ) ) {
			$jetpack_banner = Jetpack_Connection_Banner::init();

			remove_action( 'admin_notices', array( $jetpack_banner, 'render_banner' ) );
			remove_action( 'admin_notices', array( $jetpack_banner, 'render_connect_prompt_full_screen' ) );
		}

		// Make sure that we wait until the button is clicked before displaying
		// the after_connection banner.
		WC_Connect_Options::delete_option( self::SHOULD_SHOW_AFTER_CXN_BANNER );

		$country = WC()->countries->get_base_country();
		/* translators: %s: list of features, potentially comma separated */
		$description_base = __( "Connect your store to activate <strong>WooCommerce Shipping</strong>. Once you connect your store to WordPress.com, you'll have access to %s.", 'woocommerce-shipping' );
		$feature_list     = $this->get_feature_list_for_country( $country );
		$banner_content   = array(
			'source'            => 'banner_before_connection',
			'description'       => sprintf( $description_base, $feature_list ),
			'button_text'       => __( 'Connect', 'woocommerce-shipping' ),
			'should_show_jp'    => true,
			'should_show_terms' => true,
		);

		$this->show_nux_banner( $banner_content );
	}

	public function show_banner_after_connection() {
		if ( ! $this->view_service->is_store_eligible_for_shipping_label_creation() ) {
			return;
		}

		if ( ! $this->should_display_nux_notice_on_screen( get_current_screen() ) ) {
			return;
		}

		// Did the user just dismiss?
		if (
			isset( $_GET['wcshipping-nux-notice'] )
			&& isset( $_GET['_wpnonce'] )
			&& check_admin_referer( 'wcshipping_dismiss_notice' )
			&& 'dismiss' === sanitize_text_field( wp_unslash( $_GET['wcshipping-nux-notice'] ) ) ) {
			// No longer need to keep track of whether the before connection banner was displayed.
			WC_Connect_Options::delete_option( self::SHOULD_SHOW_AFTER_CXN_BANNER );

			/**
			 * Fires when the user dismisses the setup complete banner.
			 *
			 * @since 1.0.0
			 */
			do_action( 'wcshipping_setup_complete_banner_dismissed' );

			wp_safe_redirect( remove_query_arg( array( '_wpnonce', 'wcshipping-nux-notice' ) ) );
			exit;
		}

		$country = WC()->countries->get_base_country();
		/* translators: %s: list of features, potentially comma separated */
		$description_base = __( 'You can now enjoy %s.', 'woocommerce-shipping' );
		$feature_list     = $this->get_feature_list_for_country( $country );

		$this->show_nux_banner(
			array(
				'source'            => 'banner_after_connection',
				'description'       => sprintf( $description_base, $feature_list ),
				'button_text'       => __( 'Got it, thanks!', 'woocommerce-shipping' ),
				'button_link'       => add_query_arg(
					array(
						// The source differs because we're registering that we had a successful return initiated by
						// the "connection_banner" source aka "banner_before_connection" banner.
					self::AUTH_SUCCESS_SOURCE_RETURN_PARAM => 'connection_banner',
						self::AUTH_SUCCESS_NONCE_RETURN_PARAM => wp_create_nonce( self::AUTH_SUCCESS_NONCE_ACTION ),
						'wcshipping-nux-notice'            => 'dismiss',
						'_wpnonce'                         => wp_create_nonce( 'wcshipping_dismiss_notice' ),
					)
				),
				'should_show_jp'    => false,
				'should_show_terms' => false,
			)
		);
	}

	public function show_tos_banner() {
		if ( ! $this->view_service->is_store_eligible_for_shipping_label_creation() ) {
			return;
		}

		$current_screen = get_current_screen();
		if ( ! $this->should_display_nux_notice_on_screen( $current_screen ) ) {
			return;
		}

		// The settings page itself will display a separate component with the same purpose,
		// so there is no need for us to show duplicate messages.
		if ( $this->is_wcshipping_settings_page( $current_screen ) ) {
			return;
		}

		$source = 'tos_banner';

		if (
			isset( $_GET['wcshipping-nux-tos'] )
			&& isset( $_GET['_wpnonce'] )
			&& check_admin_referer( 'wcshipping_accepted_tos' )
			&& 'accept' === sanitize_text_field( wp_unslash( $_GET['wcshipping-nux-tos'] ) ) ) {
			// Make sure we queue up the "after_jetpack_connection" banner if ToS was accepted.
			// We normally queue this before a Jetpack connection is attempted, but since the
			// connection already exists for ToS only banners, then we need to register it here
			// as well to ensure a coherent user-experience.
			WC_Connect_Options::update_option( self::SHOULD_SHOW_AFTER_CXN_BANNER, true );

			self::accept_tos( $source );

			wp_safe_redirect( remove_query_arg( array( '_wpnonce', 'wc-nux-tos' ) ) );
			exit;
		}

		$country = WC()->countries->get_base_country();
		/* translators: %s: list of features, potentially comma separated */
		$description_base = __( 'Your store is connected to WordPress.com. Accept our terms to activate <strong>WooCommerce Shipping</strong> and access %s.', 'woocommerce-shipping' );
		$feature_list     = $this->get_feature_list_for_country( $country );

		$this->show_nux_banner(
			array(
				'source'            => $source,
				'description'       => sprintf( $description_base, $feature_list ),
				'button_text'       => __( 'Enable WooCommerce Shipping', 'woocommerce-shipping' ),
				'button_link'       => add_query_arg(
					array(
						'wcshipping-nux-tos' => 'accept',
						'_wpnonce'           => wp_create_nonce( 'wcshipping_accepted_tos' ),
					)
				),
				'should_show_jp'    => false,
				'should_show_terms' => true,
			)
		);
	}

	public function show_nux_banner( $content ) {
		if ( isset( $content['dismissible_id'] ) && $this->is_notice_dismissed( sanitize_key( $content['dismissible_id'] ) ) ) {
			return;
		}

		if ( isset( $content['source'] ) ) {
			/**
			 * Track when a banner is shown.
			 *
			 * @since 1.0.5
			 *
			 * @param string $source The source aka what type of banner we are displaying.
			 * @return void
			 */
			do_action( 'wcshipping_show_banner', $content['source'] );
		}

		$allowed_html = array(
			'a'      => array( 'href' => array() ),
			'strong' => array(),
			'b'      => array(),
			'br'     => array(),
			'em'     => array(),
			'i'      => array(),
		);

		?>
		<div class="notice wcshipping-nux__notice notice-<?php echo WC_Connect_Jetpack::is_connected() && self::is_tos_accepted() ? 'success' : 'warning'; ?> <?php echo isset( $content['dismissible_id'] ) ? 'is-dismissible' : ''; ?>"
			data-dismissible-id="<?php echo isset( $content['dismissible_id'] ) ? esc_attr( $content['dismissible_id'] ) : ''; ?>">
			<div class="wcshipping-nux__notice-content">
				<p>
					<?php
					echo wp_kses( $content['description'], $allowed_html );
					?>
				</p>
				<?php if ( isset( $content['should_show_terms'] ) && $content['should_show_terms'] ) : ?>
					<p class="wcshipping-nux__notice-content-tos">
						<?php
						/* translators: %1$s example values include "Install Jetpack and CONNECT >", "Activate Jetpack and CONNECT >", "CONNECT >" */
						printf(
							wp_kses(
								// translators: %1$s: "Terms of Service", %2$s: "https://wordpress.com/tos/", %3$s: "https://automattic.com/privacy/"
								__( 'By clicking "%1$s", you agree to the <a href="%2$s">Terms of Service</a> and have read our <a href="%3$s">Privacy Policy</a>', 'woocommerce-shipping' ),
								$allowed_html
							),
							esc_html( $content['button_text'] ),
							'https://wordpress.com/tos/',
							'https://automattic.com/privacy/'
						);
						?>
					</p>
				<?php endif; ?>

			</div>
			<div class="wcshipping-nux__notice-button">

				<?php if ( isset( $content['button_link'] ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $content['button_link'] ); ?>">
						<?php echo esc_html( $content['button_text'] ); ?>
					</a>
				<?php else : ?>
					<form action="<?php echo esc_attr( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="register_wcshipping_jetpack" />
						<input type="hidden" name="redirect_url"
							value="<?php echo esc_url( $this->get_jetpack_redirect_url() ); ?>" />
						<?php wp_nonce_field( 'wcshipping_nux_notice' ); ?>
						<button
							class="woocommerce-shipping__connect-jetpack wcshipping-nux__notice-content-button button button-primary"
							onclick="this.className += ' disabled';">
							<?php echo esc_html( $content['button_text'] ); ?>
						</button>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
		if ( isset( $content['dismissible_id'] ) ) :
			wp_enqueue_script( 'wcshipping-connect-banner' );
		endif;
	}

	/**
	 * Get Jetpack connection URL.
	 */
	public function register_wcshipping_jetpack() {
		check_ajax_referer( 'wcshipping_nux_notice' );

		$redirect_url = '';
		if ( isset( $_POST['redirect_url'] ) ) {
			$redirect_url = esc_url_raw( wp_unslash( $_POST['redirect_url'] ) );
		}

		$source = 'connection_banner';

		// Clicking the "Connect" button is enough acceptance of ToS that we can
		// register it now, independent of what happens with the connection itself.
		self::accept_tos( $source );

		// Make sure we always display the after-connection banner
		// after the before_connection button is clicked.
		WC_Connect_Options::update_option( self::SHOULD_SHOW_AFTER_CXN_BANNER, true );

		WC_Connect_Jetpack::connect_site( $redirect_url, $source );
	}

	/**
	 * Load scripts and styles.
	 *
	 * @return void
	 */
	public function load_scripts_and_styles() {
		wp_register_style(
			'wcshipping_connect_banner',
			WCSHIPPING_STYLESHEETS_URL . 'connect-banner.css',
			array(),
			Utils::get_file_version( WCSHIPPING_STYLESHEETS_DIR . 'connect-banner.css' )
		);
		wp_register_script(
			'wcshipping-connect-banner',
			WCSHIPPING_JAVASCRIPT_URL . 'wc-connect-nux.js',
			array( 'jquery', 'wp-util' ),
			Utils::get_file_version( WCSHIPPING_JAVASCRIPT_DIR . 'wc-connect-nux.js' ),
			array( 'in_footer' => true )
		);
		$banner_info = wp_json_encode(
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wcshipping_dismiss_notice' ),
			)
		);
		wp_add_inline_script(
			'wcshipping-connect-banner',
			"var wcShippingConnectBanner = Object.assign( {}, wcShippingConnectBanner, $banner_info );",
			'before'
		);
	}
}
