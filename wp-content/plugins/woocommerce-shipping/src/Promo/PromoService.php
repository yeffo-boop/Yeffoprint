<?php
/**
 * Class PromoService
 *
 * @package Automattic\WCShipping
 */

namespace Automattic\WCShipping\Promo;

use Automattic\WCShipping\Connect\WC_Connect_Options;
use Automattic\WCShipping\Connect\WC_Connect_Service_Schemas_Store;
use Automattic\WCShipping\Connect\WC_Connect_Service_Settings_Store;
use Automattic\WCShipping\Tracks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all promotion logics.
 */
class PromoService {
	/**
	 * Schema Store instance
	 *
	 * @var WC_Connect_Service_Schemas_Store
	 */
	protected $schemas_store;

	/**
	 * Settings Store instance
	 *
	 * @var WC_Connect_Service_Settings_Store
	 */
	protected $settings_store;

	/**
	 * Class constructor.
	 *
	 * @param WC_Connect_Service_Schemas_Store  $schemas_store   Schema store instance.
	 * @param WC_Connect_Service_Settings_Store $settings_store  Settings store instance.
	 */
	public function __construct( WC_Connect_Service_Schemas_Store $schemas_store, WC_Connect_Service_Settings_Store $settings_store ) {
		$this->schemas_store  = $schemas_store;
		$this->settings_store = $settings_store;

		add_action( 'admin_notices', array( $this, 'maybe_show_promotion_notice' ) );
		add_action( 'admin_init', array( $this, 'handle_promotion_notice_dismiss' ) );
	}

	/**
	 * Get promotion from service schemas.
	 *
	 * @return object|null Promotion object or null if none exists
	 */
	public function get_promotion() {
		$schemas = $this->schemas_store->get_service_schemas();
		$promo   = $schemas->promotion ?? null;

		if ( ! isset( $promo->id ) || $promo->remaining <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( isset( $promo->endDate ) && strtotime( $promo->endDate ) < time() ) {
			return null;
		}

		// Unset dismissed parts of the promotion.
		if ( $this->is_promotion_dismissed( 'notice', $promo->id ) ) {
			unset( $promo->notice );
		}

		if ( $this->is_promotion_dismissed( 'banner', $promo->id ) ) {
			unset( $promo->banner );
		}

		// Sanitize HTML properties.
		foreach ( array( 'notice', 'banner', 'tooltip' ) as $property ) {
			if ( isset( $promo->{$property} ) ) {
				$promo->{$property} = wp_kses_post( $promo->{$property} );
			}
			if ( empty( $promo->{$property} ) ) {
				unset( $promo->{$property} );
			}
		}

		return $promo;
	}

	/**
	 * Check if a promotion is dismissed by type and ID.
	 *
	 * @param string $type The type of promotion ('notice' | 'banner').
	 * @param string $id   The ID of the promotion.
	 *
	 * @return bool True if the promotion is dismissed, false otherwise.
	 */
	public function is_promotion_dismissed( string $type, string $id ): bool {
		return (bool) get_user_meta( get_current_user_id(), "wcc-promo-{$type}-{$id}-dismissed", true );
	}

	/**
	 * Dismiss a promotion by type and ID.
	 *
	 * @param string $type The type of promotion ('notice' | 'banner').
	 * @param string $id   The ID of the promotion.
	 */
	public function dismiss_promotion( string $type, string $id ) {
		update_user_meta( get_current_user_id(), "wcc-promo-{$type}-{$id}-dismissed", true );
		Tracks::promo_dismissed( $type, $id );
	}

	/**
	 * Maybe show promotion on the WooCommerce Orders page.
	 */
	public function maybe_show_promotion_notice() {
		$promo = $this->get_promotion();

		if (
			// Promotion notice available.
			! isset( $promo->notice )
			// WooCommerce Orders page.
			|| get_current_screen()->base !== 'woocommerce_page_wc-orders'
			// WooCommerce Order detail page.
			|| isset( $_GET['id'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			return;
		}

		$dismiss_url = add_query_arg(
			array(
				'wcc-dismiss-promo-notice' => $promo->id,
				'_wpnonce'                 => wp_create_nonce( 'wcc_dismiss_promo_notice_nonce' ),
			),
		);

		?>
		<div class="notice notice-info is-dismissible" style="position: relative;">
			<a href="<?php echo esc_url( $dismiss_url ); ?>"
				style="text-decoration: none;"
				class="notice-dismiss"
				title="<?php esc_attr_e( 'Dismiss this notice', 'woocommerce-shipping' ); ?>"></a>
			<p><?php echo wp_kses_post( $promo->notice ); ?></p>
		</div>
		<?php

		Tracks::promo_notice_viewed( $promo->id );
	}

	/**
	 * Handle the dismissal of the Orders page notice.
	 */
	public function handle_promotion_notice_dismiss() {
		$promo = $this->get_promotion();

		if ( ! isset( $promo->id ) ) {
			return;
		}

		if ( ! isset( $_GET['wcc-dismiss-promo-notice'] )
			|| $_GET['wcc-dismiss-promo-notice'] !== $promo->id
			|| ! isset( $_GET['_wpnonce'] )
			|| ! check_admin_referer( 'wcc_dismiss_promo_notice_nonce' ) ) {
				return;
		}

		$this->dismiss_promotion( 'notice', $promo->id );
	}

	/**
	 * Maybe decrement promotion remaining count when a label is purchased.
	 *
	 * @param int    $order_id       The order ID.
	 * @param object $new_label_data The new label data.
	 */
	public function maybe_decrement_promotion_remaining( int $order_id, object $new_label_data ) {
		if ( 'PURCHASED' !== $new_label_data->status ) {
			return;
		}

		$promo = $this->get_promotion();

		if ( ! $promo || $new_label_data->promo_id !== $promo->id || $promo->remaining <= 0 ) {
			return;
		}

		$label_data = current(
			array_filter(
				$this->settings_store->get_label_order_meta_data( $order_id ),
				fn( $data ) => $data['label_id'] === $new_label_data->label_id
			)
		);

		if ( $label_data && 'PURCHASED' === $label_data['status'] ) {
			return;
		}

		--$promo->remaining;

		$this->update_promotion_schema( $promo );
	}

	/**
	 * Update the promotion schema in the service schemas store.
	 *
	 * @param object $promotion The updated promotion object.
	 */
	protected function update_promotion_schema( object $promotion ) {
		$services            = $this->schemas_store->get_service_schemas();
		$services->promotion = $promotion;
		WC_Connect_Options::update_option( 'services', $services );
	}
}
