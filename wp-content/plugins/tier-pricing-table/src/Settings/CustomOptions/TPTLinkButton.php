<?php namespace TierPricingTable\Settings\CustomOptions;

class TPTLinkButton {

	const FIELD_TYPE = 'tpt_link_button';

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

		if ( ! isset( $value['title'] ) ) {
			$value['title'] = isset( $value['name'] ) ? $value['name'] : '';
		}

		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
			</th>
			<td class="forminp forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>">
				<a href="<?php echo esc_attr( $value['button_link'] ); ?>"
				   class="<?php echo esc_attr( $value['button_class'] ); ?>">
					<?php echo esc_html( $value['button_text'] ); ?>
				</a>
			</td>
		</tr>
		<?php
	}
}