<?php
/**
 * Shipping labels report ability definition.
 *
 * @package Automattic\WCShipping\Abilities
 */

namespace Automattic\WCShipping\Abilities;

use Automattic\WCShipping\Analytics\LabelsService;
use Automattic\WCShipping\Connect\WC_Connect_Functions;
use Automattic\WooCommerce\Abilities\AbilityDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only ability for querying purchased WooCommerce Shipping labels.
 */
class ShippingLabelsReport implements AbilityDefinition {

	public const ABILITY_ID = 'woocommerce-shipping/get-shipping-labels-report';

	/**
	 * Get the ability name.
	 *
	 * @return string
	 */
	public static function get_name(): string {
		return self::ABILITY_ID;
	}

	/**
	 * Get the ability registration arguments.
	 *
	 * @return array
	 */
	public static function get_registration_args(): array {
		return array(
			'label'               => __( 'Get shipping labels report', 'woocommerce-shipping' ),
			'description'         => __( 'Retrieve a curated, read-only report of purchased WooCommerce Shipping labels for a date range.', 'woocommerce-shipping' ),
			'category'            => 'woocommerce',
			'input_schema'        => self::get_input_schema(),
			'output_schema'       => self::get_output_schema(),
			'execute_callback'    => array( __CLASS__, 'execute' ),
			'permission_callback' => array( __CLASS__, 'can_read_labels_report' ),
			'meta'                => array(
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
					'type'   => 'tool',
				),
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function execute( array $input ) {
		$validation_error = self::validate_date_range( $input );
		if ( is_wp_error( $validation_error ) ) {
			return $validation_error;
		}

		$page     = isset( $input['page'] ) ? absint( $input['page'] ) : 1;
		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 20;
		$offset   = ( $page - 1 ) * $per_page;

		$report = ( new LabelsService() )->get_labels_for_period(
			array(
				// LabelsService is shared with the REST report endpoint and decodes date query values internally.
				'before'   => rawurlencode( $input['before'] ),
				'after'    => rawurlencode( $input['after'] ),
				'offset'   => $offset,
				'per_page' => $per_page,
			),
			array(
				'created_date',
				'order_id',
				'rate',
				'service_name',
				'refund',
			)
		);

		return array(
			'labels'      => array_map( array( __CLASS__, 'format_label' ), $report['rows'] ),
			'total_pages' => (int) $report['meta']['pages'],
			'page'        => $page,
			'per_page'    => $per_page,
			'totals'      => array(
				'count'    => (int) $report['meta']['total_count'],
				'cost'     => wc_format_decimal( $report['meta']['total_cost'], wc_get_price_decimals() ),
				'refunds'  => (int) $report['meta']['total_refunds'],
				'currency' => get_woocommerce_currency(),
			),
		);
	}

	/**
	 * Check whether the current user can read WooCommerce Shipping labels.
	 *
	 * @param mixed $input Ability input.
	 * @return bool
	 */
	public static function can_read_labels_report( $input = array() ): bool {
		return WC_Connect_Functions::user_can_manage_labels();
	}

	/**
	 * Get the input schema.
	 *
	 * @return array
	 */
	private static function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'after'    => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => __( 'Inclusive start date/time for labels to include.', 'woocommerce-shipping' ),
				),
				'before'   => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => __( 'Inclusive end date/time for labels to include.', 'woocommerce-shipping' ),
				),
				'page'     => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'default' => 20,
					'minimum' => 1,
					'maximum' => 100,
				),
			),
			'required'             => array( 'after', 'before' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Get the output schema.
	 *
	 * @return array
	 */
	private static function get_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'labels'      => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'order_id'      => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'created_at'    => array(
								'type'   => 'string',
								'format' => 'date-time',
							),
							'service_name'  => array( 'type' => 'string' ),
							'rate'          => array(
								'type'        => 'string',
								'description' => __( 'Label cost as a decimal string without a currency symbol.', 'woocommerce-shipping' ),
							),
							'refund_status' => array(
								'type'        => 'string',
								'description' => __( 'Refund state for the label; an empty string means no refund is recorded.', 'woocommerce-shipping' ),
								'enum'        => array( '', 'requested', 'complete', 'rejected' ),
							),
						),
						'additionalProperties' => false,
					),
				),
				'total_pages' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'page'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'per_page'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'totals'      => array(
					'type'                 => 'object',
					'description'          => __( 'Aggregate totals for the full filtered date range, not only the current page.', 'woocommerce-shipping' ),
					'properties'           => array(
						'count'    => array(
							'type'        => 'integer',
							'description' => __( 'Total labels in the full filtered date range.', 'woocommerce-shipping' ),
							'minimum'     => 0,
						),
						'cost'     => array(
							'type'        => 'string',
							'description' => __( 'Total label cost across the full filtered date range as a decimal string without a currency symbol.', 'woocommerce-shipping' ),
						),
						'refunds'  => array(
							'type'        => 'integer',
							'description' => __( 'Number of labels in the full filtered date range with a completed refund.', 'woocommerce-shipping' ),
							'minimum'     => 0,
						),
						'currency' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Validate semantic date range constraints not covered by JSON schema.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	private static function validate_date_range( array $input ) {
		$after_timestamp  = strtotime( $input['after'] );
		$before_timestamp = strtotime( $input['before'] );

		if ( false === $after_timestamp || false === $before_timestamp ) {
			return new \WP_Error(
				'woocommerce_shipping_invalid_date_range',
				__( 'The after and before values must be valid date-time strings.', 'woocommerce-shipping' ),
				array( 'status' => 400 )
			);
		}

		if ( $after_timestamp > $before_timestamp ) {
			return new \WP_Error(
				'woocommerce_shipping_invalid_date_range',
				__( 'The after date must be earlier than or equal to the before date.', 'woocommerce-shipping' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Format a label row for the ability output contract.
	 *
	 * @param array $label Raw label row from LabelsService.
	 * @return array
	 */
	private static function format_label( array $label ): array {
		$created_timestamp = isset( $label['created_date'] ) ? (int) floor( (int) $label['created_date'] / 1000 ) : 0;
		$refund            = isset( $label['refund'] ) && is_array( $label['refund'] ) ? $label['refund'] : array();

		return array(
			'order_id'      => (int) $label['order_id'],
			'created_at'    => gmdate( 'c', $created_timestamp ),
			'service_name'  => isset( $label['service_name'] ) ? sanitize_text_field( (string) $label['service_name'] ) : '',
			'rate'          => wc_format_decimal( $label['rate'] ?? 0, wc_get_price_decimals() ),
			'refund_status' => self::normalize_refund_status( $refund['status'] ?? '' ),
		);
	}

	/**
	 * Normalize refund statuses to the ability's stable enum values.
	 *
	 * @param mixed $status Refund status from LabelsService.
	 * @return string
	 */
	private static function normalize_refund_status( $status ): string {
		$status = is_scalar( $status ) ? strtolower( trim( (string) $status ) ) : '';

		if ( '' === $status ) {
			return '';
		}

		if ( in_array( $status, array( 'complete', 'rejected' ), true ) ) {
			return $status;
		}

		return 'requested';
	}
}
