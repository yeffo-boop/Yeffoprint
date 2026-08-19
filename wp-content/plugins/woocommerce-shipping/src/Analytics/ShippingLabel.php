<?php

namespace Automattic\WCShipping\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShippingLabel {
	const ANALYTICS_PATH = '/analytics/shipping';
	/**
	 * Initialize the shipping label analytics
	 */
	public function init() {
		add_filter( 'woocommerce_analytics_report_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Add the shipping label analytics menu item
	 *
	 * @param array $report_pages Report page menu items.
	 * @return array
	 */
	public function add_menu_item( $report_pages ) {

		$wcshipping_analytics_menu_item = array(
			'id'     => 'wcshipping-label-analytics',
			'title'  => __( 'Shipping Labels', 'woocommerce-shipping' ),
			'parent' => 'woocommerce-analytics',
			'path'   => self::ANALYTICS_PATH,
		);
		// Insert the shipping label analytics menu item before the settings menu item if it exists
		$settings_index = array_search( 'woocommerce-analytics-settings', array_column( $report_pages, 'id' ) );
		if ( $settings_index !== false ) {
			array_splice(
				$report_pages,
				$settings_index,
				0,
				array(
					$wcshipping_analytics_menu_item,
				)
			);
		} else {
			$report_pages[] = $wcshipping_analytics_menu_item;
		}

		return $report_pages;
	}

	public function enqueue_scripts( $current_screen ) {
		if ( 'woocommerce_page_wc-admin' !== $current_screen ) {
			return;
		}

		do_action(
			'wcshipping_enqueue_script',
			'woocommerce-shipping-analytics',
			array(
				'cacheExpirationInSeconds' => LabelsService::LABELS_TRANSIENT_EXPIRATION_IN_SECONDS,
			)
		);
	}
}
