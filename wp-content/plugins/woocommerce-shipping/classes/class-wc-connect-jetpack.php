<?php

namespace Automattic\WCShipping\Connect;

use Automattic\Jetpack\Connection\Manager;
use Automattic\Jetpack\Connection\Package_Version;
use Automattic\Jetpack\Status;
use Automattic\Jetpack\Status\Host;
use Automattic\WCShipping\Tracks;
use stdClass;
use WP_Error;
use WP_User;

class WC_Connect_Jetpack {
	const JETPACK_PLUGIN_SLUG = 'woocommerce-shipping';

	public static function get_connection_manager() {
		return new Manager( self::JETPACK_PLUGIN_SLUG );
	}

	/**
	 * Returns a Jetpack Status instance.
	 *
	 * Has methods to retrieve information about the current status of Jetpack and the site overall.
	 *
	 * @return Status The Jetpack status instance.
	 */
	public static function get_status() {
		return new Status();
	}

	/**
	 * Returns the Blog Token.
	 *
	 * Blog Tokens: These are the "main" tokens.
	 * Each site typically has one Blog Token, though some sites can have multiple "Special" Blog Tokens.
	 * These tokens are not associated with a user account. They represent the site's connection with the Jetpack servers.
	 *
	 * @return stdClass|WP_Error
	 */
	public static function get_blog_access_token() {
		return self::get_connection_manager()->get_tokens()->get_access_token();
	}

	/**
	 * Is Jetpack in offline mode?
	 *
	 * This was formerly called "Development Mode", but sites "in development" aren't always offline/localhost.
	 *
	 * @return bool
	 */
	public static function is_offline_mode() {
		return self::get_status()->is_offline_mode();
	}

	/**
	 * Helper method to get if Jetpack is connected (aka active).
	 *
	 * @return bool
	 */
	public static function is_active() {
		return self::is_connected();
	}

	/**
	 * Helper method to get if the current Jetpack website is marked as a staging/development site.
	 *
	 * @return bool
	 */
	public static function is_development_site() {
		return self::get_status()->is_development_site();
	}

	/**
	 * Helper method to get if the current Jetpack website is in safe mode.
	 *
	 * Safe mode is enabled by Jetpack if we identify an identify crisis with the site ID.
	 *
	 * @return bool
	 */
	public static function is_safe_mode() {
		return self::get_status()->in_safe_mode();
	}

	/**
	 * Determine if the site is hosted on the Atomic hosting platform.
	 *
	 * @return bool
	 */
	public static function is_atomic_site() {
		return ( new Host() )->is_woa_site();
	}

	/**
	 * Get the wpcom user data of the current|specified connected user.
	 *
	 * @return bool|array An array with the WPCOM user data on success, false otherwise.
	 */
	public static function get_connection_owner_wpcom_data() {
		$connection_owner = self::get_connection_owner();

		if ( ! $connection_owner ) {
			return false;
		}

		return self::get_connection_manager()->get_connected_user_data( $connection_owner->ID );
	}

	/**
	 * Get the wpcom user data of the current|specified connected user.
	 *
	 * @return array|bool
	 */
	public static function get_connected_user_data( $user_id ) {
		return self::get_connection_manager()->get_connected_user_data( $user_id );
	}

	/**
	 * Helper method to get the Jetpack connection owner user object, IF we are connected.
	 *
	 * @return WP_User|false
	 */
	public static function get_connection_owner() {
		return self::get_connection_manager()->get_connection_owner();
	}

	public static function is_current_user_connection_owner() {
		return self::get_connection_manager()->has_connected_owner() && self::get_connection_manager()->is_connection_owner();
	}

	/**
	 * Determines if the current user is connected to Jetpack
	 *
	 * @return bool Whether or nor the current user is connected to Jetpack
	 */
	public static function is_current_user_connected() {
		return self::get_connection_manager()->is_user_connected();
	}

	/**
	 * Determines if both the blog and user are connected to Jetpack.
	 *
	 * Returns true if the site has a token, a blog id, and a connected Blog owner.
	 *
	 * @return bool Whether or nor Jetpack is connected
	 */
	public static function is_connected() {
		return self::get_connection_manager()->is_connected() && self::get_connection_manager()->has_connected_owner();
	}

	/**
	 * Connects the site to Jetpack.
	 *
	 * This code performs a redirection, so anything executed after it will be ignored.
	 *
	 * @param string $redirect_url The return URL after a connection has been authorized on WPCOM.
	 * @param string $source The location the connection was initiated from.
	 * @param bool   $redirect Determines if we should redirect immediately or return the redirect URL.
	 * @return void|string|WP_Error
	 */
	public static function connect_site( $redirect_url, $source, $redirect = true ) {
		$connection_manager = self::get_connection_manager();

		// Register the site to wp.com.
		if ( ! $connection_manager->is_connected() ) {
			$result = $connection_manager->try_registration();
			if ( is_wp_error( $result ) ) {
				/**
				 * Fire when the site is about to be connected to WP.com.
				 *
				 * @since 1.0.0
				 *
				 * @param WP_Error $error The error thrown to explain why we cannot register a connection.
				 */
				do_action( 'wcshipping_wpcom_connect_site_error', $result );

				if ( $redirect ) {
					wp_die( esc_html( $result->get_error_message() ), 'wcshipping_jetpack_register_site_failed', 500 );
				} else {
					return $result;
				}
			}
		}

		// Initialise tracks class so the hooks fire if opted in.
		Tracks::init();

		/**
		 * Fire when the site is about to be connected to WP.com.
		 *
		 * @since 1.0.0
		 *
		 * @param string $source The location the connection was initiated from.
		 */
		do_action( 'wcshipping_wpcom_connect_site_start', $source );

		$redirect_url = add_query_arg(
			array(
				'from' => self::JETPACK_PLUGIN_SLUG,
			),
			$connection_manager->get_authorization_url( null, $redirect_url )
		);
		if ( $redirect ) {
			wp_redirect( $redirect_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect --- needs to go Jetpack URL.
			exit;
		} else {
			return $redirect_url;
		}
	}

	/**
	 * Jetpack Connection package version.
	 *
	 * @return string
	 */
	public static function get_jetpack_connection_package_version() {
		return Package_Version::PACKAGE_VERSION;
	}

	/**
	 * Get the WPCOM or self-hosted site ID.
	 *
	 * @return int|WP_Error
	 */
	public static function get_wpcom_site_id() {
		return Manager::get_site_id();
	}
}
