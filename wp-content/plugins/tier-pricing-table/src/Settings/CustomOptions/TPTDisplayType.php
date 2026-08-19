<?php namespace TierPricingTable\Settings\CustomOptions;

class TPTDisplayType {
	
	const FIELD_TYPE = 'tpt_display_type';
	
	public function __construct() {
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( $this, 'render' ) );
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
		
		if ( ! isset( $value['value'] ) ) {
			$value['value'] = \WC_Admin_Settings::get_option( $value['id'], $value['default'] );
		}
		
		$option_value = $value['value'];
		
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
			</th>
			<td class="forminp forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>">
				<fieldset>
					<div style="display: flex">
						<?php foreach ( $value['options'] as $key => $label ) : ?>
							<div class="tpt-display-template-options">
								<input type="radio"
									   name="<?php echo esc_attr( $value['id'] ); ?>"
									   value="<?php echo esc_attr( $key ); ?>"
									   id="<?php echo esc_attr( $value['id'] . '-' . $key ); ?>"
									   class="tpt-display-template-option"
									<?php checked( $key, $option_value ); ?>
								>

								<label class="tpt-display-template-label"
									   for="<?php echo esc_attr( $value['id'] . '-' . $key ); ?>">
									<?php echo esc_attr( $label ); ?>
								</label>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="description">
						<?php echo esc_html( $value['desc'] ); ?>
					</p>
					<?php if ( isset( $value['extended_description'] ) ) : ?>
						<div class="tpt-toggle-extended-description">
							<?php
								echo wp_kses_post( $value['extended_description'] ); // audit.php.wp.security.xss.shortcode-attr ignore
							?>
						</div>
					<?php endif; ?>
				</fieldset>
			</td>
		</tr>
		<?php
	}
}