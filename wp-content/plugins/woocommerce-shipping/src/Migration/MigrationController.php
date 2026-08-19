<?php

namespace Automattic\WCShipping\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Internal\BatchProcessing\BatchProcessingController;

/**
 * Class MigrationController
 *
 * A controller to manage all the data migration from WCS&T to WooCommerce Shipping.
 *
 * @package Automattic\WCShipping\Migration
 */
class MigrationController {

	private LegacyLabelMigrator $label_migrator;
	private LegacySettingsMigrator $settings_migrator;

	public function __construct( LegacyLabelMigrator $label_migrator, LegacySettingsMigrator $settings_migrator ) {
		$this->label_migrator    = $label_migrator;
		$this->settings_migrator = $settings_migrator;
		add_filter( 'woocommerce_get_batch_processor', array( $this, 'get_label_batch_processor' ), 10, 2 );
		add_filter( 'woocommerce_debug_tools', array( $this, 'handle_woocommerce_debug_tools' ), 999, 1 );
	}

	/**
	 * Register LegacyLabelMigrator to WC BatchProcessor, this is how a third-party plugin provides its own batch-processor
	 *
	 * @param string|mixed $processor
	 * @param string|mixed $processor_class_name
	 *
	 * @return LegacyLabelMigrator|mixed
	 */
	public function get_label_batch_processor( $processor, $processor_class_name ) {
		if ( strpos( $processor_class_name, 'Automattic\WCShipping\Migration\LegacyLabelMigrator' ) !== false ) {
			return $this->label_migrator;
		}

		return $processor;
	}

	/**
	 * Migrate all the data from WCS&T to WooCommerce Shipping.
	 */
	public function migrate_all(): void {
		$this->label_migrator->start();
		$this->settings_migrator->migrate_all();
	}

	/**
	 * Migrate the settings from WCS&T to WooCommerce Shipping.
	 */
	public function migrate_settings(): void {
		$this->settings_migrator->migrate_all();
	}

	/**
	 * Migrate the labels from WCS&T to WooCommerce Shipping.
	 */
	public function migrate_labels(): void {
		$this->label_migrator->start();
	}

	/**
	 * Add the tool to start or stop the background process that converts order coupon metadata entries.
	 *
	 * @param array $tools Old tools array.
	 * @return array Updated tools array.
	 */
	public function handle_woocommerce_debug_tools( array $tools ): array {
		$batch_processor = wc_get_container()->get( BatchProcessingController::class );
		$pending_count   = $this->label_migrator->get_total_pending_count();

		$start_label_migration_desc = __( 'This will migrate the labels purchased by WooCommerce Shipping & Tax to be viewable in WooCommerce Shipping.', 'woocommerce-shipping' );

		if ( 0 === $pending_count ) {
			$tools['wcshipping_start_migrating_label_data'] = array(
				'name'     => __( 'Start migrating WooCommerce Shipping labels', 'woocommerce-shipping' ),
				'button'   => __( 'Start migration', 'woocommerce-shipping' ),
				'disabled' => true,
				'desc'     => $start_label_migration_desc,
			);
		} elseif ( $batch_processor->is_enqueued( get_class( $this->label_migrator ) ) ) {
			$tools['wcshipping_stop_migrating_label_data'] = array(
				'name'     => __( 'Stop migrating WooCommerce Shipping labels', 'woocommerce-shipping' ),
				'button'   => __( 'Stop migration', 'woocommerce-shipping' ),
				'desc'     =>
					sprintf(
						/* translators: %d=count of labels pending migration */
						_n(
							'This will stop the background process that migrates purchased labels. There are currently <strong>%d label</strong> that can be migrated.',
							'This will stop the background process that migrates purchased labels. There are currently <strong>%d labels</strong> that can be migrated.',
							$pending_count,
							'woocommerce-shipping'
						),
						$pending_count
					),
				'callback' => function () {
					$this->label_migrator->stop();
					return __( 'The WooCommerce Shipping & Tax shipping label migration has been stopped.', 'woocommerce-shipping' );
				},
			);
		} else {
			$tools['wcshipping_start_migrating_label_data'] = array(
				'name'     => __( 'Start migrating WooCommerce Shipping labels', 'woocommerce-shipping' ),
				'button'   => __( 'Start migration', 'woocommerce-shipping' ),
				'desc'     =>
					$start_label_migration_desc . ' ' . sprintf(
						// translators: %d is the number of labels. The word "label(s)" is wrapped in <strong> tags.
						_n(
							'There are currently <strong>%d label</strong> that can be migrated.',
							'There are currently <strong>%d labels</strong> that can be migrated.',
							$pending_count,
							'woocommerce-shipping'
						),
						$pending_count
					),
				'callback' => function () {
					$this->label_migrator->start();
					return __( 'The migration of WooCommerce Shipping & Tax shipping labels has started.', 'woocommerce-shipping' );
				},
			);
		}

		if ( $this->settings_migrator->needs_migration() ) {
			$tools['wcshipping_start_migrating_settings'] = array(
				'name'     => __( 'Start migrating WooCommerce Shipping settings', 'woocommerce-shipping' ),
				'button'   => __( 'Start migration', 'woocommerce-shipping' ),
				'desc'     =>
					__( 'This will migrate the settings set by WooCommerce Shipping & Tax and make them usable in WooCommerce Shipping.', 'woocommerce-shipping' ),
				'callback' => function () {
					$this->settings_migrator->migrate_all();
					return __( 'Your WooCommerce Shipping & Tax settings has been successfully migrated.', 'woocommerce-shipping' );
				},
			);
		} else {
			$tools['wcshipping_start_migrating_settings'] = array(
				'name'     => __( 'Start migrating WooCommerce Shipping settings', 'woocommerce-shipping' ),
				'button'   => __( 'Start migration', 'woocommerce-shipping' ),
				'desc'     =>
					__( 'This will migrate the settings set by WooCommerce Shipping & Tax and make them usable in WooCommerce Shipping.', 'woocommerce-shipping' ),
				'disabled' => true,
			);
		}

		return $tools;
	}

	/**
	 * Check if there are any migrations pending.
	 *
	 * @return bool
	 */
	public function needs_migration(): bool {
		return $this->label_migrator->needs_migration() || $this->settings_migrator->needs_migration();
	}

	/**
	 * Check if there are labels migrations pending.
	 *
	 * @return bool
	 */
	public function needs_labels_migration(): bool {
		return $this->label_migrator->needs_migration() && ! $this->label_migrator->migration_queued();
	}

	/**
	 * Check if there are settings migrations pending.
	 *
	 * @return bool
	 */
	public function needs_settings_migration(): bool {
		return $this->settings_migrator->needs_migration();
	}

	/**
	 * Get progress of the labels migration.
	 *
	 * @return array
	 */
	public function get_labels_migration_progress(): int {
		$total = $this->label_migrator->get_total_count();
		if ( 0 === $total ) {
			return 0;
		}
		$processed = $total - $this->label_migrator->get_total_pending_count();
		return (int) ( ( $processed * 100.0 ) / $total );
	}
}
