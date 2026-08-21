<?php namespace TierPricingTable\Settings\CustomOptions;

class TPTQuantityMeasurementField {
	
	const FIELD_TYPE = 'tpt_quantity_measurement_option';
	
	public function __construct() {
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( $this, 'render' ) );
		
		add_action( 'woocommerce_admin_settings_sanitize_option', function ( $value, $option, $rawValue ) {
			
			if ( self::FIELD_TYPE === $option['type'] ) {
				$value = is_array( $value ) ? $value : array();
				
				$_value['singular'] = isset( $value['singular'] ) ? sanitize_text_field( $value['singular'] ) : '';
				$_value['plural']   = isset( $value['plural'] ) ? sanitize_text_field( $value['plural'] ) : '';
				
				$value = $_value;
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
			$value['default'] = array( 'singular' => '', 'plural' => '' );
		}
		
		if ( ! isset( $value['value'] ) ) {
			$value['value'] = \WC_Admin_Settings::get_option( $value['id'], $value['default'] );
		}
		
		if ( ! isset( $value['desc'] ) ) {
			$value['desc'] = '';
		}
		
		$value['value'] = isset( $value['value'] ) ? (array) $value['value'] : array();
		
		$_value['singular'] = isset( $value['value']['singular'] ) ? sanitize_text_field( $value['value']['singular'] ) : '';
		$_value['plural']   = isset( $value['value']['plural'] ) ? sanitize_text_field( $value['value']['plural'] ) : '';
		
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
			</th>
			<td class="forminp forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>">
				<div>

					<div style="display: flex; width: 400px; justify-content: space-between ">
						<div>
							<label for="<?php echo esc_attr( $value['id'] ); ?>-singular">
								<?php esc_html_e( 'Singular', 'tier-pricing-table' ); ?>:
							</label>
							<br>
							<input type="text" style="width: 190px;"
								   value="<?php echo esc_attr( $_value['singular'] ); ?>"
								   name="<?php echo esc_attr( $value['id'] ); ?>[singular]"
								   id="<?php echo esc_attr( $value['id'] ); ?>-singular"
								   placeholder="<?php esc_attr_e( 'e.g., piece', 'tier-pricing-table' ); ?>">
						</div>

						<div>
							<label for="<?php echo esc_attr( $value['id'] ); ?>-plural">
								<?php esc_html_e( 'Plural', 'tier-pricing-table' ); ?>:
							</label>
							<br>
							<input type="text" style="width: 190px;"
								   value="<?php echo esc_attr( $_value['plural'] ); ?>"
								   name="<?php echo esc_attr( $value['id'] ); ?>[plural]"
								   id="<?php echo esc_attr( $value['id'] ); ?>-plural"
								   placeholder="<?php esc_attr_e( 'e.g., pieces', 'tier-pricing-table' ); ?>">
						</div>
					</div>
				</div>
				<p class="description">
					<?php
						echo wp_kses_post( $value['desc'] ); // audit.php.wp.security.xss.shortcode-attr ignore
					?>
				</p>
			</td>
		</tr>
		<?php
	}
}