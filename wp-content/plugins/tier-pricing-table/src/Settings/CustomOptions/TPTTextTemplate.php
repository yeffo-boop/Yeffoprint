<?php namespace TierPricingTable\Settings\CustomOptions;

use TierPricingTable\Core\ServiceContainerTrait;
use TierPricingTable\Settings\Settings;

class TPTTextTemplate {

	const FIELD_TYPE = 'tpt_text_template';

	/**
	 * Editor id
	 *
	 * @var mixed|string
	 */
	private $editorId;

	use ServiceContainerTrait;

	public function __construct() {
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( $this, 'render' ) );

		add_action( 'admin_enqueue_scripts', function () {
			$assetName = Settings::SETTINGS_PAGE . '_script';

			wp_add_inline_script( $assetName,
					'const TPTAvailableCustomButtons = ' . wp_json_encode( $this->getAvailableVariables() ) . ';',
					'before' );
		}, 999 );

		add_filter( 'mce_external_plugins', function ( $plugins, $editor ) {
			if ( $this->editorId === $editor ) {
				$plugins['tiered-pricing-custom-mce-buttons'] = $this->getContainer()->getFileManager()->locateJSAsset( 'admin/mce' );
			}

			return $plugins;
		}, 10, 2 );

		add_filter( 'mce_buttons', function ( $buttons ) {

			$buttons = array_merge( $buttons, array_keys( $this->getAvailableVariables() ) );

			return $buttons;
		} );

		add_action( 'woocommerce_admin_settings_sanitize_option', function ( $value, $option, $rawValue ) {

			if ( self::FIELD_TYPE === $option['type'] ) {
				return wp_kses_post( $rawValue );
			}

			return $value;
		}, 10, 3 );

	}

	public function render( $value ) {
		if ( ! isset( $value['id'] ) ) {
			$value['id'] = '';
		}
		if ( ! isset( $value['title'] ) ) {
			$value['title'] = isset( $value['name'] ) ? $value['name'] : '';
		}
		if ( ! isset( $value['default'] ) ) {
			$value['default'] = '';
		}
		if ( ! isset( $value['desc'] ) ) {
			$value['desc'] = '';
		}
		if ( ! isset( $value['desc_tip'] ) ) {
			$value['desc_tip'] = false;
		}
		if ( ! isset( $value['placeholder'] ) ) {
			$value['placeholder'] = '';
		}
		if ( ! isset( $value['value'] ) ) {
			$value['value'] = \WC_Admin_Settings::get_option( $value['id'], $value['default'] );
		}

		$option_value = $value['value'];

		$this->editorId = $value['id'];

		$value['placeholders'] = is_array( $value['placeholders'] ) ? $value['placeholders'] : array();
		?>
		<style>
			#wp-<?php echo esc_html($value['id']); ?>-wrap .wp-editor-tools {
				display: none;
			}

			#wp-<?php echo esc_html($value['id']); ?>-wrap {
				max-width: 500px;
			}
		</style>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
			</th>
			<td class="forminp forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>">
				<?php
					wp_editor( $option_value, $value['id'], array(
							'wpautop'       => true,
							'media_buttons' => false,

							'textarea_name'    => $value['id'],
							'editor_height'    => 30,
							'tabindex'         => null,
							'editor_class'     => 'tpt-message-template-mce',
							'tinymce'          => array(
									'resize'   => 'vertical',
									'menubar'  => false,
									'wpautop'  => true,
									'toolbar2' => '',
									'toolbar1' => implode( ',', array_merge( array(
											'bold',
											'italic',
											'strikethrough',
											'link',

											'spellchecker',
									), $value['placeholders'] ) ),
							),
							'quicktags'        => array(
									'id'      => $value['id'],
									'buttons' => 'strong,em,del',
							),
							'drag_drop_upload' => false,
					) );
				?>

				<?php if ( $value['desc'] ) : ?>
					<p class="description"><?php echo wp_kses_post( $value['desc'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	protected function getAvailableVariables() {
		return apply_filters( 'tiered_pricing_table/text_template_variables', array(
				'tp_quantity' => array(
						'name'        => __( 'Quantity', 'tier-pricing-table' ),
						'description' => __( '{tp_quantity} - range or a static quantity of the current pricing tier.',
								'tier-pricing-table' ),
						'variableKey' => '{tp_quantity}',
				),

				'tp_discount' => array(
						'name'        => __( 'Percentage discount', 'tier-pricing-table' ),
						'description' => __( '{tp_discount} - current tier`s percentage discount.',
								'tier-pricing-table' ),
						'variableKey' => '{tp_discount}',
				),

				'tp_rounded_discount' => array(
						'name'        => __( 'Rounded percentage discount', 'tier-pricing-table' ),
						'description' => __( '{tp_rounded_discount} - current tier`s rounded percentage discount.',
								'tier-pricing-table' ),
						'variableKey' => '{tp_rounded_discount}',
				),

				'tp_required_quantity' => array(
						'name'        => __( 'Required quantity', 'tier-pricing-table' ),
						'description' => __( '{tp_required_quantity} - required quantity to add a next tiered pricing.',
								'tier-pricing-table' ),
						'variableKey' => '{tp_required_quantity}',
				),

				'tp_next_price' => array(
						'name'        => __( 'Next price', 'tier-pricing-table' ),
						'description' => __( '{tp_next_price} - next tiered pricing price user can get if they add required quantity.',
								'tier-pricing-table' ),
						'variableKey' => '{tp_next_price}',
				),

				'tp_next_discount' => array(
						'name'        => __( 'Next discount', 'tier-pricing-table' ),
						'description' => __( '{tp_next_discount} - next discount if they add required quantity.',
								'tier-pricing-table' ),
						'variableKey' => '{tp_next_discount}',
				),

				'tp_actual_discount'        => array(
						'name'        => __( 'Next actual discount', 'tier-pricing-table' ),
						'description' => __( '{tp_actual_discount} - discount based on the original product price.',
								'tier-pricing-table' ),
						'variableKey' => '{tp_actual_discount}',
				),
				'tp_ys_price'               => array(
						'name'        => __( 'Save price', 'tier-pricing-table' ),
						'description' => __( '{tp_ys_price} - difference between regular and current price.',
								'tier-pricing-table' ),
						'variableKey' => '{tp_ys_price}',
				),
				'tp_ys_total_price'         => array(
						'name'        => __( 'Total save price', 'tier-pricing-table' ),
						'description' => __( '{tp_ys_total_price} - difference between regular and current price multiplied by quantity.',
								'tier-pricing-table' ),
						'variableKey' => '{tp_ys_total_price}',
				),
				'tp_ys_percentage_discount' => array(
						'name'        => __( 'Percentage discount', 'tier-pricing-table' ),
						'variableKey' => '{tp_ys_percentage_discount}',
				),
				'tp_price'                  => array(
						'name'        => __( 'Price', 'tier-pricing-table' ),
						'description' => __( '{tp_price} - Tier price for one piece', 'tier-pricing-table' ),
						'variableKey' => '{tp_price}',
				),
				'tp_base_unit_name'         => array(
						'name'        => __( 'Unit label', 'tier-pricing-table' ),
						'description' => __( 'It will use Unit label from products if you set it.',
								'tier-pricing-table' ),
						'variableKey' => '{tp_base_unit_name}',
				),

		) );
	}
}
