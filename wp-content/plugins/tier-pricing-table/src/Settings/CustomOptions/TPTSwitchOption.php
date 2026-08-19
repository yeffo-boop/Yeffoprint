<?php namespace TierPricingTable\Settings\CustomOptions;

class TPTSwitchOption {

	const FIELD_TYPE = 'tpt_switch_option';

	public function __construct() {
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( $this, 'render' ) );

		add_action( 'woocommerce_admin_settings_sanitize_option', function ( $value, $option, $rawValue ) {

			if ( self::FIELD_TYPE === $option['type'] ) {
				$value = in_array( $value, array( 1, 'yes' ) ) ? 'yes' : 'no';
			}

			return $value;
		}, 10, 3 );
	}

	public function render( $value ) {
		if ( ! isset( $value['id'] ) ) {
			$value['id'] = '';
		}

		if ( ! isset( $value['custom_attributes'] ) ) {
			$value['custom_attributes'] = array();
		} else {
			$value['custom_attributes'] = array_keys( $value['custom_attributes'] );
		}

		if ( ! isset( $value['title'] ) ) {
			$value['title'] = isset( $value['name'] ) ? $value['name'] : '';
		}
		if ( ! isset( $value['default'] ) ) {
			$value['default'] = '';
		}

		if ( ! isset( $value['value'] ) ) {
			$value['value'] = \WC_Admin_Settings::get_option( $value['id'], $value['default'] );
		}

		if ( ! isset( $value['on_label'] ) ) {
			$value['on_label'] = __( 'On', 'role-and-customer-based-pricing-for-woocommerce' );
		}

		if ( ! isset( $value['off_label'] ) ) {
			$value['off_label'] = __( 'Off', 'role-and-customer-based-pricing-for-woocommerce' );
		}
		if ( ! isset( $value['desc'] ) ) {
			$value['desc'] = '';
		}

		$option_value = $value['value'];

		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
			</th>
			<td class="forminp forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>">
				<div>
					<input
						<?php echo esc_attr(implode( ' ', $value['custom_attributes'] )); ?>
						name="<?php echo esc_attr( $value['id'] ); ?>"
						id="<?php echo esc_attr( $value['id'] ); ?>"
						type="checkbox"
						value="1"
						<?php checked( $option_value, 'yes' ); ?>
						class="tpt-toggle-switch"
					/>
					<label for="<?php echo esc_attr( $value['id'] ); ?>">
						<span data-tpt-toggle-switch-on><?php echo esc_attr( $value['on_label'] ); ?></span>
						<span data-tpt-toggle-switch-off><?php echo esc_attr( $value['off_label'] ); ?></span>
					</label>
				</div>
				<p class="description"><?php echo wp_kses_post( $value['desc'] ); ?></p>

				<?php if ( isset( $value['extended_description'] ) ) : ?>
					<div class="tpt-toggle-extended-description">
						<?php echo wp_kses_post( $value['extended_description'] ); ?>
					</div>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}